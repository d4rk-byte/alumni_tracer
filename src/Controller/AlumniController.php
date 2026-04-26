<?php

namespace App\Controller;

use App\Entity\Alumni;
use App\Entity\User;
use App\Form\AlumniType;
use App\Form\AlumniVerificationType;
use App\Repository\AlumniRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/alumni')]
#[IsGranted('ROLE_USER')]
class AlumniController extends AbstractController
{
    #[Route('/', name: 'alumni_index', methods: ['GET', 'POST'])]
    public function index(Request $request, AlumniRepository $repo, EntityManagerInterface $em): Response
    {
        if (!$this->isGranted('ROLE_STAFF')) {
            throw $this->createAccessDeniedException('Only staff can access Alumni Verification Portal.');
        }

        $isStaffPortal = $this->isGranted('ROLE_STAFF') && !$this->isGranted('ROLE_ADMIN');
        $canCreateAlumni = $this->isGranted('ROLE_ADMIN');

        // ── Handle Add Alumni form ──
        $alumni = new Alumni();
        $form = null;

        if ($canCreateAlumni) {
            $form = $this->createForm(AlumniType::class, $alumni);
            $form->handleRequest($request);
        }

        if ($form !== null && $form->isSubmitted() && $form->isValid()) {
            $em->persist($alumni);
            $em->flush();
            $this->addFlash('success', 'Alumni record created successfully.');

            return $this->redirectToRoute('alumni_index');
        }

        // ── Alumni directory listing ──
        $search   = $request->query->get('q', '');
        $course   = $request->query->get('course', '');
        $year     = $request->query->get('year', '');
        $campus   = $request->query->get('campus', '');
        $status   = $request->query->get('status', '');
        $province = $request->query->get('province', '');
        $registrationState = $request->query->get('registration_state', '');

        $qb = $repo->searchByBatchCampusCourse(
            $year !== '' ? (int) $year : null,
            $campus !== '' ? $campus : null,
            $course !== '' ? $course : null
        );
        $qb->leftJoin('a.user', 'u');

        if ($search !== '') {
            if ($isStaffPortal) {
                $qb->andWhere('a.lastName LIKE :q OR a.studentNumber LIKE :q')
                    ->setParameter('q', '%' . $search . '%');
            } else {
                $qb->andWhere('a.firstName LIKE :q OR a.lastName LIKE :q OR a.studentNumber LIKE :q OR a.emailAddress LIKE :q')
                    ->setParameter('q', '%' . $search . '%');
            }
        }
        if ($status !== '') {
            $qb->andWhere('a.employmentStatus = :status')->setParameter('status', $status);
        }
        if ($province !== '') {
            $qb->andWhere('a.province LIKE :province')->setParameter('province', '%' . $province . '%');
        }

        if ($isStaffPortal) {
            $qb->andWhere('u.id IS NOT NULL')
                ->andWhere('(u.roles LIKE :roleUser OR u.roles LIKE :roleAlumniLegacy)')
                ->andWhere('u.roles NOT LIKE :staffRole')
                ->andWhere('u.roles NOT LIKE :adminRole')
                ->setParameter('roleUser', '%ROLE_USER%')
                ->setParameter('roleAlumniLegacy', '%' . User::ROLE_ALUMNI . '%')
                ->setParameter('staffRole', '%ROLE_STAFF%')
                ->setParameter('adminRole', '%ROLE_ADMIN%');
        }

        $registrationStateCounts = $this->countRegistrationStates($qb, $repo);

        if ($registrationState !== '') {
            $repo->applyRegistrationStateFilter($qb, 'u', $registrationState);
        }

        $qb->orderBy('a.lastName', 'ASC');

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 20;
        $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        $paginator = new Paginator($qb);
        $totalItems = count($paginator);
        $totalPages = (int) ceil($totalItems / $limit);
        $alumnis = $paginator;

        // Get distinct values for filter dropdowns
        $courses = $repo->createQueryBuilder('a')
            ->select('DISTINCT a.course')->where('a.course IS NOT NULL AND a.deletedAt IS NULL')
            ->orderBy('a.course', 'ASC')->getQuery()->getSingleColumnResult();

        $years = $repo->createQueryBuilder('a')
            ->select('DISTINCT a.yearGraduated')->where('a.yearGraduated IS NOT NULL AND a.deletedAt IS NULL')
            ->orderBy('a.yearGraduated', 'DESC')->getQuery()->getSingleColumnResult();

        $provinces = $repo->createQueryBuilder('a')
            ->select('DISTINCT a.province')->where('a.province IS NOT NULL AND a.province != :empty AND a.deletedAt IS NULL')
            ->setParameter('empty', '')->orderBy('a.province', 'ASC')->getQuery()->getSingleColumnResult();

        return $this->render('alumni/index.html.twig', [
            'alumnis'   => $alumnis,
            'search'    => $search,
            'courses'   => $courses,
            'years'     => $years,
            'provinces' => $provinces,
            'filter_course'   => $course,
            'filter_year'     => $year,
            'filter_campus'   => $campus,
            'filter_status'   => $status,
            'filter_province' => $province,
            'filter_registration_state' => $registrationState,
            'registrationStateCounts' => $registrationStateCounts,
            'currentPage' => $page,
            'totalPages'  => $totalPages,
            'totalCount'  => $totalItems,
            'isStaffPortal' => $isStaffPortal,
            'canCreateAlumni' => $canCreateAlumni,
            'form' => $form?->createView(),
        ]);
    }

