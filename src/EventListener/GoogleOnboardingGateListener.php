<?php

namespace App\EventListener;

use App\Entity\User;
use App\Service\GoogleOnboardingService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsEventListener(event: KernelEvents::REQUEST)]
class GoogleOnboardingGateListener
{
    public function __construct(
        private Security $security,
        private GoogleOnboardingService $googleOnboardingService,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');

        if ($route === '' || str_starts_with($route, '_')) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User || !$this->googleOnboardingService->needsOnboarding($user)) {
            return;
        }

        if (in_array($route, ['app_google_onboarding', 'app_logout'], true)) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_google_onboarding')));
    }
}