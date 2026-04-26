<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260425160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add GTS survey template table and link questions to templates';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on mysql.'
        );

        $this->addSql('CREATE TABLE gts_survey_template (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE gts_survey_question ADD survey_template_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE gts_survey_question ADD CONSTRAINT FK_gts_q_template FOREIGN KEY (survey_template_id) REFERENCES gts_survey_template (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_gts_q_template ON gts_survey_question (survey_template_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on mysql.'
        );

        $this->addSql('ALTER TABLE gts_survey_question DROP FOREIGN KEY FK_gts_q_template');
        $this->addSql('ALTER TABLE gts_survey_question DROP INDEX IDX_gts_q_template');
        $this->addSql('ALTER TABLE gts_survey_question DROP COLUMN survey_template_id');
        $this->addSql('DROP TABLE gts_survey_template');
    }
}
