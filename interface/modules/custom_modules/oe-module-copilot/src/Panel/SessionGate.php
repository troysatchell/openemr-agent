<?php

/**
 * Default-deny gate for the session-bound panel (T21; UC1/UC3; AUDIT S4/S5;
 * ARCHITECTURE.md §4 session-bound delegation).
 *
 * The session-page analogue of GuardedRouteRegistrar
 * (src/Routes/GuardedRouteRegistrar.php): no session endpoint handler may
 * run before this gate. Pure and fails closed — every collaborator (CSRF
 * verification, ACL check, principal lookup) is an injected callable, so
 * this class carries no framework or session knowledge of its own. Checks
 * run in a fixed order: a missing or blank CSRF token denies WITHOUT
 * consulting any collaborator; a failed CSRF check denies before the ACL
 * check runs; a failed ACL check denies before the principal is even read.
 * A principal that cannot be parsed into a named physician denies even
 * though every prior check passed (S4: delegated reads require a named
 * principal, never ambient authority — this mirrors the guard already
 * enforced by PhysicianContext's own constructor, applied here before
 * construction is attempted). Allow is the only path that returns, and it
 * returns the typed PhysicianContext the downstream panel endpoints
 * require.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Panel;

use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Routes\AclRequirement;

final readonly class SessionGate
{
    /**
     * @param \Closure $csrfVerifier    Contract: (string $token): bool.
     * @param \Closure $aclVerifier     Contract:
     *        (string $section, string $value): bool.
     * @param \Closure $principalReader Contract: (): array<string, mixed>
     *        with keys 'username' and 'userId'.
     */
    public function __construct(
        private \Closure $csrfVerifier,
        private \Closure $aclVerifier,
        private \Closure $principalReader,
    ) {
    }

    /**
     * Fixed order: CSRF, then ACL, then principal. Denies at the first
     * failing check and never evaluates a later collaborator once an
     * earlier one has failed (S5: fail closed by construction).
     *
     * @throws SessionAccessDeniedException on any denial path.
     */
    public function authorize(AclRequirement $acl, ?string $csrfToken): PhysicianContext
    {
        if ($csrfToken === null || trim($csrfToken) === '') {
            // Fail closed before touching any collaborator: there is
            // nothing to verify a blank token against (S5).
            throw new SessionAccessDeniedException('Session access denied: no CSRF token supplied.');
        }

        $csrfVerifier = $this->csrfVerifier;
        if (!$csrfVerifier($csrfToken)) {
            throw new SessionAccessDeniedException('Session access denied: CSRF verification failed.');
        }

        $aclVerifier = $this->aclVerifier;
        if (!$aclVerifier($acl->section, $acl->value)) {
            throw new SessionAccessDeniedException('Session access denied: authorization check failed.');
        }

        $principalReader = $this->principalReader;
        /** @var array<string, mixed> $principal */
        $principal = $principalReader();

        return $this->parsePrincipal($principal);
    }

    /**
     * @param array<string, mixed> $principal
     */
    private function parsePrincipal(array $principal): PhysicianContext
    {
        $username = $principal['username'] ?? null;
        if (!is_string($username) || trim($username) === '') {
            // S4: delegated reads require a named principal, never ambient
            // authority — an unparseable username denies even though every
            // prior check passed.
            throw new SessionAccessDeniedException('Session access denied: no named physician principal.');
        }

        $userId = $this->parseUserId($principal['userId'] ?? null);
        if ($userId === null) {
            throw new SessionAccessDeniedException('Session access denied: no named physician principal.');
        }

        return new PhysicianContext($username, $userId);
    }

    /**
     * Accepts a positive int, or a digit-only string cast to int; anything
     * else (missing, null, zero, negative, float, non-digit string) is
     * unparseable and denies (S4).
     */
    private function parseUserId(mixed $rawUserId): ?int
    {
        if (is_int($rawUserId)) {
            return $rawUserId > 0 ? $rawUserId : null;
        }

        if (is_string($rawUserId) && ctype_digit($rawUserId)) {
            $userId = (int) $rawUserId;

            return $userId > 0 ? $userId : null;
        }

        return null;
    }
}
