<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260502100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add system settings table for registration controls';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on mysql.'
        );

        $this->addSql('CREATE TABLE system_setting (id INT AUTO_INCREMENT NOT NULL, key_name VARCHAR(120) NOT NULL, value LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_SYSTEM_SETTING_KEY_NAME (key_name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql("INSERT INTO system_setting (key_name, value) VALUES ('public_signup_enabled', '1')");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on mysql.'
        );

        $this->addSql('DROP TABLE system_setting');
    }
}
