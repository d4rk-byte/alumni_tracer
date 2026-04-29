<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\GoogleOnboardingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoogleAuthController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly GoogleOnboardingService $googleOnboardingService,
        private readonly Security $security,
        #[Autowire('%env(string:GOOGLE_CLIENT_ID)%')]
        private readonly string $googleClientId,
        #[Autowire('%env(string:GOOGLE_CLIENT_SECRET)%')]
        private readonly string $googleClientSecret,
    ) {
    }

    #[Route('/connect/google', name: 'app_google_start', methods: ['GET'])]
    public function start(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        if (!$this->isGoogleConfigured()) {
            $this->addFlash('error', 'Google authentication is not configured yet. Please contact an administrator.');
            return $this->redirectToRoute('app_login');
        }

        $state = bin2hex(random_bytes(32));
        $request->getSession()->set('google_oauth_state', $state);

        $redirectUri = $this->generateUrl('app_google_check', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $query = http_build_query([
            'client_id' => $this->googleClientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
            'access_type' => 'online',
        ], '', '&', \PHP_QUERY_RFC3986);

        return $this->redirect('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    #[Route('/connect/google/check', name: 'app_google_check', methods: ['GET'])]
    public function check(Request $request): Response
    {
        if (!$this->isGoogleConfigured()) {
            $this->addFlash('error', 'Google authentication is not configured yet.');
            return $this->redirectToRoute('app_login');
        }

        $session = $request->getSession();
        $expectedState = (string) $session->get('google_oauth_state', '');
        $session->remove('google_oauth_state');

        $receivedState = (string) $request->query->get('state', '');
        if ($expectedState === '' || !hash_equals($expectedState, $receivedState)) {
            $this->addFlash('error', 'Invalid Google OAuth state. Please try again.');
            return $this->redirectToRoute('app_login');
        }

        if ($request->query->has('error')) {
            $errorText = (string) $request->query->get('error_description', (string) $request->query->get('error'));
            $this->addFlash('error', 'Google sign-in failed: '.$errorText);
            return $this->redirectToRoute('app_login');
        }

        $code = (string) $request->query->get('code', '');
        if ($code === '') {
            $this->addFlash('error', 'Missing Google authorization code. Please try again.');
            return $this->redirectToRoute('app_login');
        }

        $redirectUri = $this->generateUrl('app_google_check', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $accessToken = $this->exchangeCodeForAccessToken($code, $redirectUri);

        if ($accessToken === null) {
            $this->addFlash('error', 'Unable to authenticate with Google right now. Please try again later.');
            return $this->redirectToRoute('app_login');
        }

        $profile = $this->fetchGoogleProfile($accessToken);
        if ($profile === null) {
            $this->addFlash('error', 'Unable to fetch your Google profile. Please try again later.');
            return $this->redirectToRoute('app_login');
        }

        $email = strtolower(trim((string) ($profile['email'] ?? '')));
        $emailVerified = (bool) ($profile['email_verified'] ?? false);

        if ($email === '' || !$emailVerified) {
            $this->addFlash('error', 'Your Google account must have a verified email address.');
            return $this->redirectToRoute('app_login');
        }

        $googleSubject = trim((string) ($profile['sub'] ?? ''));
        $user = null;

        if ($googleSubject !== '') {
            $user = $this->userRepository->findOneBy(['googleSubject' => $googleSubject]);
        }

        if (!$user instanceof User) {
            $user = $this->userRepository->findOneBy(['email' => $email]);
        }

        if (!$user instanceof User) {
            [$firstName, $lastName] = $this->resolveNames($profile, $email);

            $user = new User();
            $user->setEmail($email);
            $user->setFirstName($firstName);
            $user->setLastName($lastName);
            $user->setRoles([User::ROLE_ALUMNI]);
            $user->setAccountStatus('active');
            $user->setSchoolId(null);
            $user->setGoogleSubject($googleSubject !== '' ? $googleSubject : null);
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
            $user->setRequiresOnboarding(true);
            $user->setDpaConsent(true);
            $user->setDpaConsentDate(new \DateTime());
            $user->setPassword(
                $this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32)))
            );

            $this->entityManager->persist($user);
        } else {
            if ($googleSubject !== '' && $user->getGoogleSubject() !== $googleSubject) {
                $user->setGoogleSubject($googleSubject);
            }

            if ($user->getAccountStatus() !== 'active') {
                $user->setAccountStatus('active');
            }

            if ($user->getEmailVerifiedAt() === null) {
                $user->setEmailVerifiedAt(new \DateTimeImmutable());
            }

            if ($user->isDpaConsent() !== true) {
                $user->setDpaConsent(true);
                $user->setDpaConsentDate(new \DateTime());
            }
        }

        $needsOnboarding = $this->googleOnboardingService->needsOnboarding($user);
        $user->setRequiresOnboarding($needsOnboarding);

        if (!$needsOnboarding && $user->getProfileCompletedAt() === null) {
            $user->setProfileCompletedAt(new \DateTimeImmutable());
        }

        $this->entityManager->flush();

        try {
            $response = $this->security->login($user, 'form_login', 'main');

            if ($needsOnboarding) {
                return $this->redirectToRoute('app_google_onboarding');
            }

            if ($response instanceof Response) {
                return $response;
            }
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage() !== '' ? $e->getMessage() : 'Unable to sign in with Google.');
            return $this->redirectToRoute('app_login');
        }

        if ($needsOnboarding) {
            return $this->redirectToRoute('app_google_onboarding');
        }

        return $this->redirectToRoute('app_home');
    }

    private function isGoogleConfigured(): bool
    {
        return trim($this->googleClientId) !== '' && trim($this->googleClientSecret) !== '';
    }

    private function exchangeCodeForAccessToken(string $code, string $redirectUri): ?string
    {
        try {
            $response = $this->httpClient->request('POST', 'https://oauth2.googleapis.com/token', [
                'body' => [
                    'client_id' => $this->googleClientId,
                    'client_secret' => $this->googleClientSecret,
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $redirectUri,
                ],
            ]);

            $data = $response->toArray(false);
            $token = (string) ($data['access_token'] ?? '');

            return $token !== '' ? $token : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchGoogleProfile(string $accessToken): ?array
    {
        try {
            $response = $this->httpClient->request('GET', 'https://openidconnect.googleapis.com/v1/userinfo', [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                ],
            ]);

            return $response->toArray(false);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return array{0: string, 1: string}
     */
    private function resolveNames(array $profile, string $email): array
    {
        $firstName = trim((string) ($profile['given_name'] ?? ''));
        $lastName = trim((string) ($profile['family_name'] ?? ''));

        if (($firstName === '' || $lastName === '') && isset($profile['name'])) {
            $fullName = preg_replace('/\s+/', ' ', trim((string) $profile['name'])) ?: '';
            if ($fullName !== '') {
                $parts = explode(' ', $fullName);
                if ($firstName === '') {
                    $firstName = array_shift($parts) ?: '';
                }
                if ($lastName === '') {
                    $lastName = count($parts) > 0 ? implode(' ', $parts) : 'User';
                }
            }
        }

        if ($firstName === '') {
            $localPart = explode('@', $email)[0] ?? 'Google';
            $normalized = trim((string) preg_replace('/[^a-z0-9]+/i', ' ', $localPart));
            $firstName = $normalized !== '' ? ucfirst($normalized) : 'Google';
        }

        if ($lastName === '') {
            $lastName = 'User';
        }

        return [substr($firstName, 0, 255), substr($lastName, 0, 255)];
    }
}
