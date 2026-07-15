<?php

/**
 * Server-side store for in-flight SMART launch handshakes
 * (TRO-53 / docs/TRO51_SMART_LAUNCH_DESIGN.md §4.2, decision D2-confidential).
 *
 * The PKCE verifier and the `state` nonce are held in the physician's PHP
 * session — never handed to the browser — because a confidential client
 * exchanges the authorization code server-side with its secret, and the
 * verifier is proof of possession. `launch.php` stores a handshake before
 * redirecting to the authorization server; the returning `launch-exchange.php`
 * consumes it exactly once (single use), binding the exchange to the same
 * session that initiated the launch and to the `state` the authorization
 * server echoed back.
 *
 * Handshakes are keyed by `state` so concurrent launches from the same session
 * (a provider with two patient charts open in two tabs) coexist — each
 * callback consumes only its own entry (CodeRabbit SMART-002). The map is
 * capped so a stream of abandoned launches cannot grow the session unbounded.
 *
 * One class owns the session key + shape so the writer and reader never drift.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Panel\SmartLaunch;

use OpenEMR\Common\Session\SessionUtil;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class SmartLaunchSession
{
    /** Session key holding the state-keyed map of pending handshakes. */
    private const KEY = 'copilot_smart_pkce';

    /** Cap on concurrently pending launches — abandoned ones age out FIFO. */
    private const MAX_PENDING = 8;

    /**
     * Record a pending handshake, keyed by its `state` nonce. `redirectUri` is
     * kept so the exchange sends the authorization server the identical value
     * the authorize request used (OAuth requires the match).
     *
     * Reads the existing map via the session getter (permitted); the write
     * goes through SessionUtil (never a direct $session->set()), which reopens
     * a read_and_close session so the value survives to the next request.
     */
    public static function store(
        SessionInterface $session,
        string $verifier,
        string $state,
        string $redirectUri,
    ): void {
        $map = self::readMap($session);
        $map[$state] = ['verifier' => $verifier, 'redirect_uri' => $redirectUri];
        if (count($map) > self::MAX_PENDING) {
            // Keep the most recent MAX_PENDING (insertion order), dropping the
            // oldest abandoned handshakes.
            $map = array_slice($map, -self::MAX_PENDING, null, true);
        }

        SessionUtil::setSession(self::KEY, $map);
    }

    /**
     * Consume the handshake for `returnedState` exactly once. Returns its
     * verifier + redirect_uri only when a handshake with that state is pending;
     * an unknown/blank state returns null and leaves other pending launches
     * untouched. The matched entry is always removed (single use — no replay).
     *
     * @return array{verifier: string, redirect_uri: string}|null
     */
    public static function consume(SessionInterface $session, string $returnedState): ?array
    {
        $map = self::readMap($session);
        if ($returnedState === '' || !array_key_exists($returnedState, $map)) {
            return null;
        }

        $entry = $map[$returnedState];
        unset($map[$returnedState]);
        if ($map === []) {
            SessionUtil::unsetSession(self::KEY);
        } else {
            SessionUtil::setSession(self::KEY, $map);
        }

        $verifier = is_array($entry) ? ($entry['verifier'] ?? null) : null;
        $redirectUri = is_array($entry) ? ($entry['redirect_uri'] ?? null) : null;
        if (!is_string($verifier) || !is_string($redirectUri)) {
            return null;
        }

        return ['verifier' => $verifier, 'redirect_uri' => $redirectUri];
    }

    /**
     * The pending-handshake map for this session, or [] when none/malformed.
     *
     * @return array<string, mixed>
     */
    private static function readMap(SessionInterface $session): array
    {
        $raw = $session->get(self::KEY);
        if (!is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $key => $value) {
            $map[(string) $key] = $value;
        }

        return $map;
    }
}