    #[Route('/create', name: 'alumni_create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        $alumni = new Alumni();
        $form = $this->createForm(AlumniType::class, $alumni);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($alumni);
            $em->flush();
            $this->addFlash('success', 'Alumni record created successfully.');

            return $this->redirectToRoute('alumni_show', ['id' => $alumni->getId()]);
        }

        return $this->render('alumni/form.html.twig', [
            'form'  => $form->createView(),
            'title' => 'Add New Alumni',
            'alumni' => $alumni,
        ]);
    }

    #[Route('/{id}/edit', name: 'alumni_edit', methods: ['GET', 'POST'])]
    public function edit(Alumni $alumni, Request $request, EntityManagerInterface $em): Response
    {
        $isStaffPortal = $this->isGranted('ROLE_STAFF') && !$this->isGranted('ROLE_ADMIN');

        // Staff/admin can edit any record; alumni can only edit their own
        if (!$this->isGranted('ROLE_STAFF') && $alumni->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You can only edit your own record.');
        }

        $isApproved = $alumni->getUser()?->getAccountStatus() === 'active';
        $form = $isStaffPortal
            ? $this->createForm(AlumniVerificationType::class, $alumni, ['is_approved' => $isApproved])
            : $this->createForm(AlumniType::class, $alumni);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isStaffPortal) {
                $linkedUser = $alumni->getUser();
                if ($linkedUser !== null) {
                    $linkedUser->setAccountStatus($form->get('isApproved')->getData() ? 'active' : 'pending');
                }
            }

            $em->flush();
            $this->addFlash('success', 'Alumni record updated successfully.');

            return $this->redirectToRoute('alumni_show', ['id' => $alumni->getId()]);
        }

        return $this->render('alumni/form.html.twig', [
            'form'  => $form->createView(),
            'title' => $isStaffPortal ? 'Alumni Verification Portal — Edit Graduate' : 'Edit Alumni — ' . $alumni->getFullName(),
            'alumni' => $alumni,
            'isStaffPortal' => $isStaffPortal,
        ]);
    }

    #[Route('/{id}', name: 'alumni_show', methods: ['GET'])]
    public function show(Alumni $alumni): Response
    {
        return $this->render('alumni/show.html.twig', [
            'alumni' => $alumni,
        ]);
    }

    #[Route('/{id}/delete', name: 'alumni_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Alumni $alumni, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete' . $alumni->getId(), $request->request->get('_token'))) {
            $alumni->setDeletedAt(new \DateTime());
            $em->flush();
            $this->addFlash('success', 'Alumni record archived.');
        }

        return $this->redirectToRoute('alumni_index');
    }

    /**
     * @return array{unregistered: int, pending: int, active: int, inactive: int}
     */
    private function countRegistrationStates(QueryBuilder $baseQb, AlumniRepository $repo): array
    {
        $counts = [];

        foreach ([
            AlumniRepository::REGISTRATION_STATE_UNREGISTERED,
            AlumniRepository::REGISTRATION_STATE_PENDING,
            AlumniRepository::REGISTRATION_STATE_ACTIVE,
            AlumniRepository::REGISTRATION_STATE_INACTIVE,
        ] as $state) {
            $countQb = clone $baseQb;
            $countQb->resetDQLPart('orderBy');
            $countQb->setFirstResult(null);
            $countQb->setMaxResults(null);
            $countQb->select('COUNT(a.id)');
            $repo->applyRegistrationStateFilter($countQb, 'u', $state);
            $counts[$state] = (int) $countQb->getQuery()->getSingleScalarResult();
        }

        return $counts;
    }
}
