<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260425153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add saved QR registration batches table';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on mysql.'
        );

        $this->addSql('CREATE TABLE qr_registration_batch (id INT AUTO_INCREMENT NOT NULL, batch_year SMALLINT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_qr_registration_batch_year (batch_year), INDEX idx_qr_registration_batch_year (batch_year), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on mysql.'
        );

        $this->addSql('DROP TABLE qr_registration_batch');
    }
}