<?php

/**
 * Value object naming the ACL section/value a copilot route requires.
 *
 * Exists so a route can never be registered without an explicit ACL
 * requirement (AUDIT S5: OpenEMR has no default-deny route gate, so this
 * module supplies one by construction).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Routes;

final readonly class AclRequirement
{
    public function __construct(
        public string $section,
        public string $value,
    ) {
        if (trim($this->section) === '') {
            throw new \InvalidArgumentException('ACL section must be a non-empty string.');
        }
        if (trim($this->value) === '') {
            throw new \InvalidArgumentException('ACL value must be a non-empty string.');
        }
    }
}
