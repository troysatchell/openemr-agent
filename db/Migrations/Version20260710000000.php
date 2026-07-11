<?php

/**
 * @package   openemr
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use OpenEMR\Common\Auth\AuthHash;

/**
 * S8 (AUDIT.md): relocate the timing-defense bootstrap out of the AuthUtils
 * constructor so login (including unauthenticated attempts) performs no DB
 * write. Seed the persisted `hidden_auth_dummy_hash` when absent and normalize a
 * blank `password_expiration_days` to 0. Idempotent — safe to run on a system
 * that already has these values.
 */
final class Version20260710000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'S8: seed hidden_auth_dummy_hash and normalize blank password_expiration_days so AuthUtils constructor is read-only';
    }

    public function up(Schema $schema): void
    {
        // Seed the durable timing-defense dummy hash only if it is missing.
        // passwordHash() takes its argument by reference, so use a variable.
        $dummyPassword = 'dummy';
        $dummyHash = (new AuthHash())->passwordHash($dummyPassword);
        $this->addSql(
            "INSERT INTO `globals` (`gl_name`, `gl_index`, `gl_value`) "
            . "SELECT 'hidden_auth_dummy_hash', 0, ? FROM DUAL "
            . "WHERE NOT EXISTS (SELECT 1 FROM `globals` WHERE `gl_name` = 'hidden_auth_dummy_hash')",
            [$dummyHash],
        );

        // Normalize a blank password_expiration_days to 0 (previously done lazily
        // by the constructor).
        $this->addSql(
            "UPDATE `globals` SET `gl_value` = '0' "
            . "WHERE `gl_name` = 'password_expiration_days' AND `gl_index` = 0 AND `gl_value` = ''",
        );
    }

    public function down(Schema $schema): void
    {
        // Intentionally no-op: removing the seeded dummy hash would re-introduce
        // the write on the login path, and the value is harmless to retain.
    }
}
