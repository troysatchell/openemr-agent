<?php

/**
 * RFC 7636 PKCE verifier/challenge pair for the SMART EHR-launch
 * authorization-code flow (TRO-53 / docs/TRO51_SMART_LAUNCH_DESIGN.md §4.2,
 * decision D2: public client + PKCE — the browser panel cannot keep a
 * secret).
 *
 * A verifier is 43-128 characters drawn from the RFC 7636 §4.1 unreserved
 * character set; the paired challenge is the unpadded base64url encoding of
 * its SHA-256 digest (S256 — this module never emits the insecure "plain"
 * method). `generate()` mints a fresh verifier from `random_bytes()`; every
 * launch gets its own pair, never a reused or constant one.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Panel\SmartLaunch;

final readonly class PkcePair
{
    /**
     * Random bytes used to mint a fresh verifier via generate(). Encoded as
     * base64url this yields an 86-character verifier — comfortably inside
     * the RFC 7636 §4.1 43-128 character range.
     */
    private const GENERATED_VERIFIER_BYTES = 64;

    private const VERIFIER_MIN_LENGTH = 43;

    private const VERIFIER_MAX_LENGTH = 128;

    private const VERIFIER_CHARSET_PATTERN = '/^[A-Za-z0-9\-._~]+$/';

    private function __construct(
        public string $verifier,
        public string $challenge,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the verifier does not satisfy
     *         RFC 7636 §4.1 (length 43-128, unreserved character set only)
     */
    public static function fromVerifier(string $verifier): self
    {
        $length = strlen($verifier);
        if (
            $length < self::VERIFIER_MIN_LENGTH
            || $length > self::VERIFIER_MAX_LENGTH
            || preg_match(self::VERIFIER_CHARSET_PATTERN, $verifier) !== 1
        ) {
            throw new \InvalidArgumentException(
                'PKCE verifier must be 43-128 characters from the RFC 7636 unreserved character set.'
            );
        }

        return new self($verifier, self::deriveChallenge($verifier));
    }

    /**
     * Mints a fresh, cryptographically random verifier and its S256
     * challenge — never a constant, never reused across launches.
     */
    public static function generate(): self
    {
        $verifier = self::base64Url(random_bytes(self::GENERATED_VERIFIER_BYTES));

        return new self($verifier, self::deriveChallenge($verifier));
    }

    private static function deriveChallenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
