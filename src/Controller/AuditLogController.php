<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/audit-log')]
class AuditLogController extends AbstractController
{
    #[Route('', name: 'admin_audit_log', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(Request $request, AuditLogRepository $auditRepo, UserRepository $userRepo): Response
    {
        $filterAction = $request->query->get('action', '');
        $filterUser = $request->query->get('user', '');

        $logs = $auditRepo->findRecent(
            100,
            $filterAction ?: null,
            $filterUser ? (int) $filterUser : null
        );

        $actions = $auditRepo->createQueryBuilder('a')
            ->select('DISTINCT a.action')
            ->orderBy('a.action', 'ASC')
            ->getQuery()->getSingleColumnResult();

        $staffUsersQb = $userRepo->createQueryBuilder('u');
        $adminRoleExpression = $userRepo->createRoleMatchExpression($staffUsersQb, 'u', User::ROLE_CODE_ADMIN, 'admin_role');
        $staffRoleExpression = $userRepo->createRoleMatchExpression($staffUsersQb, 'u', User::ROLE_CODE_STAFF, 'staff_role');

        $staffUsers = $staffUsersQb
            ->where(sprintf('(%s OR %s)', $adminRoleExpression, $staffRoleExpression))
            ->orderBy('u.lastName', 'ASC')
            ->getQuery()->getResult();

        return $this->render('admin/audit_log.html.twig', [
            'logs' => $logs,
            'actions' => $actions,
            'staffUsers' => $staffUsers,
            'filterAction' => $filterAction,
            'filterUser' => $filterUser,
        ]);
    }

    #[Route('/{id}', name: 'admin_audit_log_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function show(AuditLog $log): Response
    {
        return $this->render('admin/audit_log_show.html.twig', [
            'log' => $log,
        ]);
    }

    #[Route('/export', name: 'admin_audit_log_export', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function export(Request $request, AuditLogRepository $auditRepo): StreamedResponse
    {
        $filterAction = $request->query->get('action', '');
        $filterUser = $request->query->get('user', '');

        $logs = $auditRepo->findRecent(
            5000,
            $filterAction ?: null,
            $filterUser ? (int) $filterUser : null
        );

        $response = new StreamedResponse(function () use ($logs) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Timestamp', 'Performed By', 'Action', 'Entity', 'Entity ID', 'Details', 'IP Address']);
            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->getCreatedAt()->format('Y-m-d H:i:s'),
                    $log->getPerformedBy()->getFullName(),
                    $log->getActionLabel(),
                    $log->getEntityType(),
                    $log->getEntityId(),
                    $log->getDetails(),
                    $log->getIpAddress(),
                ]);
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="audit_log_' . date('Ymd') . '.csv"');

        return $response;
    }

    #[Route('/{id}/delete', name: 'admin_audit_log_delete', methods: ['POST'], requirements: ['id' => '\\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(AuditLog $log, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('audit_delete_' . $log->getId(), (string) $request->request->get('_token'))) {
            $em->remove($log);
            $em->flush();
            $this->addFlash('success', 'Audit log entry deleted.');
        }

        return $this->redirectToRoute('admin_audit_log');
    }

    #[Route('/clear', name: 'admin_audit_log_clear', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function clear(Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('audit_clear_all', (string) $request->request->get('_token'))) {
            $em->createQuery('DELETE FROM App\\Entity\\AuditLog a')->execute();
            $this->addFlash('success', 'All audit log entries have been cleared.');
        }

        return $this->redirectToRoute('admin_audit_log');
    }
}
