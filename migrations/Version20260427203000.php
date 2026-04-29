<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\User;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize user roles to numeric role codes (1=admin, 2=staff, 3=user)';
    }

    public function up(Schema $schema): void
    {
        $this->rewriteRoles(static fn (int $roleCode): int|string => $roleCode);
    }

    public function down(Schema $schema): void
    {
        $this->rewriteRoles(static function (int $roleCode): string {
            return match ($roleCode) {
                User::ROLE_CODE_ADMIN => 'ROLE_ADMIN',
                User::ROLE_CODE_STAFF => 'ROLE_STAFF',
                default => User::ROLE_ALUMNI,
            };
        });
    }

    /** @param callable(int): int|string $mapRole */
    private function rewriteRoles(callable $mapRole): void
    {
        $tableName = $this->connection->quoteIdentifier('user');
        $rows = $this->connection->fetchAllAssociative(sprintf('SELECT id, roles FROM %s', $tableName));

        foreach ($rows as $row) {
            $decodedRoles = json_decode((string) ($row['roles'] ?? '[]'), true);

            if (!is_array($decodedRoles)) {
                continue;
            }

            $roleCodes = [];

            foreach ($decodedRoles as $role) {
                $roleCodes[] = User::normalizeRoleCode(is_int($role) ? $role : (string) $role);
            }

            if ($roleCodes === []) {
                $roleCodes[] = User::ROLE_CODE_USER;
            }

            $roleCodes = array_values(array_unique($roleCodes));
            sort($roleCodes);

            $storedRoles = array_map($mapRole, $roleCodes);

            $this->connection->executeStatement(
                sprintf('UPDATE %s SET roles = :roles WHERE id = :id', $tableName),
                [
                    'roles' => json_encode($storedRoles, JSON_THROW_ON_ERROR),
                    'id' => (int) $row['id'],
                ]
            );
        }
    }
}