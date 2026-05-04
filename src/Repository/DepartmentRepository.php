<?php

namespace App\Repository;

use App\Entity\College;
use App\Entity\Department;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DepartmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Department::class);
    }

    /**
     * @return list<Department>
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Department>
     */
    public function findActiveWithActiveCollege(): array
    {
        return $this->createQueryBuilder('d')
            ->innerJoin('d.college', 'c')
            ->where('d.isActive = :active')
            ->andWhere('c.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Department>
     */
    public function findAllWithCollegeOrdered(): array
    {
        return $this->createQueryBuilder('d')
            ->innerJoin('d.college', 'c')
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Department>
     */
    public function findByCollege(College $college): array
    {
        return $this->createQueryBuilder('d')
            ->innerJoin('d.college', 'c')
            ->where('d.college = :college')
            ->andWhere('d.isActive = :active')
            ->andWhere('c.isActive = :active')
            ->setParameter('college', $college)
            ->setParameter('active', true)
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
