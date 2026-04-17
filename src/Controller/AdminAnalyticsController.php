<?php

namespace App\Controller;

use App\Repository\AlumniRepository;
use App\Repository\GtsSurveyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
final class AdminAnalyticsController extends AbstractController
{
    #[Route('/analytics', name: 'admin_analytics_hub', methods: ['GET'])]
    public function index(AlumniRepository $alumniRepo, GtsSurveyRepository $gtsRepo): Response
    {
        $totalAlumni = $alumniRepo->count([]);
        $employed = $alumniRepo->count(['employmentStatus' => 'Employed']);
        $selfEmployed = $alumniRepo->count(['employmentStatus' => 'Self-Employed']);
        $unemployed = $alumniRepo->count(['employmentStatus' => 'Unemployed']);
        $employmentRate = $totalAlumni > 0 ? round(($employed + $selfEmployed) / $totalAlumni * 100, 1) : 0;

        $aligned = $alumniRepo->count(['jobRelatedToCourse' => true]);
        $notAligned = $alumniRepo->count(['jobRelatedToCourse' => false]);
        $alignmentBase = $aligned + $notAligned;
        $alignmentRate = $alignmentBase > 0 ? round($aligned / $alignmentBase * 100, 1) : 0;

        $totalGtsResponses = $gtsRepo->count([]);
        $latestGtsResponse = $gtsRepo->findOneBy([], ['createdAt' => 'DESC']);

        return $this->render('admin/analytics_hub.html.twig', [
            'totalAlumni' => $totalAlumni,
            'employed' => $employed,
            'selfEmployed' => $selfEmployed,
            'unemployed' => $unemployed,
            'employmentRate' => $employmentRate,
            'alignmentRate' => $alignmentRate,
            'totalGtsResponses' => $totalGtsResponses,
            'latestGtsResponse' => $latestGtsResponse,
        ]);
    }
}