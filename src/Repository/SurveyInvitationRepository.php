<?php

namespace App\Repository;

use App\Entity\SurveyCampaign;
use App\Entity\SurveyInvitation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurveyInvitation>
 */
class SurveyInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurveyInvitation::class);
    }

    public function findByToken(string $token): ?SurveyInvitation
    {
        return $this->findOneBy(['token' => $token]);
    }

    public function findPendingForUser(User $user): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.user = :user')
            ->andWhere('i.status NOT IN (:done)')
            ->setParameter('user', $user)
            ->setParameter('done', [SurveyInvitation::STATUS_COMPLETED, SurveyInvitation::STATUS_EXPIRED])
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findLatestForUser(User $user): ?SurveyInvitation
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.user = :user')
            ->setParameter('user', $user)
            ->orderBy('i.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return SurveyInvitation[] */
    public function findByCampaign(SurveyCampaign $campaign, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->orderBy('i.createdAt', 'ASC');

        if ($status !== null) {
            $qb->andWhere('i.status = :status')
               ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    public function countByCampaign(SurveyCampaign $campaign): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByCampaignAndStatus(SurveyCampaign $campaign, string $status): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.campaign = :campaign')
            ->andWhere('i.status = :status')
            ->setParameter('campaign', $campaign)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
