<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261103175601 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_logs (id BINARY(16) NOT NULL, action VARCHAR(40) NOT NULL, entity_type VARCHAR(60) NOT NULL, entity_id BINARY(16) DEFAULT NULL, old_values JSON DEFAULT NULL, new_values JSON DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL, tenant_id BINARY(16) NOT NULL, user_id BINARY(16) DEFAULT NULL, INDEX IDX_AUDIT_ENTITY (entity_type, entity_id), INDEX IDX_AUDIT_TENANT (tenant_id), INDEX IDX_D62F2858A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE changes (id BINARY(16) NOT NULL, title VARCHAR(200) NOT NULL, description VARCHAR(500) DEFAULT NULL, risk_level VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, scheduled_start DATETIME DEFAULT NULL, scheduled_end DATETIME DEFAULT NULL, tenant_id BINARY(16) NOT NULL, requester_id BINARY(16) NOT NULL, approver_id BINARY(16) DEFAULT NULL, INDEX IDX_CHANGE_TENANT (tenant_id), INDEX IDX_CHANGE_STATUS (status), INDEX IDX_2020B83DED442CF4 (requester_id), INDEX IDX_2020B83DBB23766C (approver_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE change_ci (change_id BINARY(16) NOT NULL, ci_id BINARY(16) NOT NULL, INDEX IDX_A5621FC3213C8BF4 (change_id), INDEX IDX_A5621FC3C79E5E2B (ci_id), PRIMARY KEY (change_id, ci_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ci_categories (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, type VARCHAR(255) NOT NULL, tenant_id BINARY(16) NOT NULL, INDEX IDX_3B89A1779033212A (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ci_items (id BINARY(16) NOT NULL, name VARCHAR(150) NOT NULL, description VARCHAR(255) DEFAULT NULL, status VARCHAR(255) NOT NULL, mac_address VARCHAR(50) DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, serial_number VARCHAR(100) DEFAULT NULL, location VARCHAR(100) DEFAULT NULL, metadata JSON DEFAULT NULL, tenant_id BINARY(16) NOT NULL, category_id INT NOT NULL, INDEX IDX_CI_TENANT (tenant_id), INDEX IDX_CI_STATUS (status), INDEX IDX_FF8CD57512469DE2 (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ci_relationships (id INT AUTO_INCREMENT NOT NULL, relationship_type VARCHAR(255) NOT NULL, parent_ci_id BINARY(16) NOT NULL, child_ci_id BINARY(16) NOT NULL, INDEX IDX_7B6D71E2BAFCA88 (parent_ci_id), INDEX IDX_7B6D71E29498FCB8 (child_ci_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE permissions (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, code VARCHAR(60) NOT NULL, UNIQUE INDEX UNIQ_PERMISSION_CODE (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE problems (id BINARY(16) NOT NULL, title VARCHAR(200) NOT NULL, description VARCHAR(500) DEFAULT NULL, root_cause LONGTEXT DEFAULT NULL, workaround LONGTEXT DEFAULT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, tenant_id BINARY(16) NOT NULL, INDEX IDX_PROBLEM_TENANT (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE problem_ticket (problem_id BINARY(16) NOT NULL, ticket_id BINARY(16) NOT NULL, INDEX IDX_D63CDFECA0DCED86 (problem_id), INDEX IDX_D63CDFEC700047D2 (ticket_id), PRIMARY KEY (problem_id, ticket_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE roles (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(60) NOT NULL, tenant_id BINARY(16) DEFAULT NULL, INDEX IDX_B63E2EC79033212A (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE role_permission (role_id INT NOT NULL, permission_id INT NOT NULL, INDEX IDX_6F7DF886D60322AC (role_id), INDEX IDX_6F7DF886FED90CCA (permission_id), PRIMARY KEY (role_id, permission_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sla_policies (id BINARY(16) NOT NULL, name VARCHAR(120) NOT NULL, response_time_minutes INT NOT NULL, resolution_time_minutes INT NOT NULL, business_hours_only TINYINT DEFAULT 1 NOT NULL, tenant_id BINARY(16) NOT NULL, priority_id INT NOT NULL, INDEX IDX_SLA_POLICY_TENANT (tenant_id), INDEX IDX_64A810DA497B19F9 (priority_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tenants (id BINARY(16) NOT NULL, name VARCHAR(150) NOT NULL, domain VARCHAR(100) NOT NULL, status TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_TENANT_DOMAIN (domain), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ticket_comments (id BINARY(16) NOT NULL, content LONGTEXT NOT NULL, is_private TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, ticket_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, INDEX IDX_COMMENT_TICKET (ticket_id), INDEX IDX_DAF76AABA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ticket_priorities (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, level INT DEFAULT 1 NOT NULL, color VARCHAR(20) DEFAULT NULL, tenant_id BINARY(16) NOT NULL, INDEX IDX_4F4AD2839033212A (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ticket_slas (id BINARY(16) NOT NULL, response_due_at DATETIME DEFAULT NULL, resolution_due_at DATETIME DEFAULT NULL, first_response_at DATETIME DEFAULT NULL, resolved_at DATETIME DEFAULT NULL, response_breached TINYINT DEFAULT 0 NOT NULL, resolution_breached TINYINT DEFAULT 0 NOT NULL, ticket_id BINARY(16) NOT NULL, sla_policy_id BINARY(16) NOT NULL, INDEX IDX_TICKET_SLA_BREACH (response_breached, resolution_breached), INDEX IDX_9E328D72700047D2 (ticket_id), INDEX IDX_9E328D7237771414 (sla_policy_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ticket_statuses (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, order_index INT DEFAULT 0 NOT NULL, is_closed_state TINYINT DEFAULT 0 NOT NULL, tenant_id BINARY(16) NOT NULL, INDEX IDX_CE99E2909033212A (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tickets (id BINARY(16) NOT NULL, ticket_code VARCHAR(30) NOT NULL, type VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, tenant_id BINARY(16) NOT NULL, status_id INT NOT NULL, priority_id INT NOT NULL, requester_id BINARY(16) NOT NULL, assignee_id BINARY(16) DEFAULT NULL, INDEX IDX_TICKET_TENANT (tenant_id), INDEX IDX_TICKET_CODE (ticket_code), INDEX IDX_54469DF46BF700BD (status_id), INDEX IDX_54469DF4497B19F9 (priority_id), INDEX IDX_54469DF4ED442CF4 (requester_id), INDEX IDX_54469DF459EC7D60 (assignee_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ticket_ci (ticket_id BINARY(16) NOT NULL, ci_id BINARY(16) NOT NULL, INDEX IDX_F45ED3E5700047D2 (ticket_id), INDEX IDX_F45ED3E5C79E5E2B (ci_id), PRIMARY KEY (ticket_id, ci_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE users (id BINARY(16) NOT NULL, name VARCHAR(120) NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, status TINYINT DEFAULT 1 NOT NULL, is_verified TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, tenant_id BINARY(16) DEFAULT NULL, role_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_TENANT_USER_EMAIL (tenant_id, email), INDEX IDX_1483A5E99033212A (tenant_id), INDEX IDX_1483A5E9D60322AC (role_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE wiki_articles (id BINARY(16) NOT NULL, title VARCHAR(255) NOT NULL, content LONGTEXT NOT NULL, is_public TINYINT DEFAULT 0 NOT NULL, views_count INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, tenant_id BINARY(16) NOT NULL, category_id INT NOT NULL, author_id BINARY(16) NOT NULL, INDEX IDX_WIKI_TENANT (tenant_id), INDEX IDX_WIKI_PUBLIC (is_public), INDEX IDX_541B34A712469DE2 (category_id), INDEX IDX_541B34A7F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE wiki_categories (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, description VARCHAR(255) DEFAULT NULL, tenant_id BINARY(16) NOT NULL, INDEX IDX_6FB92A09033212A (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE audit_logs ADD CONSTRAINT FK_D62F28589033212A FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audit_logs ADD CONSTRAINT FK_D62F2858A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE changes ADD CONSTRAINT FK_2020B83D9033212A FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE changes ADD CONSTRAINT FK_2020B83DED442CF4 FOREIGN KEY (requester_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE changes ADD CONSTRAINT FK_2020B83DBB23766C FOREIGN KEY (approver_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE change_ci ADD CONSTRAINT FK_A5621FC3213C8BF4 FOREIGN KEY (change_id) REFERENCES changes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE change_ci ADD CONSTRAINT FK_A5621FC3C79E5E2B FOREIGN KEY (ci_id) REFERENCES ci_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ci_categories ADD CONSTRAINT FK_3B89A1779033212A FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ci_items ADD CONSTRAINT FK_FF8CD5759033212A FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ci_items ADD CONSTRAINT FK_FF8CD57512469DE2 FOREIGN KEY (category_id) REFERENCES ci_categories (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ci_relationships ADD CONSTRAINT FK_7B6D71E2BAFCA88 FOREIGN KEY (parent_ci_id) REFERENCES ci_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ci_relationships ADD CONSTRAINT FK_7B6D71E29498FCB8 FOREIGN KEY (child_ci_id) REFERENCES ci_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE problems ADD CONSTRAINT FK_8E6662459033212A FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE problem_ticket ADD CONSTRAINT FK_D63CDFECA0DCED86 FOREIGN KEY (problem_id) REFERENCES problems (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE problem_ticket ADD CONSTRAINT FK_D63CDFEC700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE roles ADD CONSTRAINT FK_B63E2EC79033212A FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE role_permission ADD CONSTRAINT FK_6F7DF886D60322AC FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE role_permission ADD CONSTRAINT FK_6F7DF886FED90CCA FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sla_policies ADD CONSTRAINT FK_64A810DA9033212A FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sla_policies ADD CONSTRAINT FK_64A810DA497B19F9 FOREIGN KEY (priority_id) REFERENCES ticket_priorities (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ticket_comments ADD CONSTRAINT FK_DAF76AAB700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticket_comments ADD CONSTRAINT FK_DAF76AABA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticket_priorities ADD CONSTRAINT FK_4F4AD2839033212A FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticket_slas ADD CONSTRAINT FK_9E328D72700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticket_slas ADD CONSTRAINT FK_9E328D7237771414 FOREIGN KEY (sla_policy_id) REFERENCES sla_policies (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ticket_statuses ADD CONSTRAINT FK_CE99E2909033212A FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF49033212A FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF46BF700BD FOREIGN KEY (status_id) REFERENCES ticket_statuses (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF4497B19F9 FOREIGN KEY (priority_id) REFERENCES ticket_priorities (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF4ED442CF4 FOREIGN KEY (requester_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF459EC7D60 FOREIGN KEY (assignee_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ticket_ci ADD CONSTRAINT FK_F45ED3E5700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticket_ci ADD CONSTRAINT FK_F45ED3E5C79E5E2B FOREIGN KEY (ci_id) REFERENCES ci_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E99033212A FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9D60322AC FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE wiki_articles ADD CONSTRAINT FK_541B34A79033212A FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wiki_articles ADD CONSTRAINT FK_541B34A712469DE2 FOREIGN KEY (category_id) REFERENCES wiki_categories (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE wiki_articles ADD CONSTRAINT FK_541B34A7F675F31B FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE wiki_categories ADD CONSTRAINT FK_6FB92A09033212A FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE');
        $this->addSql('DROP TABLE user');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, name VARCHAR(100) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, roles JSON NOT NULL, password VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, is_verified TINYINT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE audit_logs DROP FOREIGN KEY FK_D62F28589033212A');
        $this->addSql('ALTER TABLE audit_logs DROP FOREIGN KEY FK_D62F2858A76ED395');
        $this->addSql('ALTER TABLE changes DROP FOREIGN KEY FK_2020B83D9033212A');
        $this->addSql('ALTER TABLE changes DROP FOREIGN KEY FK_2020B83DED442CF4');
        $this->addSql('ALTER TABLE changes DROP FOREIGN KEY FK_2020B83DBB23766C');
        $this->addSql('ALTER TABLE change_ci DROP FOREIGN KEY FK_A5621FC3213C8BF4');
        $this->addSql('ALTER TABLE change_ci DROP FOREIGN KEY FK_A5621FC3C79E5E2B');
        $this->addSql('ALTER TABLE ci_categories DROP FOREIGN KEY FK_3B89A1779033212A');
        $this->addSql('ALTER TABLE ci_items DROP FOREIGN KEY FK_FF8CD5759033212A');
        $this->addSql('ALTER TABLE ci_items DROP FOREIGN KEY FK_FF8CD57512469DE2');
        $this->addSql('ALTER TABLE ci_relationships DROP FOREIGN KEY FK_7B6D71E2BAFCA88');
        $this->addSql('ALTER TABLE ci_relationships DROP FOREIGN KEY FK_7B6D71E29498FCB8');
        $this->addSql('ALTER TABLE problems DROP FOREIGN KEY FK_8E6662459033212A');
        $this->addSql('ALTER TABLE problem_ticket DROP FOREIGN KEY FK_D63CDFECA0DCED86');
        $this->addSql('ALTER TABLE problem_ticket DROP FOREIGN KEY FK_D63CDFEC700047D2');
        $this->addSql('ALTER TABLE roles DROP FOREIGN KEY FK_B63E2EC79033212A');
        $this->addSql('ALTER TABLE role_permission DROP FOREIGN KEY FK_6F7DF886D60322AC');
        $this->addSql('ALTER TABLE role_permission DROP FOREIGN KEY FK_6F7DF886FED90CCA');
        $this->addSql('ALTER TABLE sla_policies DROP FOREIGN KEY FK_64A810DA9033212A');
        $this->addSql('ALTER TABLE sla_policies DROP FOREIGN KEY FK_64A810DA497B19F9');
        $this->addSql('ALTER TABLE ticket_comments DROP FOREIGN KEY FK_DAF76AAB700047D2');
        $this->addSql('ALTER TABLE ticket_comments DROP FOREIGN KEY FK_DAF76AABA76ED395');
        $this->addSql('ALTER TABLE ticket_priorities DROP FOREIGN KEY FK_4F4AD2839033212A');
        $this->addSql('ALTER TABLE ticket_slas DROP FOREIGN KEY FK_9E328D72700047D2');
        $this->addSql('ALTER TABLE ticket_slas DROP FOREIGN KEY FK_9E328D7237771414');
        $this->addSql('ALTER TABLE ticket_statuses DROP FOREIGN KEY FK_CE99E2909033212A');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF49033212A');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF46BF700BD');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF4497B19F9');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF4ED442CF4');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF459EC7D60');
        $this->addSql('ALTER TABLE ticket_ci DROP FOREIGN KEY FK_F45ED3E5700047D2');
        $this->addSql('ALTER TABLE ticket_ci DROP FOREIGN KEY FK_F45ED3E5C79E5E2B');
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_1483A5E99033212A');
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_1483A5E9D60322AC');
        $this->addSql('ALTER TABLE wiki_articles DROP FOREIGN KEY FK_541B34A79033212A');
        $this->addSql('ALTER TABLE wiki_articles DROP FOREIGN KEY FK_541B34A712469DE2');
        $this->addSql('ALTER TABLE wiki_articles DROP FOREIGN KEY FK_541B34A7F675F31B');
        $this->addSql('ALTER TABLE wiki_categories DROP FOREIGN KEY FK_6FB92A09033212A');
        $this->addSql('DROP TABLE audit_logs');
        $this->addSql('DROP TABLE changes');
        $this->addSql('DROP TABLE change_ci');
        $this->addSql('DROP TABLE ci_categories');
        $this->addSql('DROP TABLE ci_items');
        $this->addSql('DROP TABLE ci_relationships');
        $this->addSql('DROP TABLE permissions');
        $this->addSql('DROP TABLE problems');
        $this->addSql('DROP TABLE problem_ticket');
        $this->addSql('DROP TABLE roles');
        $this->addSql('DROP TABLE role_permission');
        $this->addSql('DROP TABLE sla_policies');
        $this->addSql('DROP TABLE tenants');
        $this->addSql('DROP TABLE ticket_comments');
        $this->addSql('DROP TABLE ticket_priorities');
        $this->addSql('DROP TABLE ticket_slas');
        $this->addSql('DROP TABLE ticket_statuses');
        $this->addSql('DROP TABLE tickets');
        $this->addSql('DROP TABLE ticket_ci');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE wiki_articles');
        $this->addSql('DROP TABLE wiki_categories');
    }
}
