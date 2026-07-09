<?php

/**
 * FROZEN acceptance tests — T21: the session-bound panel's default-deny gate
 * (UC1/UC3; AUDIT S4/S5; ARCHITECTURE.md §4 session-bound delegation).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: SessionGate is the session-page analogue of
 * GuardedRouteRegistrar — no session endpoint handler may run before it. It
 * is pure (verifiers and the principal source are injected callables) and
 * fails closed: a missing or blank CSRF token denies without consulting any
 * collaborator; a failed CSRF check denies before the ACL check; a failed
 * ACL check denies before the principal is read; a principal that cannot be
 * parsed into a named physician (S4: delegated reads require a named
 * principal, never ambient authority) denies even though every check passed.
 * Checks run in a fixed order — CSRF, then ACL, then principal — and every
 * denial is the same typed exception whose message never echoes the token.
 * Allow is the ONLY path that returns, and what it returns is the typed
 * principal (PhysicianContext) the downstream endpoints require.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Panel;

use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Panel\SessionAccessDeniedException;
use OpenEMR\Modules\Copilot\Panel\SessionGate;
use OpenEMR\Modules\Copilot\Routes\AclRequirement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SessionGateTest extends TestCase
{
    private const TOKEN = 'csrf-token-f00';

    /**
     * @param array<string, mixed> $principal
     * @param list<string>         $calls     records collaborator invocation order:
     *                                        'csrf', 'acl', 'principal'
     * @param array<string, mixed> $received  records the arguments each collaborator saw
     */
    private function gate(
        array &$calls,
        array &$received,
        bool $csrfOk = true,
        bool $aclOk = true,
        array $principal = ['username' => 'dr.tran', 'userId' => 7],
    ): SessionGate {
        return new SessionGate(
            function (string $token) use (&$calls, &$received, $csrfOk): bool {
                $calls[] = 'csrf';
                $received['csrfToken'] = $token;

                return $csrfOk;
            },
            function (string $section, string $value) use (&$calls, &$received, $aclOk): bool {
                $calls[] = 'acl';
                $received['aclSection'] = $section;
                $received['aclValue'] = $value;

                return $aclOk;
            },
            function () use (&$calls, $principal): array {
                $calls[] = 'principal';

                return $principal;
            },
        );
    }

    private function acl(): AclRequirement
    {
        return new AclRequirement('patients', 'demo');
    }

    public function testMissingTokenDeniesWithoutConsultingAnyCollaborator(): void
    {
        $calls = [];
        $received = [];
        $gate = $this->gate($calls, $received);

        try {
            $gate->authorize($this->acl(), null);
            $this->fail('A missing CSRF token must deny');
        } catch (SessionAccessDeniedException) {
        }

        $this->assertSame([], $calls, 'Fail closed: nothing runs without a token to verify');
    }

    public function testBlankTokenDeniesWithoutConsultingAnyCollaborator(): void
    {
        $calls = [];
        $received = [];
        $gate = $this->gate($calls, $received);

        try {
            $gate->authorize($this->acl(), '   ');
            $this->fail('A blank CSRF token must deny');
        } catch (SessionAccessDeniedException) {
        }

        $this->assertSame([], $calls);
    }

    public function testFailedCsrfDeniesBeforeAclAndPrincipal(): void
    {
        $calls = [];
        $received = [];
        $gate = $this->gate($calls, $received, csrfOk: false);

        try {
            $gate->authorize($this->acl(), self::TOKEN);
            $this->fail('A failed CSRF check must deny');
        } catch (SessionAccessDeniedException) {
        }

        $this->assertSame(['csrf'], $calls, 'CSRF failure must short-circuit: no ACL check, no principal read');
    }

    public function testFailedAclDeniesBeforePrincipal(): void
    {
        $calls = [];
        $received = [];
        $gate = $this->gate($calls, $received, aclOk: false);

        try {
            $gate->authorize($this->acl(), self::TOKEN);
            $this->fail('A failed ACL check must deny');
        } catch (SessionAccessDeniedException) {
        }

        $this->assertSame(['csrf', 'acl'], $calls, 'ACL failure must short-circuit: no principal read');
    }

    public function testCollaboratorsReceiveTheTokenAndTheAclRequirement(): void
    {
        $calls = [];
        $received = [];
        $gate = $this->gate($calls, $received);

        $gate->authorize(new AclRequirement('patients', 'med'), self::TOKEN);

        $this->assertSame(self::TOKEN, $received['csrfToken']);
        $this->assertSame('patients', $received['aclSection']);
        $this->assertSame('med', $received['aclValue']);
    }

    public function testAllChecksPassingReturnsTheNamedPhysicianPrincipal(): void
    {
        $calls = [];
        $received = [];
        $gate = $this->gate($calls, $received);

        $principal = $gate->authorize($this->acl(), self::TOKEN);

        $this->assertInstanceOf(PhysicianContext::class, $principal);
        $this->assertSame('dr.tran', $principal->username);
        $this->assertSame(7, $principal->userId);
        $this->assertSame(['csrf', 'acl', 'principal'], $calls, 'Fixed order: CSRF, then ACL, then principal');
    }

    public function testDigitStringUserIdIsParsedToInt(): void
    {
        $calls = [];
        $received = [];
        $gate = $this->gate($calls, $received, principal: ['username' => 'dr.tran', 'userId' => '7']);

        $principal = $gate->authorize($this->acl(), self::TOKEN);

        $this->assertSame(7, $principal->userId);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function unparseablePrincipalProvider(): array
    {
        return [
            'empty session' => [[]],
            'missing username' => [['userId' => 7]],
            'empty username' => [['username' => '', 'userId' => 7]],
            'whitespace username' => [['username' => '   ', 'userId' => 7]],
            'non-string username' => [['username' => 123, 'userId' => 7]],
            'missing userId' => [['username' => 'dr.tran']],
            'zero userId' => [['username' => 'dr.tran', 'userId' => 0]],
            'negative userId' => [['username' => 'dr.tran', 'userId' => -3]],
            'non-digit userId' => [['username' => 'dr.tran', 'userId' => 'abc']],
            'float userId' => [['username' => 'dr.tran', 'userId' => 3.5]],
            'null userId' => [['username' => 'dr.tran', 'userId' => null]],
        ];
    }

    /**
     * @param array<string, mixed> $principal
     */
    #[DataProvider('unparseablePrincipalProvider')]
    public function testUnparseablePrincipalDeniesEvenWhenEveryCheckPasses(array $principal): void
    {
        $calls = [];
        $received = [];
        $gate = $this->gate($calls, $received, principal: $principal);

        $this->expectException(SessionAccessDeniedException::class);
        $gate->authorize($this->acl(), self::TOKEN);
    }

    public function testDenialIsATypedRuntimeException(): void
    {
        $calls = [];
        $received = [];
        $gate = $this->gate($calls, $received, csrfOk: false);

        try {
            $gate->authorize($this->acl(), self::TOKEN);
            $this->fail('Expected a denial');
        } catch (SessionAccessDeniedException $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }

    public function testDenialMessageNeverEchoesTheToken(): void
    {
        $calls = [];
        $received = [];
        $gate = $this->gate($calls, $received, csrfOk: false);

        try {
            $gate->authorize($this->acl(), self::TOKEN);
            $this->fail('Expected a denial');
        } catch (SessionAccessDeniedException $e) {
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }
}
