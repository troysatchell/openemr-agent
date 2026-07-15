<?php

/**
 * Server-side store for the in-flight SMART launch handshake
 * (TRO-53 / docs/TRO51_SMART_LAUNCH_DESIGN.md §4.2, decision D2-confidential).
 *
 * The PKCE verifier and the CSRF-equivalent `state` nonce are held in the
 * physician's PHP session — never handed to the browser — because a
 * confidential client exchanges the authorization code server-side with its
 * secret, and the verifier is proof of possession. `launch.php` stores the
 * handshake before redirecting to the authorization server; the returning
 * `launch-exchange.php` consumes it exactly once (single use), binding the
 * exchange to the same session that initiated the launch and to the `state`
 * the authorization server echoed back.
 *
 * One class owns the session keys so the writer and reader can never drift.
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
    private const KEY_VERIFIER = 'copilot_smart_pkce_verifier';
    private const KEY_STATE = 'copilot_smart_pkce_state';
    private const KEY_REDIRECT_URI = 'copilot_smart_redirect_uri';

    /**
     * Persist the in-flight handshake for this session. `redirectUri` is kept
     * so the exchange sends the authorization server the identical value the
     * authorize request used — OAuth requires the two to match.
     *
     * Writes go through SessionUtil (never a direct $session->set()), which
     * reopens a read_and_close session for writing so the value actually
     * survives to the next request — the whole point of this store.
     */
    public static function store(
        string $verifier,
        string $state,
        string $redirectUri,
    ): void {
        SessionUtil::setSession([
            self::KEY_VERIFIER => $verifier,
            self::KEY_STATE => $state,
            self::KEY_REDIRECT_URI => $redirectUri,
        ]);
    }

    /**
     * Consume the handshake exactly once. Returns the verifier + redirect_uri
     * only when a handshake is pending AND the supplied `state` matches the
     * stored nonce; any mismatch, or an absent handshake, returns null. The
     * stored values are always cleared (a code, matched or not, ends the
     * handshake — no replay).
     *
     * Reads use the active session's getter (permitted); the clear-out writes
     * through SessionUtil.
     *
     * @return array{verifier: string, redirect_uri: string}|null
     */
    public static function consume(SessionInterface $session, string $returnedState): ?array
    {
        $verifier = $session->get(self::KEY_VERIFIER);
        $state = $session->get(self::KEY_STATE);
        $redirectUri = $session->get(self::KEY_REDIRECT_URI);

        self::clear();

        if (!is_string($verifier) || !is_string($state) || !is_string($redirectUri)) {
            return null;
        }
        // Constant-time compare — the state is an unguessable per-launch nonce
        // and the only thing binding the returned code to this session.
        if ($returnedState === '' || !hash_equals($state, $returnedState)) {
            return null;
        }

        return ['verifier' => $verifier, 'redirect_uri' => $redirectUri];
    }

    public static function clear(): void
    {
        SessionUtil::unsetSession([self::KEY_VERIFIER, self::KEY_STATE, self::KEY_REDIRECT_URI]);
    }
}
