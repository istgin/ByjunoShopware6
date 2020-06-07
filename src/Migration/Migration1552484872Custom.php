<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1552484872Custom extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1552484872;
    }

    public function update(Connection $connection): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `byjuno_log_entity` (
    `id` BINARY(16) NOT NULL,
    `request_id` VARCHAR(255) CHARACTER SET 'utf8',
    `request_type` VARCHAR(255) CHARACTER SET 'utf8',
    `firstname` VARCHAR(255) CHARACTER SET 'utf8',
    `lastname` VARCHAR(255) CHARACTER SET 'utf8',
    `ip` VARCHAR(255) CHARACTER SET 'utf8',
    `byjuno_status` VARCHAR(255) CHARACTER SET 'utf8',
    `xml_request` TEXT CHARACTER SET 'utf8',
    `xml_response` TEXT CHARACTER SET 'utf8',                
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`)
)
    ENGINE = InnoDB
    DEFAULT CHARSET = utf8mb4;
SQL;
        $connection->executeUpdate($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}