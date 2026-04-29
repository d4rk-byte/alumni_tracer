<?php

namespace App\Repository;

use App\Entity\SurveyCampaign;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurveyCampaign>
 */
class SurveyCampaignRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurveyCampaign::class);
    }

    /** @return SurveyCampaign[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return SurveyCampaign[] */
    public function findDueScheduled(\DateTimeImmutable $dueAt): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.status = :scheduledStatus')
            ->andWhere('c.scheduledSendAt IS NOT NULL')
            ->andWhere('c.scheduledSendAt <= :dueAt')
            ->setParameter('scheduledStatus', 'scheduled')
            ->setParameter('dueAt', $dueAt)
            ->orderBy('c.scheduledSendAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
