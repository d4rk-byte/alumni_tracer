<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260415151442 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE announcement (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, category VARCHAR(100) DEFAULT NULL, date_posted DATETIME NOT NULL, is_active TINYINT NOT NULL, posted_by_id INT DEFAULT NULL, INDEX IDX_4DB9D91C5A6D2235 (posted_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE audit_log (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(50) NOT NULL, entity_type VARCHAR(255) NOT NULL, entity_id INT DEFAULT NULL, details LONGTEXT DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL, performed_by_id INT NOT NULL, INDEX IDX_F6E1C0F52E65C292 (performed_by_id), INDEX idx_audit_action (action), INDEX idx_audit_date (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE college (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, code VARCHAR(100) NOT NULL, description VARCHAR(500) DEFAULT NULL, is_active TINYINT DEFAULT NULL, UNIQUE INDEX UNIQ_AADA87025E237E06 (name), UNIQUE INDEX UNIQ_AADA870277153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE communication (id INT AUTO_INCREMENT NOT NULL, subject VARCHAR(255) NOT NULL, message LONGTEXT NOT NULL, channel VARCHAR(100) NOT NULL, recipient_count INT NOT NULL, target_year VARCHAR(255) DEFAULT NULL, sent_at DATETIME NOT NULL, sent_by VARCHAR(100) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE department (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, code VARCHAR(100) NOT NULL, description VARCHAR(500) DEFAULT NULL, is_active TINYINT DEFAULT NULL, college_id INT NOT NULL, UNIQUE INDEX UNIQ_CD1DE18A5E237E06 (name), UNIQUE INDEX UNIQ_CD1DE18A77153098 (code), INDEX IDX_CD1DE18A770124B2 (college_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE document (id INT AUTO_INCREMENT NOT NULL, original_filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(255) NOT NULL, document_type VARCHAR(100) NOT NULL, uploaded_at DATETIME NOT NULL, alumni_id INT NOT NULL, INDEX IDX_D8698A76D943BA32 (alumni_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE education (id INT AUTO_INCREMENT NOT NULL, degree_program VARCHAR(255) DEFAULT NULL, major VARCHAR(255) DEFAULT NULL, date_graduated DATE DEFAULT NULL, honors_received VARCHAR(255) DEFAULT NULL, school_name VARCHAR(255) DEFAULT NULL, gwa VARCHAR(20) DEFAULT NULL, scholarship_granted VARCHAR(255) DEFAULT NULL, alumni_id INT NOT NULL, INDEX IDX_DB0A5ED2D943BA32 (alumni_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE employment (id INT AUTO_INCREMENT NOT NULL, company_name VARCHAR(255) DEFAULT NULL, job_title VARCHAR(255) DEFAULT NULL, employment_status VARCHAR(100) DEFAULT NULL, employment_type VARCHAR(100) DEFAULT NULL, monthly_salary VARCHAR(100) DEFAULT NULL, date_hired DATE DEFAULT NULL, date_ended DATE DEFAULT NULL, work_location VARCHAR(500) DEFAULT NULL, industry VARCHAR(255) DEFAULT NULL, job_level VARCHAR(100) DEFAULT NULL, job_related_to_course TINYINT DEFAULT NULL, is_abroad TINYINT DEFAULT NULL, country VARCHAR(255) DEFAULT NULL, alumni_id INT NOT NULL, INDEX IDX_BF089C98D943BA32 (alumni_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE feedback (id INT AUTO_INCREMENT NOT NULL, suggestions LONGTEXT DEFAULT NULL, recommend_university TINYINT DEFAULT NULL, date_submitted DATETIME NOT NULL, alumni_id INT DEFAULT NULL, INDEX IDX_D2294458D943BA32 (alumni_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE gts_survey (id INT AUTO_INCREMENT NOT NULL, institution_code VARCHAR(100) DEFAULT NULL, control_code VARCHAR(100) DEFAULT NULL, name VARCHAR(255) NOT NULL, permanent_address LONGTEXT DEFAULT NULL, email_address VARCHAR(255) DEFAULT NULL, telephone_number VARCHAR(100) DEFAULT NULL, mobile_number VARCHAR(100) DEFAULT NULL, civil_status VARCHAR(50) DEFAULT NULL, sex VARCHAR(10) DEFAULT NULL, birthday DATE DEFAULT NULL, region_of_origin VARCHAR(50) DEFAULT NULL, province VARCHAR(100) DEFAULT NULL, location_of_residence VARCHAR(50) DEFAULT NULL, dynamic_answers JSON DEFAULT NULL, educational_attainment JSON DEFAULT NULL, professional_exams JSON DEFAULT NULL, reasons_for_course_undergrad JSON DEFAULT NULL, reasons_for_course_grad JSON DEFAULT NULL, reasons_for_course_other VARCHAR(255) DEFAULT NULL, trainings JSON DEFAULT NULL, reasons_advance_study JSON DEFAULT NULL, reason_advance_study_other VARCHAR(255) DEFAULT NULL, presently_employed VARCHAR(30) DEFAULT NULL, reasons_not_employed JSON DEFAULT NULL, reason_not_employed_other VARCHAR(255) DEFAULT NULL, present_employment_status VARCHAR(50) DEFAULT NULL, present_occupation VARCHAR(255) DEFAULT NULL, company_name_address LONGTEXT DEFAULT NULL, line_of_business VARCHAR(255) DEFAULT NULL, place_of_work VARCHAR(20) DEFAULT NULL, is_first_job_after_college TINYINT DEFAULT NULL, reasons_for_staying JSON DEFAULT NULL, reason_for_staying_other VARCHAR(255) DEFAULT NULL, first_job_related_to_course TINYINT DEFAULT NULL, reasons_for_accepting JSON DEFAULT NULL, reason_for_accepting_other VARCHAR(255) DEFAULT NULL, reasons_for_changing JSON DEFAULT NULL, reason_for_changing_other VARCHAR(255) DEFAULT NULL, duration_first_job VARCHAR(50) DEFAULT NULL, duration_first_job_other VARCHAR(255) DEFAULT NULL, how_found_first_job JSON DEFAULT NULL, how_found_first_job_other VARCHAR(255) DEFAULT NULL, time_to_land_first_job VARCHAR(50) DEFAULT NULL, time_to_land_first_job_other VARCHAR(255) DEFAULT NULL, job_level_first_job VARCHAR(100) DEFAULT NULL, job_level_current_job VARCHAR(100) DEFAULT NULL, initial_monthly_earning VARCHAR(100) DEFAULT NULL, curriculum_relevant TINYINT DEFAULT NULL, competencies_useful JSON DEFAULT NULL, competencies_useful_other VARCHAR(255) DEFAULT NULL, suggestions LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_9621D0D4A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE gts_survey_question (id INT AUTO_INCREMENT NOT NULL, question_text LONGTEXT NOT NULL, input_type VARCHAR(50) NOT NULL, section VARCHAR(120) NOT NULL, options JSON DEFAULT NULL, sort_order INT NOT NULL, is_active TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE job_posting (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, company_name VARCHAR(255) NOT NULL, location VARCHAR(500) DEFAULT NULL, description LONGTEXT NOT NULL, requirements LONGTEXT DEFAULT NULL, salary_range VARCHAR(100) DEFAULT NULL, employment_type VARCHAR(100) DEFAULT NULL, industry VARCHAR(255) DEFAULT NULL, related_course VARCHAR(255) DEFAULT NULL, contact_email VARCHAR(255) DEFAULT NULL, application_link VARCHAR(255) DEFAULT NULL, deadline DATE DEFAULT NULL, is_active TINYINT NOT NULL, date_posted DATETIME NOT NULL, date_updated DATETIME DEFAULT NULL, posted_by_id INT DEFAULT NULL, INDEX IDX_27C8EAE85A6D2235 (posted_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE reset_password_token (id INT AUTO_INCREMENT NOT NULL, hashed_token VARCHAR(64) NOT NULL, requested_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_452C9EC5A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91C5A6D2235 FOREIGN KEY (posted_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F52E65C292 FOREIGN KEY (performed_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE department ADD CONSTRAINT FK_CD1DE18A770124B2 FOREIGN KEY (college_id) REFERENCES college (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76D943BA32 FOREIGN KEY (alumni_id) REFERENCES alumni (id)');
        $this->addSql('ALTER TABLE education ADD CONSTRAINT FK_DB0A5ED2D943BA32 FOREIGN KEY (alumni_id) REFERENCES alumni (id)');
        $this->addSql('ALTER TABLE employment ADD CONSTRAINT FK_BF089C98D943BA32 FOREIGN KEY (alumni_id) REFERENCES alumni (id)');
        $this->addSql('ALTER TABLE feedback ADD CONSTRAINT FK_D2294458D943BA32 FOREIGN KEY (alumni_id) REFERENCES alumni (id)');
        $this->addSql('ALTER TABLE gts_survey ADD CONSTRAINT FK_9621D0D4A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE job_posting ADD CONSTRAINT FK_27C8EAE85A6D2235 FOREIGN KEY (posted_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE reset_password_token ADD CONSTRAINT FK_452C9EC5A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE work_history DROP FOREIGN KEY `FK_F271C869D943BA32`');
        $this->addSql('DROP TABLE work_history');
        $this->addSql('DROP INDEX UNIQ_FD567018CB944F1A ON alumni');
        $this->addSql('DROP INDEX UNIQ_FD567018E7927C74 ON alumni');
        $this->addSql('ALTER TABLE alumni ADD middle_name VARCHAR(255) DEFAULT NULL, ADD suffix VARCHAR(20) DEFAULT NULL, ADD sex VARCHAR(10) DEFAULT NULL, ADD date_of_birth DATE DEFAULT NULL, ADD civil_status VARCHAR(50) DEFAULT NULL, ADD contact_number VARCHAR(50) DEFAULT NULL, ADD home_address VARCHAR(500) DEFAULT NULL, ADD province VARCHAR(255) DEFAULT NULL, ADD year_graduated INT DEFAULT NULL, ADD college VARCHAR(255) DEFAULT NULL, ADD honors_received VARCHAR(255) DEFAULT NULL, ADD degree_program VARCHAR(255) DEFAULT NULL, ADD major VARCHAR(255) DEFAULT NULL, ADD date_graduated DATE DEFAULT NULL, ADD gwa VARCHAR(20) DEFAULT NULL, ADD scholarship_granted VARCHAR(255) DEFAULT NULL, ADD employment_status VARCHAR(100) DEFAULT NULL, ADD tracer_status VARCHAR(50) DEFAULT NULL, ADD last_tracer_submission_at DATETIME DEFAULT NULL, ADD employment_type VARCHAR(100) DEFAULT NULL, ADD job_title VARCHAR(255) DEFAULT NULL, ADD job_level VARCHAR(100) DEFAULT NULL, ADD industry VARCHAR(255) DEFAULT NULL, ADD company_address VARCHAR(500) DEFAULT NULL, ADD date_hired DATE DEFAULT NULL, ADD monthly_salary VARCHAR(100) DEFAULT NULL, ADD is_first_job TINYINT DEFAULT NULL, ADD years_in_company INT DEFAULT NULL, ADD work_abroad TINYINT DEFAULT NULL, ADD country_of_employment VARCHAR(255) DEFAULT NULL, ADD job_related_to_course TINYINT DEFAULT NULL, ADD promotion_received TINYINT DEFAULT NULL, ADD date_promoted DATE DEFAULT NULL, ADD skills_used_in_job LONGTEXT DEFAULT NULL, ADD trainings_attended LONGTEXT DEFAULT NULL, ADD licenses_obtained LONGTEXT DEFAULT NULL, ADD certifications LONGTEXT DEFAULT NULL, ADD career_achievements LONGTEXT DEFAULT NULL, ADD further_studies TINYINT DEFAULT NULL, ADD postgraduate_degree VARCHAR(255) DEFAULT NULL, ADD school_for_further_studies VARCHAR(255) DEFAULT NULL, ADD recommend_norsu TINYINT DEFAULT NULL, ADD suggestions_for_university LONGTEXT DEFAULT NULL, ADD willing_for_seminar TINYINT DEFAULT NULL, ADD willing_for_donation TINYINT DEFAULT NULL, ADD willing_for_mentorship TINYINT DEFAULT NULL, ADD deleted_at DATETIME DEFAULT NULL, ADD user_id INT DEFAULT NULL, DROP batch_year, DROP phone, DROP current_position, DROP salary_range, DROP location, DROP skills, CHANGE course course VARCHAR(255) DEFAULT NULL, CHANGE student_id student_number VARCHAR(100) NOT NULL, CHANGE email email_address VARCHAR(180) NOT NULL, CHANGE current_employment_status latin_honor VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE alumni ADD CONSTRAINT FK_FD567018A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FD56701818A6C7D4 ON alumni (student_number)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FD567018B08E074E ON alumni (email_address)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FD567018A76ED395 ON alumni (user_id)');
        $this->addSql('ALTER TABLE user ADD school_id VARCHAR(50) DEFAULT NULL, ADD account_status VARCHAR(50) NOT NULL, ADD date_registered DATETIME NOT NULL, ADD last_login DATETIME DEFAULT NULL, ADD last_activity DATETIME DEFAULT NULL, ADD dpa_consent TINYINT DEFAULT 0 NOT NULL, ADD dpa_consent_date DATETIME DEFAULT NULL, ADD profile_image VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649C32A47EE ON user (school_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE work_history (id INT AUTO_INCREMENT NOT NULL, position VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, company VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, alumni_id INT NOT NULL, INDEX IDX_F271C869D943BA32 (alumni_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE work_history ADD CONSTRAINT `FK_F271C869D943BA32` FOREIGN KEY (alumni_id) REFERENCES alumni (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE announcement DROP FOREIGN KEY FK_4DB9D91C5A6D2235');
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_F6E1C0F52E65C292');
        $this->addSql('ALTER TABLE department DROP FOREIGN KEY FK_CD1DE18A770124B2');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76D943BA32');
        $this->addSql('ALTER TABLE education DROP FOREIGN KEY FK_DB0A5ED2D943BA32');
        $this->addSql('ALTER TABLE employment DROP FOREIGN KEY FK_BF089C98D943BA32');
        $this->addSql('ALTER TABLE feedback DROP FOREIGN KEY FK_D2294458D943BA32');
        $this->addSql('ALTER TABLE gts_survey DROP FOREIGN KEY FK_9621D0D4A76ED395');
        $this->addSql('ALTER TABLE job_posting DROP FOREIGN KEY FK_27C8EAE85A6D2235');
        $this->addSql('ALTER TABLE reset_password_token DROP FOREIGN KEY FK_452C9EC5A76ED395');
        $this->addSql('DROP TABLE announcement');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE college');
        $this->addSql('DROP TABLE communication');
        $this->addSql('DROP TABLE department');
        $this->addSql('DROP TABLE document');
        $this->addSql('DROP TABLE education');
        $this->addSql('DROP TABLE employment');
        $this->addSql('DROP TABLE feedback');
        $this->addSql('DROP TABLE gts_survey');
        $this->addSql('DROP TABLE gts_survey_question');
        $this->addSql('DROP TABLE job_posting');
        $this->addSql('DROP TABLE reset_password_token');
        $this->addSql('ALTER TABLE alumni DROP FOREIGN KEY FK_FD567018A76ED395');
        $this->addSql('DROP INDEX UNIQ_FD56701818A6C7D4 ON alumni');
        $this->addSql('DROP INDEX UNIQ_FD567018B08E074E ON alumni');
        $this->addSql('DROP INDEX UNIQ_FD567018A76ED395 ON alumni');
        $this->addSql('ALTER TABLE alumni ADD batch_year INT NOT NULL, ADD current_employment_status VARCHAR(100) DEFAULT NULL, ADD phone VARCHAR(255) DEFAULT NULL, ADD current_position VARCHAR(255) DEFAULT NULL, ADD salary_range VARCHAR(255) DEFAULT NULL, ADD location VARCHAR(255) DEFAULT NULL, ADD skills VARCHAR(255) DEFAULT NULL, DROP middle_name, DROP suffix, DROP sex, DROP date_of_birth, DROP civil_status, DROP contact_number, DROP home_address, DROP province, DROP year_graduated, DROP college, DROP honors_received, DROP degree_program, DROP major, DROP date_graduated, DROP latin_honor, DROP gwa, DROP scholarship_granted, DROP employment_status, DROP tracer_status, DROP last_tracer_submission_at, DROP employment_type, DROP job_title, DROP job_level, DROP industry, DROP company_address, DROP date_hired, DROP monthly_salary, DROP is_first_job, DROP years_in_company, DROP work_abroad, DROP country_of_employment, DROP job_related_to_course, DROP promotion_received, DROP date_promoted, DROP skills_used_in_job, DROP trainings_attended, DROP licenses_obtained, DROP certifications, DROP career_achievements, DROP further_studies, DROP postgraduate_degree, DROP school_for_further_studies, DROP recommend_norsu, DROP suggestions_for_university, DROP willing_for_seminar, DROP willing_for_donation, DROP willing_for_mentorship, DROP deleted_at, DROP user_id, CHANGE course course VARCHAR(100) NOT NULL, CHANGE student_number student_id VARCHAR(100) NOT NULL, CHANGE email_address email VARCHAR(180) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FD567018CB944F1A ON alumni (student_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FD567018E7927C74 ON alumni (email)');
        $this->addSql('DROP INDEX UNIQ_8D93D649C32A47EE ON `user`');
        $this->addSql('ALTER TABLE `user` DROP school_id, DROP account_status, DROP date_registered, DROP last_login, DROP last_activity, DROP dpa_consent, DROP dpa_consent_date, DROP profile_image');
    }
}
