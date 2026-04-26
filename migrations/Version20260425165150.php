<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260425165150 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 1: Add survey_campaign and survey_invitation tables; make gts_survey template-aware (user+template unique, invitation link).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE survey_campaign (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, email_subject VARCHAR(255) NOT NULL, email_body LONGTEXT NOT NULL, target_graduation_years JSON NOT NULL, target_college VARCHAR(255) DEFAULT NULL, target_course VARCHAR(255) DEFAULT NULL, expiry_days INT NOT NULL, status VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, sent_at DATETIME DEFAULT NULL, created_by VARCHAR(100) DEFAULT NULL, survey_template_id INT NOT NULL, INDEX IDX_43E254FABD22D0BD (survey_template_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE survey_invitation (id INT AUTO_INCREMENT NOT NULL, token VARCHAR(64) NOT NULL, status VARCHAR(30) NOT NULL, sent_at DATETIME DEFAULT NULL, opened_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, failed_at DATETIME DEFAULT NULL, failure_reason LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, campaign_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_B4676B95F639F774 (campaign_id), INDEX IDX_B4676B95A76ED395 (user_id), INDEX idx_invitation_status (status), UNIQUE INDEX uniq_invitation_token (token), UNIQUE INDEX uniq_invitation_campaign_user (campaign_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE survey_campaign ADD CONSTRAINT FK_43E254FABD22D0BD FOREIGN KEY (survey_template_id) REFERENCES gts_survey_template (id)');
        $this->addSql('ALTER TABLE survey_invitation ADD CONSTRAINT FK_B4676B95F639F774 FOREIGN KEY (campaign_id) REFERENCES survey_campaign (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE survey_invitation ADD CONSTRAINT FK_B4676B95A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE gts_survey DROP INDEX UNIQ_9621D0D4A76ED395, ADD INDEX IDX_9621D0D4A76ED395 (user_id)');
        $this->addSql('ALTER TABLE gts_survey ADD survey_template_id INT DEFAULT NULL, ADD survey_invitation_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE gts_survey ADD CONSTRAINT FK_9621D0D4BD22D0BD FOREIGN KEY (survey_template_id) REFERENCES gts_survey_template (id)');
        $this->addSql('ALTER TABLE gts_survey ADD CONSTRAINT FK_9621D0D4C846F908 FOREIGN KEY (survey_invitation_id) REFERENCES survey_invitation (id)');
        $this->addSql('CREATE INDEX IDX_9621D0D4BD22D0BD ON gts_survey (survey_template_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_gts_survey_user_template ON gts_survey (user_id, survey_template_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_gts_survey_invitation ON gts_survey (survey_invitation_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE survey_campaign DROP FOREIGN KEY FK_43E254FABD22D0BD');
        $this->addSql('ALTER TABLE survey_invitation DROP FOREIGN KEY FK_B4676B95F639F774');
        $this->addSql('ALTER TABLE survey_invitation DROP FOREIGN KEY FK_B4676B95A76ED395');
        $this->addSql('DROP TABLE survey_campaign');
        $this->addSql('DROP TABLE survey_invitation');
        $this->addSql('ALTER TABLE gts_survey DROP INDEX IDX_9621D0D4A76ED395, ADD UNIQUE INDEX UNIQ_9621D0D4A76ED395 (user_id)');
        $this->addSql('ALTER TABLE gts_survey DROP FOREIGN KEY FK_9621D0D4BD22D0BD');
        $this->addSql('ALTER TABLE gts_survey DROP FOREIGN KEY FK_9621D0D4C846F908');
        $this->addSql('DROP INDEX IDX_9621D0D4BD22D0BD ON gts_survey');
        $this->addSql('DROP INDEX uniq_gts_survey_user_template ON gts_survey');
        $this->addSql('DROP INDEX uniq_gts_survey_invitation ON gts_survey');
        $this->addSql('ALTER TABLE gts_survey DROP survey_template_id, DROP survey_invitation_id');
    }
}
