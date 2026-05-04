<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260502090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account settings profile fields to users';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on mysql.'
        );

        $this->addSql('ALTER TABLE `user` ADD username VARCHAR(80) DEFAULT NULL, ADD phone_number VARCHAR(50) DEFAULT NULL, ADD bio LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_USERNAME ON `user` (username)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on mysql.'
        );

        $this->addSql('DROP INDEX UNIQ_IDENTIFIER_USERNAME ON `user`');
        $this->addSql('ALTER TABLE `user` DROP username, DROP phone_number, DROP bio');
    }
}
