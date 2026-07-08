<?php

/**
 * Disclosure logger backed by OpenEMR's EventAuditLogger (T2).
 *
 * Records each LLM crossing under the dedicated external-AI audit category
 * (AUDIT C5: "Add an audit category for external-AI disclosure — build it
 * with the feature"). Categories are free-form on the newEvent() path
 * (src/Common/Logging/EventAuditLogger.php:187-219 — $category = $event,
 * only 'delete' is special-cased). The sink closure is injected so the
 * logging contract is testable without a database; the production sink is
 * built by forEventAuditLogger(). Sink exceptions propagate — an unlogged
 * disclosure must never look logged (C1).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Audit;

use OpenEMR\Common\Logging\EventAuditLogger;

final class EventAuditDisclosureLogger implements DisclosureLogger
{
    public const CATEGORY = 'external-AI-disclosure';

    /**
     * @param \Closure(string, string, string, int): void $sink
     *        Receives (category, user, comments, patientPid) for exactly one
     *        audit event per disclosure.
     */
    public function __construct(private readonly \Closure $sink)
    {
    }

    /**
     * Production sink wrapping EventAuditLogger::getInstance()->newEvent().
     *
     * NOT covered by the isolated suite (it reaches the database) — verify
     * against the running stack.
     */
    public static function forEventAuditLogger(): self
    {
        return new self(
            static function (string $category, string $user, string $comments, int $patientPid): void {
                EventAuditLogger::getInstance()->newEvent($category, $user, '', 1, $comments, $patientPid);
            },
        );
    }

    public function record(Disclosure $disclosure): void
    {
        $comments = json_encode(
            [
                'data_classes' => $disclosure->dataClasses,
                'purpose' => $disclosure->purpose,
                'occurred_at' => $disclosure->occurredAt->format(\DateTimeInterface::ATOM),
            ],
            JSON_THROW_ON_ERROR,
        );

        ($this->sink)(self::CATEGORY, $disclosure->userId, $comments, $disclosure->patientPid);
    }
}
