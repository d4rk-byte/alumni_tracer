<?php

namespace App\Repository;

use App\Entity\GtsSurveyQuestion;
use App\Entity\GtsSurveyTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GtsSurveyQuestion>
 */
class GtsSurveyQuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GtsSurveyQuestion::class);
    }

    /**
     * @return list<GtsSurveyQuestion>
     */
    public function findOrdered(): array
    {
        return $this->createQueryBuilder('q')
            ->orderBy('q.section', 'ASC')
            ->addOrderBy('q.sortOrder', 'ASC')
            ->addOrderBy('q.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<GtsSurveyQuestion>
     */
    public function findOrderedByTemplate(GtsSurveyTemplate $template): array
    {
        return $this->createQueryBuilder('q')
            ->where('q.surveyTemplate = :template')
            ->setParameter('template', $template)
            ->orderBy('q.section', 'ASC')
            ->addOrderBy('q.sortOrder', 'ASC')
            ->addOrderBy('q.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<GtsSurveyQuestion>
     */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('q')
            ->andWhere('q.isActive = :active')
            ->andWhere('q.surveyTemplate IS NULL')
            ->setParameter('active', true)
            ->orderBy('q.section', 'ASC')
            ->addOrderBy('q.sortOrder', 'ASC')
            ->addOrderBy('q.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<GtsSurveyQuestion>
     */
    public function findActiveOrderedByTemplate(GtsSurveyTemplate $template): array
    {
        return $this->createQueryBuilder('q')
            ->andWhere('q.surveyTemplate = :template')
            ->andWhere('q.isActive = :active')
            ->setParameter('template', $template)
            ->setParameter('active', true)
            ->orderBy('q.section', 'ASC')
            ->addOrderBy('q.sortOrder', 'ASC')
            ->addOrderBy('q.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
