<?php

namespace App\Repository;

use App\Entity\Alumni;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Alumni>
 *
 * @method Alumni|null find($id, $lockMode = null, $lockVersion = null)
 * @method Alumni|null findOneBy(array $criteria, array $orderBy = null)
 * @method Alumni[]    findAll()
 * @method Alumni[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AlumniRepository extends ServiceEntityRepository
{
    public const REGISTRATION_STATE_UNREGISTERED = 'unregistered';
    public const REGISTRATION_STATE_PENDING = 'pending';
    public const REGISTRATION_STATE_ACTIVE = 'active';
    public const REGISTRATION_STATE_INACTIVE = 'inactive';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Alumni::class);
    }

    public function applyRegistrationStateFilter(QueryBuilder $qb, string $userAlias, ?string $registrationState): void
    {
        $normalizedState = strtolower(trim((string) $registrationState));

        switch ($normalizedState) {
            case self::REGISTRATION_STATE_UNREGISTERED:
                $qb->andWhere(sprintf('%s.id IS NULL', $userAlias));

                break;

            case self::REGISTRATION_STATE_PENDING:
                $qb->andWhere(sprintf('%s.accountStatus = :registrationStatePending', $userAlias))
                    ->setParameter('registrationStatePending', 'pending');

                break;

            case self::REGISTRATION_STATE_ACTIVE:
                $qb->andWhere(sprintf('%s.accountStatus = :registrationStateActive', $userAlias))
                    ->setParameter('registrationStateActive', 'active');

                break;

            case self::REGISTRATION_STATE_INACTIVE:
                $qb->andWhere(sprintf('%s.id IS NOT NULL', $userAlias))
                    ->andWhere(sprintf('%s.accountStatus NOT IN (:registrationStateOpen)', $userAlias))
                    ->setParameter('registrationStateOpen', ['pending', 'active']);

                break;
        }
    }

    /**
     * @return array{unregistered: int, pending: int, active: int, inactive: int}
     */
    public function countRegistrationStates(): array
    {
        $row = $this->createQueryBuilder('a')
            ->select('SUM(CASE WHEN u.id IS NULL THEN 1 ELSE 0 END) AS unregistered')
            ->addSelect('SUM(CASE WHEN u.accountStatus = :pending THEN 1 ELSE 0 END) AS pending')
            ->addSelect('SUM(CASE WHEN u.accountStatus = :active THEN 1 ELSE 0 END) AS active')
            ->addSelect('SUM(CASE WHEN u.id IS NOT NULL AND u.accountStatus NOT IN (:openStates) THEN 1 ELSE 0 END) AS inactive')
            ->leftJoin('a.user', 'u')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('pending', 'pending')
            ->setParameter('active', 'active')
            ->setParameter('openStates', ['pending', 'active'])
            ->getQuery()
            ->getSingleResult();

        return [
            self::REGISTRATION_STATE_UNREGISTERED => (int) ($row['unregistered'] ?? 0),
            self::REGISTRATION_STATE_PENDING => (int) ($row['pending'] ?? 0),
            self::REGISTRATION_STATE_ACTIVE => (int) ($row['active'] ?? 0),
            self::REGISTRATION_STATE_INACTIVE => (int) ($row['inactive'] ?? 0),
        ];
    }

    /**
     * Returns total alumni grouped by employment status.
     *
     * @return array<int, array{employmentStatus: string, total: int}>
     */
    public function countGroupedByEmploymentStatus(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select("COALESCE(NULLIF(TRIM(a.employmentStatus), ''), :unknown) AS employmentStatus")
            ->addSelect('COUNT(a.id) AS total')
            ->setParameter('unknown', 'Unknown')
            ->groupBy('employmentStatus')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'employmentStatus' => (string) $row['employmentStatus'],
            'total' => (int) $row['total'],
        ], $rows);
    }

    /**
     * Returns employment statistics as an associative array:
     * [
     *   'Employed' => 120,
     *   'Unemployed' => 35,
     *   'Self-Employed' => 22,
     * ]
     *
     * @return array<string, int>
     */
    public function getEmploymentStats(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select("COALESCE(NULLIF(TRIM(a.employmentStatus), ''), :unknown) AS employmentStatus")
            ->addSelect('COUNT(a.id) AS total')
            ->setParameter('unknown', 'Unknown')
            ->groupBy('employmentStatus')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $stats = [];
        foreach ($rows as $row) {
            $stats[(string) $row['employmentStatus']] = (int) $row['total'];
        }

        return $stats;
    }

    /**
     * Returns alumni tracer totals for dashboard pie chart.
     * Treats legacy "Fully Traced" values as TRACED for backward compatibility.
     *
     * @return array{TRACED: int, UNTRACED: int}
     */
    public function countTracedVsUntraced(): array
    {
        $row = $this->createQueryBuilder('a')
            ->select("SUM(CASE WHEN UPPER(COALESCE(NULLIF(TRIM(a.tracerStatus), ''), 'UNTRACED')) IN ('TRACED', 'FULLY TRACED') THEN 1 ELSE 0 END) AS traced")
            ->addSelect("SUM(CASE WHEN UPPER(COALESCE(NULLIF(TRIM(a.tracerStatus), ''), 'UNTRACED')) IN ('TRACED', 'FULLY TRACED') THEN 0 ELSE 1 END) AS untraced")
            ->andWhere('a.deletedAt IS NULL')
            ->getQuery()
            ->getSingleResult();

        return [
            'TRACED' => (int) ($row['traced'] ?? 0),
            'UNTRACED' => (int) ($row['untraced'] ?? 0),
        ];
    }

    /**
     * Search alumni by Batch Year, Campus (mapped to college), and Course.
     */
    public function searchByBatchCampusCourse(?int $batchYear, ?string $campus, ?string $course): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.deletedAt IS NULL');

        if ($batchYear !== null) {
            $qb->andWhere('a.yearGraduated = :batchYear')
                ->setParameter('batchYear', $batchYear);
        }

        if ($campus !== null && trim($campus) !== '') {
            $qb->andWhere('a.college LIKE :campus')
                ->setParameter('campus', '%' . trim($campus) . '%');
        }

        if ($course !== null && trim($course) !== '') {
            $qb->andWhere('a.course LIKE :course')
                ->setParameter('course', '%' . trim($course) . '%');
        }

        return $qb->orderBy('a.lastName', 'ASC');
    }
}
