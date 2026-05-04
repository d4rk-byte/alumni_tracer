<?php

namespace App\Controller;

use App\Entity\Announcement;
use App\Entity\JobPosting;
use App\Repository\AnnouncementRepository;
use App\Repository\JobPostingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/public')]
class PublicApiController extends AbstractController
{
    #[Route('/announcements', name: 'api_public_announcements', methods: ['GET'])]
    public function announcements(Request $request, AnnouncementRepository $announcementRepo): JsonResponse
    {
        $limit = max(1, min((int) $request->query->get('limit', 6), 12));
        $announcements = $announcementRepo->findActiveAnnouncements($limit);

        return $this->json([
            'items' => array_map(fn (Announcement $announcement): array => $this->serializeAnnouncement($announcement), $announcements),
            'meta' => [
                'limit' => $limit,
                'total' => $announcementRepo->count(['isActive' => true]),
            ],
        ]);
    }

    #[Route('/announcements/{id}', name: 'api_public_announcement_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function announcement(Announcement $announcement): JsonResponse
    {
        if (!$announcement->isActive()) {
            throw $this->createNotFoundException();
        }

        return $this->json([
            'item' => $this->serializeAnnouncement($announcement),
        ]);
    }

    #[Route('/jobs', name: 'api_public_jobs', methods: ['GET'])]
    public function jobs(Request $request, JobPostingRepository $jobRepo): JsonResponse
    {
        $limit = max(1, min((int) $request->query->get('limit', 6), 12));
        $jobs = $jobRepo->findActiveJobs();

        return $this->json([
            'items' => array_map(fn (JobPosting $job): array => $this->serializeJob($job), array_slice($jobs, 0, $limit)),
            'meta' => [
                'limit' => $limit,
                'total' => count($jobs),
            ],
        ]);
    }

    #[Route('/jobs/{id}', name: 'api_public_job_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function job(JobPosting $job): JsonResponse
    {
        if (!$job->isActive() || $job->isExpired()) {
            throw $this->createNotFoundException();
        }

        return $this->json([
            'item' => $this->serializeJob($job),
        ]);
    }

    private function serializeAnnouncement(Announcement $announcement): array
    {
        return [
            'id' => $announcement->getId(),
            'title' => $announcement->getTitle(),
            'description' => $announcement->getDescription(),
            'category' => $announcement->getCategory(),
            'eventStartAt' => $announcement->getEventStartAt()?->format(DATE_ATOM),
            'location' => $announcement->getLocation(),
            'joinUrl' => $announcement->getJoinUrl(),
            'isActive' => $announcement->isActive(),
            'datePosted' => $announcement->getDatePosted()?->format(DATE_ATOM),
            'postedBy' => $announcement->getPostedBy() ? [
                'fullName' => $announcement->getPostedBy()->getFullName(),
            ] : null,
        ];
    }

    private function serializeJob(JobPosting $job): array
    {
        return [
            'id' => $job->getId(),
            'title' => $job->getTitle(),
            'companyName' => $job->getCompanyName(),
            'location' => $job->getLocation(),
            'description' => $job->getDescription(),
            'requirements' => $job->getRequirements(),
            'salaryRange' => $job->getSalaryRange(),
            'employmentType' => $job->getEmploymentType(),
            'industry' => $job->getIndustry(),
            'relatedCourse' => $job->getRelatedCourse(),
            'contactEmail' => $job->getContactEmail(),
            'applicationLink' => $job->getApplicationLink(),
            'imageFilename' => $job->getImageFilename(),
            'deadline' => $job->getDeadline()?->format('Y-m-d'),
            'isActive' => $job->isActive(),
            'isExpired' => $job->isExpired(),
            'datePosted' => $job->getDatePosted()?->format(DATE_ATOM),
            'dateUpdated' => $job->getDateUpdated()?->format(DATE_ATOM),
        ];
    }
}
