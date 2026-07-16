<?php

/**
 * FROZEN acceptance tests — TRO-53 (Wave N): PkcePair, the RFC 7636 PKCE
 * verifier/challenge pair for the SMART EHR-launch authorization-code flow
 * (TRO-51 / docs/TRO51_SMART_LAUNCH_DESIGN.md §4.2, decision D2: public
 * client + PKCE — the browser panel cannot keep a secret).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and
 * frozen: implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test:
 *  - fromVerifier() derives the S256 challenge:
 *    base64url(sha256(verifier)), unpadded (RFC 7636 §4.2, appendix B vector).
 *  - Verifiers are validated per RFC 7636 §4.1: 43–128 chars from the
 *    unreserved set [A-Za-z0-9\-._~] only.
 *  - generate() produces a fresh, valid, non-repeating pair.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Panel\SmartLaunch;

use OpenEMR\Modules\Copilot\Panel\SmartLaunch\PkcePair;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PkcePairTest extends TestCase
{
    // RFC 7636 appendix B reference vector.
    private const RFC_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const RFC_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    public function testDerivesTheRfc7636ReferenceChallenge(): void
    {
        $pair = PkcePair::fromVerifier(self::RFC_VERIFIER);

        $this->assertSame(self::RFC_VERIFIER, $pair->verifier);
        $this->assertSame(self::RFC_CHALLENGE, $pair->challenge);
    }

    public function testGenerateProducesAValidPair(): void
    {
        $pair = PkcePair::generate();

        $length = strlen($pair->verifier);
        $this->assertGreaterThanOrEqual(43, $length, 'RFC 7636 §4.1 minimum verifier length');
        $this->assertLessThanOrEqual(128, $length, 'RFC 7636 §4.1 maximum verifier length');
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9\-._~]+$/',
            $pair->verifier,
            'verifier restricted to the RFC 7636 unreserved character set'
        );

        // The challenge must be the unpadded base64url S256 of the verifier.
        $expected = rtrim(strtr(base64_encode(hash('sha256', $pair->verifier, true)), '+/', '-_'), '=');
        $this->assertSame($expected, $pair->challenge);
    }

    public function testGenerateDoesNotRepeat(): void
    {
        $this->assertNotSame(
            PkcePair::generate()->verifier,
            PkcePair::generate()->verifier,
            'verifiers are fresh cryptographic randomness, never a constant'
        );
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function invalidVerifierProvider(): array
    {
        return [
            'too short (42 chars)' => [str_repeat('a', 42)],
            'too long (129 chars)' => [str_repeat('a', 129)],
            'empty' => [''],
            'illegal character (+)' => [str_repeat('a', 42) . '+'],
            'illegal character (space)' => [str_repeat('a', 42) . ' '],
            'illegal character (=)' => [str_repeat('a', 42) . '='],
        ];
    }

    #[DataProvider('invalidVerifierProvider')]
    public function testRejectsInvalidVerifiers(string $verifier): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PkcePair::fromVerifier($verifier);
    }
}
