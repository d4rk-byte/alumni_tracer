<?php

namespace App\Repository;

use App\Entity\GtsSurvey;
use App\Entity\GtsSurveyTemplate;
use App\Entity\SurveyInvitation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GtsSurvey>
 */
class GtsSurveyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GtsSurvey::class);
    }

    /**
     * Legacy check: has the user submitted any survey at all.
     * Prefer hasUserSubmittedForTemplate() for template-aware checks.
     */
    public function hasUserSubmitted(User $user): bool
    {
        $count = (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function hasUserSubmittedLegacy(User $user): bool
    {
        $count = (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.user = :user')
            ->andWhere('s.surveyTemplate IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function hasUserSubmittedForTemplate(User $user, GtsSurveyTemplate $template): bool
    {
        $count = (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.user = :user')
            ->andWhere('s.surveyTemplate = :template')
            ->setParameter('user', $user)
            ->setParameter('template', $template)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function hasUserSubmittedForInvitation(User $user, SurveyInvitation $invitation): bool
    {
        $count = (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.user = :user')
            ->andWhere('s.surveyInvitation = :invitation')
            ->setParameter('user', $user)
            ->setParameter('invitation', $invitation)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function findOneByUser(User $user): ?GtsSurvey
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestByUser(User $user): ?GtsSurvey
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByUserAndTemplate(User $user, GtsSurveyTemplate $template): ?GtsSurvey
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.surveyTemplate = :template')
            ->setParameter('user', $user)
            ->setParameter('template', $template)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByInvitation(SurveyInvitation $invitation): ?GtsSurvey
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.surveyInvitation = :invitation')
            ->setParameter('invitation', $invitation)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
