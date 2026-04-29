<?php

namespace App\Controller;

use App\Entity\Communication;
use App\Form\Admin\EmailRecipientFilterType;
use App\Repository\AlumniRepository;
use App\Repository\CommunicationRepository;
use App\Repository\QrRegistrationBatchRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/email')]
#[IsGranted('ROLE_ADMIN')]
class AdminEmailController extends AbstractController
{
    #[Route('', name: 'admin_email_index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        AlumniRepository $alumniRepository,
        CommunicationRepository $communicationRepository,
        QrRegistrationBatchRepository $batchRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $yearsFromData = $alumniRepository->createQueryBuilder('a')
            ->select('DISTINCT a.yearGraduated')
            ->where('a.yearGraduated IS NOT NULL')
            ->andWhere('a.deletedAt IS NULL')
            ->orderBy('a.yearGraduated', 'DESC')
            ->getQuery()
            ->getSingleColumnResult();

        $currentYear = (int) date('Y');
        $baselineYears = range(1980, $currentYear);
        $yearOptions = array_values(array_unique(array_merge(
            $baselineYears,
            array_map(static fn ($year): int => (int) $year, $yearsFromData)
        )));
        rsort($yearOptions);

        $filterForm = $this->createForm(EmailRecipientFilterType::class, null, [
            'years' => $yearOptions,
        ]);
        $filterForm->handleRequest($request);

        $selectedYear = null;
        if ($filterForm->isSubmitted() && $filterForm->isValid()) {
            $selectedYear = $filterForm->get('yearGraduated')->getData();
        } elseif ($request->query->has('email_recipient_filter')) {
            $selectedYear = $request->query->all('email_recipient_filter')['yearGraduated'] ?? null;
            $selectedYear = is_numeric($selectedYear) ? (int) $selectedYear : null;
        }

        $recipientsQb = $alumniRepository->createQueryBuilder('a')
            ->where('a.deletedAt IS NULL')
            ->andWhere('a.emailAddress IS NOT NULL')
            ->orderBy('a.lastName', 'ASC')
            ->addOrderBy('a.firstName', 'ASC');

        if ($selectedYear !== null) {
            // Use Doctrine QueryBuilder to fetch only matching graduation year.
            $recipientsQb->andWhere('a.yearGraduated = :selectedYear')
                ->setParameter('selectedYear', (int) $selectedYear);
        }

        $recipientCount = (int) (clone $recipientsQb)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $recipients = $recipientsQb
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        $registrationLink = $selectedYear !== null
            ? $this->generateUrl('app_qr_registration', ['batchYear' => $selectedYear], UrlGeneratorInterface::ABSOLUTE_URL)
            : $this->generateUrl('app_register', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $registrationLinkReady = $selectedYear === null || $batchRepository->findOneOpenByBatchYear((int) $selectedYear) !== null;
        $registrationLinkLabel = $selectedYear !== null
            ? sprintf('Batch %d QR registration link', $selectedYear)
            : 'General registration link';
        $registrationLinkWarning = $selectedYear !== null && !$registrationLinkReady
            ? sprintf('Batch %d does not have an active QR registration page yet. Create or reopen the batch in QR Registration before sharing this draft.', $selectedYear)
            : null;

        if ($request->isMethod('POST') && $request->request->has('subject')) {
            if (!$this->isCsrfTokenValid('email_compose', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid request token. Please try again.');
                return $this->redirectToRoute('admin_email_index');
            }

            $subject = trim((string) $request->request->get('subject'));
            $message = trim((string) $request->request->get('message'));

            if ($subject === '' || $message === '') {
                $this->addFlash('error', 'Subject and message are required.');
                return $this->redirectToRoute('admin_email_index', [
                    'email_recipient_filter' => ['yearGraduated' => $selectedYear],
                ]);
            }

            $communication = (new Communication())
                ->setSubject($subject)
                ->setMessage($message)
                ->setChannel('email')
                ->setRecipientCount($recipientCount)
                ->setTargetYear($selectedYear !== null ? (string) $selectedYear : 'All years')
                ->setSentAt(new \DateTime())
                ->setSentBy(method_exists($this->getUser(), 'getEmail') ? $this->getUser()?->getEmail() : null);

            $entityManager->persist($communication);
            $entityManager->flush();

            $this->addFlash('success', 'Draft saved to the outbox. No email was sent.');

            return $this->redirectToRoute('admin_email_index', [
                'email_recipient_filter' => ['yearGraduated' => $selectedYear],
            ]);
        }

        $communications = $communicationRepository->createQueryBuilder('c')
            ->orderBy('c.sentAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/email/index.html.twig', [
            'communications' => $communications,
            'filterForm' => $filterForm->createView(),
            'selectedYear' => $selectedYear,
            'recipientCount' => $recipientCount,
            'recipients' => $recipients,
            'registrationLink' => $registrationLink,
            'registrationLinkReady' => $registrationLinkReady,
            'registrationLinkLabel' => $registrationLinkLabel,
            'registrationLinkWarning' => $registrationLinkWarning,
        ]);
    }
}
