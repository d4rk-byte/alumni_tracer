<?php

namespace App\Controller;

use App\Entity\College;
use App\Entity\Department;
use App\Entity\Notification;
use App\Repository\CollegeRepository;
use App\Repository\DepartmentRepository;
use App\Service\AuditLogger;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/academic')]
#[IsGranted('ROLE_ADMIN')]
class AcademicManagementController extends AbstractController
{
    public function __construct(private AuditLogger $audit, private NotificationService $notifications) {}

    // ── COLLEGES ──
    #[Route('/colleges', name: 'admin_colleges', methods: ['GET'])]
    public function colleges(Request $request, CollegeRepository $repo): Response
    {
        $search = $request->query->get('q', '');
        $qb = $repo->createQueryBuilder('c');

        if ($search !== '') {
            $qb->where('c.name LIKE :q OR c.code LIKE :q')
               ->setParameter('q', '%' . $search . '%');
        }

        $colleges = $qb->orderBy('c.name', 'ASC')->getQuery()->getResult();

        return $this->render('admin/academic/colleges.html.twig', [
            'colleges' => $colleges,
            'search'   => $search,
            'total'    => count($colleges),
        ]);
    }

    #[Route('/colleges/create', name: 'admin_college_create', methods: ['GET', 'POST'])]
    public function createCollege(Request $request, EntityManagerInterface $em): Response
    {
        $college = new College();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('college_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token. Please try again.');
                return $this->redirectToRoute('admin_college_create');
            }

            $college->setName($request->request->get('name'))
                    ->setCode($request->request->get('code'))
                    ->setDescription($request->request->get('description'))
                    ->setIsActive((bool)$request->request->get('isActive'));

