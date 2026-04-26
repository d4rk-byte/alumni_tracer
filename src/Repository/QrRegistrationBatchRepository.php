<?php

namespace App\Repository;

use App\Entity\QrRegistrationBatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QrRegistrationBatch>
 */
class QrRegistrationBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QrRegistrationBatch::class);
    }

    /** @return QrRegistrationBatch[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('b')
            ->orderBy('b.batchYear', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByBatchYear(int $batchYear): ?QrRegistrationBatch
    {
        return $this->findOneBy(['batchYear' => $batchYear]);
    }
}