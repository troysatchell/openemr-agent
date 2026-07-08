<?php

/**
 * FROZEN acceptance tests — T2: disclosure logging into the audit trail (C1/C5, AUDIT.md).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: disclosures are recorded through OpenEMR's
 * EventAuditLogger under the dedicated external-AI category (AUDIT.md C5:
 * "Add an audit category for external-AI disclosure — build it with the
 * feature"). The logger is tested against an injected sink closure; the
 * production sink wraps EventAuditLogger::getInstance()->newEvent(...) and is
 * verified against the running stack, not here.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Audit;

use OpenEMR\Modules\Copilot\Audit\Disclosure;
use OpenEMR\Modules\Copilot\Audit\DisclosureLogger;
use OpenEMR\Modules\Copilot\Audit\EventAuditDisclosureLogger;
use PHPUnit\Framework\TestCase;

class EventAuditDisclosureLoggerTest extends TestCase
{
    public function testImplementsTheDisclosureLoggerContract(): void
    {
        $logger = new EventAuditDisclosureLogger(static function (): void {
        });
        $this->assertInstanceOf(DisclosureLogger::class, $logger);
    }

    public function testCategoryIsTheDedicatedExternalAiCategory(): void
    {
        $this->assertSame('external-AI-disclosure', EventAuditDisclosureLogger::CATEGORY);
    }

    public function testRecordForwardsEveryRequiredFieldToTheSink(): void
    {
        $received = [];
        $sink = static function (string $category, string $user, string $comments, int $patientPid) use (&$received): void {
            $received[] = [$category, $user, $comments, $patientPid];
        };

        $logger = new EventAuditDisclosureLogger($sink);
        $logger->record(new Disclosure(
            'ellis.tran',
            42,
            ['medications', 'lab_results', 'allergies'],
            'between-patient-snapshot',
            new \DateTimeImmutable('2026-07-08 21:15:00', new \DateTimeZone('UTC')),
        ));

        $this->assertCount(1, $received, 'Exactly one audit event per disclosure.');
        [$category, $user, $comments, $patientPid] = $received[0];

        $this->assertSame(EventAuditDisclosureLogger::CATEGORY, $category);
        $this->assertSame('ellis.tran', $user);
        $this->assertSame(42, $patientPid);

        $decoded = json_decode($comments, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['medications', 'lab_results', 'allergies'], $decoded['data_classes']);
        $this->assertSame('between-patient-snapshot', $decoded['purpose']);
        $this->assertSame('2026-07-08T21:15:00+00:00', $decoded['occurred_at']);
    }

    public function testSinkFailurePropagatesRatherThanSilentlyDroppingTheAuditRecord(): void
    {
        $logger = new EventAuditDisclosureLogger(static function (): void {
            throw new \RuntimeException('audit sink unavailable');
        });

        $this->expectException(\RuntimeException::class);
        $logger->record(new Disclosure(
            'ellis.tran',
            42,
            ['medications'],
            'between-patient-snapshot',
            new \DateTimeImmutable('2026-07-08 21:15:00', new \DateTimeZone('UTC')),
        ));
    }
}
