<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * @return Notification[]
     */
    public function findRecentForUser(User $user, int $limit = 20): array
    {
        return $this->createQueryBuilder('n')
            ->leftJoin('n.actor', 'a')
            ->addSelect('a')
            ->andWhere('n.recipient = :user')
            ->andWhere('(n.expiresAt IS NULL OR n.expiresAt > :now)')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults(max(1, min($limit, 100)))
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Notification[]
     */
    public function findNewForUser(User $user, int $sinceId, int $limit = 20): array
    {
        return $this->createQueryBuilder('n')
            ->leftJoin('n.actor', 'a')
            ->addSelect('a')
            ->andWhere('n.recipient = :user')
            ->andWhere('n.id > :sinceId')
            ->andWhere('(n.expiresAt IS NULL OR n.expiresAt > :now)')
            ->setParameter('user', $user)
            ->setParameter('sinceId', max(0, $sinceId))
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('n.id', 'ASC')
            ->setMaxResults(max(1, min($limit, 100)))
            ->getQuery()
            ->getResult();
    }

    public function countUnreadForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.recipient = :user')
            ->andWhere('n.readAt IS NULL')
            ->andWhere('(n.expiresAt IS NULL OR n.expiresAt > :now)')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();
    }
}
