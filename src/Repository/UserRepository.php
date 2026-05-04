<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function countUsersWithRole(string $role): int
    {
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)');

        $qb->andWhere($this->createRoleMatchExpression($qb, 'u', $role, 'role'));

        return (int) $qb
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return User[]
     */
    public function findActiveAdmins(): array
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.accountStatus = :activeStatus')
            ->setParameter('activeStatus', 'active')
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC');

        $qb->andWhere($this->createRoleMatchExpression($qb, 'u', 'ROLE_ADMIN', 'adminRole'));

        return $qb->getQuery()->getResult();
    }

    public function createRoleMatchExpression(QueryBuilder $qb, string $alias, string|int $role, string $parameterPrefix): string
    {
        $clauses = [];

        foreach (User::getRoleStoragePatterns($role) as $index => $pattern) {
            $parameterName = sprintf('%s_%d', $parameterPrefix, $index);
            $clauses[] = sprintf('%s.roles LIKE :%s', $alias, $parameterName);
            $qb->setParameter($parameterName, $pattern);
        }

        if (User::normalizeRoleCode($role) === User::ROLE_CODE_USER) {
            $emptyRoleParameter = sprintf('%s_empty', $parameterPrefix);
            $clauses[] = sprintf('%s.roles = :%s', $alias, $emptyRoleParameter);
            $qb->setParameter($emptyRoleParameter, '[]');
        }

        return '(' . implode(' OR ', $clauses) . ')';
    }
}
