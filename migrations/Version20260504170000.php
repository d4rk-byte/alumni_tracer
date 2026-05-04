<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin in-app notifications';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on mysql.'
        );

        $this->addSql('CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, recipient_id INT NOT NULL, actor_id INT DEFAULT NULL, type VARCHAR(80) NOT NULL, title VARCHAR(160) NOT NULL, message LONGTEXT NOT NULL, severity VARCHAR(20) NOT NULL, target_url VARCHAR(255) DEFAULT NULL, entity_type VARCHAR(80) DEFAULT NULL, entity_id INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', read_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_BF5476CABF396750 (recipient_id), INDEX IDX_BF5476CA10DAF24A (actor_id), INDEX idx_notification_recipient_created (recipient_id, created_at), INDEX idx_notification_recipient_read (recipient_id, read_at), INDEX idx_notification_expires_at (expires_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CABF396750 FOREIGN KEY (recipient_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA10DAF24A FOREIGN KEY (actor_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySQLPlatform,
            'Migration can only be executed safely on mysql.'
        );

        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CABF396750');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA10DAF24A');
        $this->addSql('DROP TABLE notification');
    }
}
