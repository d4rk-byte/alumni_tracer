<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user onboarding/auth fields and create registration_draft table for OTP-based registration';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on mysql.'
        );

        $this->addSql('ALTER TABLE `user` ADD google_subject VARCHAR(255) DEFAULT NULL, ADD email_verified_at DATETIME DEFAULT NULL, ADD profile_completed_at DATETIME DEFAULT NULL, ADD requires_onboarding TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_GOOGLE_SUBJECT ON `user` (google_subject)');

        $this->addSql('CREATE TABLE registration_draft (id INT AUTO_INCREMENT NOT NULL, flow_type VARCHAR(20) NOT NULL, email VARCHAR(180) NOT NULL, student_id VARCHAR(50) NOT NULL, first_name VARCHAR(255) NOT NULL, middle_name VARCHAR(255) DEFAULT NULL, last_name VARCHAR(255) NOT NULL, year_graduated INT DEFAULT NULL, college VARCHAR(255) DEFAULT NULL, department VARCHAR(255) DEFAULT NULL, password_hash_temp VARCHAR(255) NOT NULL, otp_code_hash VARCHAR(255) NOT NULL, otp_expires_at DATETIME NOT NULL, verify_attempts INT NOT NULL DEFAULT 0, resend_count INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, verified_at DATETIME DEFAULT NULL, dpa_consent TINYINT(1) NOT NULL DEFAULT 0, UNIQUE INDEX UNIQ_REGISTRATION_DRAFT_EMAIL (email), UNIQUE INDEX UNIQ_REGISTRATION_DRAFT_STUDENT_ID (student_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on mysql.'
        );

        $this->addSql('DROP TABLE registration_draft');
        $this->addSql('DROP INDEX UNIQ_USER_GOOGLE_SUBJECT ON `user`');
        $this->addSql('ALTER TABLE `user` DROP google_subject, DROP email_verified_at, DROP profile_completed_at, DROP requires_onboarding');
    }
}