            try {
                $em->persist($college);
                $em->flush();
                $this->audit->log('COLLEGE_CREATED', 'College created: ' . $college->getName());
                $this->notifications->createAdminNotification(
                    'academic.college_created',
                    'College created',
                    'Created college: ' . $college->getName(),
                    Notification::SEVERITY_INFO,
                    '/system-setup',
                    'College',
                    $college->getId(),
                );
                $this->addFlash('success', 'College created successfully!');
                return $this->redirectToRoute('admin_colleges');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error creating college: ' . $e->getMessage());
            }
        }

        return $this->render('admin/academic/college_form.html.twig', ['college' => $college]);
    }

    #[Route('/colleges/{id}/edit', name: 'admin_college_edit', methods: ['GET', 'POST'])]
    public function editCollege(College $college, Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('college_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token. Please try again.');
                return $this->redirectToRoute('admin_college_edit', ['id' => $college->getId()]);
            }

            $college->setName($request->request->get('name'))
                    ->setCode($request->request->get('code'))
                    ->setDescription($request->request->get('description'))
                    ->setIsActive((bool)$request->request->get('isActive'));

            try {
                $em->flush();
                $this->audit->log('COLLEGE_UPDATED', 'College updated: ' . $college->getName());
                $this->addFlash('success', 'College updated successfully!');
                return $this->redirectToRoute('admin_colleges');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error updating college: ' . $e->getMessage());
            }
        }

        return $this->render('admin/academic/college_form.html.twig', ['college' => $college]);
    }

    #[Route('/colleges/{id}/delete', name: 'admin_college_delete', methods: ['POST'])]
    public function deleteCollege(College $college, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete' . $college->getId(), $request->request->get('_token'))) {
            try {
                $collegeName = $college->getName();
                $collegeId = $college->getId();
                $em->remove($college);
                $em->flush();
                $this->audit->log('COLLEGE_DELETED', 'College deleted: ' . $collegeName);
                $this->notifications->createAdminNotification(
                    'academic.college_deleted',
                    'College deleted',
                    'Deleted college: ' . $collegeName,
                    Notification::SEVERITY_WARNING,
                    '/system-setup',
                    'College',
                    $collegeId,
                );
                $this->addFlash('success', 'College deleted successfully!');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error deleting college: ' . $e->getMessage());
            }
        }

        return $this->redirectToRoute('admin_colleges');
    }

    // ── DEPARTMENTS ──
    #[Route('/departments', name: 'admin_departments', methods: ['GET'])]
    public function departments(Request $request, DepartmentRepository $repo, CollegeRepository $collegeRepo): Response
    {
        $search   = $request->query->get('q', '');
        $collegeFilter = $request->query->get('college', '');
        $qb = $repo->createQueryBuilder('d')
                   ->leftJoin('d.college', 'c');

        if ($search !== '') {
            $qb->where('d.name LIKE :q OR d.code LIKE :q OR c.name LIKE :q')
               ->setParameter('q', '%' . $search . '%');
        }

        if ($collegeFilter !== '') {
            $qb->andWhere('d.college = :college')
               ->setParameter('college', (int)$collegeFilter);
        }

        $departments = $qb->orderBy('c.name', 'ASC')
                          ->addOrderBy('d.name', 'ASC')
                          ->getQuery()
                          ->getResult();

        $colleges = $collegeRepo->findAll();

        return $this->render('admin/academic/departments.html.twig', [
            'departments'  => $departments,
            'colleges'     => $colleges,
            'search'       => $search,
            'collegeFilter' => $collegeFilter,
            'total'        => count($departments),
        ]);
    }

    #[Route('/departments/create', name: 'admin_department_create', methods: ['GET', 'POST'])]
    public function createDepartment(Request $request, EntityManagerInterface $em, CollegeRepository $collegeRepo): Response
    {
        $department = new Department();
        $colleges = $collegeRepo->findAll();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('department_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token. Please try again.');
                return $this->redirectToRoute('admin_department_create');
            }

            $college = $collegeRepo->find($request->request->get('college'));
            if (!$college) {
                $this->addFlash('error', 'College not found');
                return $this->redirectToRoute('admin_department_create');
            }

            $department->setName($request->request->get('name'))
                      ->setCode($request->request->get('code'))
                      ->setDescription($request->request->get('description'))
                      ->setCollege($college)
                      ->setIsActive((bool)$request->request->get('isActive'));

            try {
                $em->persist($department);
                $em->flush();
                $this->audit->log('DEPARTMENT_CREATED', 'Department created: ' . $department->getName());
                $this->notifications->createAdminNotification(
                    'academic.department_created',
                    'Department created',
                    'Created department: ' . $department->getName(),
                    Notification::SEVERITY_INFO,
                    '/system-setup',
                    'Department',
                    $department->getId(),
                );
                $this->addFlash('success', 'Department created successfully!');
                return $this->redirectToRoute('admin_departments');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error creating department: ' . $e->getMessage());
            }
        }

        return $this->render('admin/academic/department_form.html.twig', [
            'department' => $department,
            'colleges'   => $colleges,
        ]);
    }

    #[Route('/departments/{id}/edit', name: 'admin_department_edit', methods: ['GET', 'POST'])]
    public function editDepartment(Department $department, Request $request, EntityManagerInterface $em, CollegeRepository $collegeRepo): Response
    {
        $colleges = $collegeRepo->findAll();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('department_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token. Please try again.');
                return $this->redirectToRoute('admin_department_edit', ['id' => $department->getId()]);
            }

            $college = $collegeRepo->find($request->request->get('college'));
            if (!$college) {
                $this->addFlash('error', 'College not found');
                return $this->redirectToRoute('admin_departments');
            }

            $department->setName($request->request->get('name'))
                      ->setCode($request->request->get('code'))
                      ->setDescription($request->request->get('description'))
                      ->setCollege($college)
                      ->setIsActive((bool)$request->request->get('isActive'));

            try {
                $em->flush();
                $this->audit->log('DEPARTMENT_UPDATED', 'Department updated: ' . $department->getName());
                $this->addFlash('success', 'Department updated successfully!');
                return $this->redirectToRoute('admin_departments');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error updating department: ' . $e->getMessage());
            }
        }

        return $this->render('admin/academic/department_form.html.twig', [
            'department' => $department,
            'colleges'   => $colleges,
        ]);
    }

    #[Route('/departments/{id}/delete', name: 'admin_department_delete', methods: ['POST'])]
    public function deleteDepartment(Department $department, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete' . $department->getId(), $request->request->get('_token'))) {
            try {
                $departmentName = $department->getName();
                $departmentId = $department->getId();
                $em->remove($department);
                $em->flush();
                $this->audit->log('DEPARTMENT_DELETED', 'Department deleted: ' . $departmentName);
                $this->notifications->createAdminNotification(
                    'academic.department_deleted',
                    'Department deleted',
                    'Deleted department: ' . $departmentName,
                    Notification::SEVERITY_WARNING,
                    '/system-setup',
                    'Department',
                    $departmentId,
                );
                $this->addFlash('success', 'Department deleted successfully!');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error deleting department: ' . $e->getMessage());
            }
        }

        return $this->redirectToRoute('admin_departments');
    }
}
