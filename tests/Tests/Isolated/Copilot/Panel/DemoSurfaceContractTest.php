<?php

/**
 * FROZEN acceptance tests — TRO-43 (upload surface) + TRO-44 MVP slice
 * (click-to-source) + the MVP latency/cost report (Week 2 MVP row 5:
 * "Deployed app, source-grounded UI, latency/cost report, walkthrough
 * video"). String/regex-pinned committed-artifact contracts, like the gate
 * workflow contract: the panel is a self-contained static page and the
 * report is prose — the pins are about committed text, not runtime DOM.
 *
 * Authored by the orchestrator and frozen: implementation agents make these
 * pass and MUST NOT modify this file. Scope note (recorded 2026-07-14): the
 * PDF bounding-box overlay is a FINAL-submission core deliverable, not an
 * MVP-row requirement — the MVP slice ships click-to-source with page-level
 * preview + byte-exact quote; the overlay (and its extraction-schema
 * coordinate extension) lands post-demo on TRO-44, which stays open.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Panel;

use PHPUnit\Framework\TestCase;

class DemoSurfaceContractTest extends TestCase
{
    private const MODULE_DIR = __DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-copilot';
    private const PANEL_PATH = self::MODULE_DIR . '/public/panel.html';
    private const BOOTSTRAP_PATH = self::MODULE_DIR . '/src/Bootstrap.php';
    private const REPORT_PATH = __DIR__ . '/../../../../../docs/W2_LATENCY_COST.md';

    private static function panel(): string
    {
        $raw = file_get_contents(self::PANEL_PATH);
        self::assertIsString($raw);

        return $raw;
    }

    public function testUploadSurfaceHitsTheGuardedDocumentRoute(): void
    {
        $panel = self::panel();

        $this->assertStringContainsString('/api/copilot/document', $panel, 'the upload surface hits the real guarded route (TRO-16), no side door');
        $this->assertStringContainsString('id="doc-file"', $panel, 'a file input exists');
        $this->assertStringContainsString('id="doc-type"', $panel, 'the clinician-facing doc-type selector exists');
        $this->assertStringContainsString('value="lab_pdf"', $panel, 'lab PDF is selectable');
        $this->assertStringContainsString('value="intake_form"', $panel, 'intake form is selectable');
        $this->assertStringContainsString('id="upload"', $panel, 'the upload trigger exists');
        $this->assertStringContainsString('extraction_status', $panel, 'the surface renders the round-trip result — attached AND extracted (or honestly failed), never fire-and-forget');
    }

    public function testCitationsAreClickToSourceAgainstTheGuardedResolverRoute(): void
    {
        $panel = self::panel();

        $this->assertStringContainsString('/api/copilot/source', $panel, 'citation clicks resolve through the guarded resolver route');
        $this->assertStringContainsString('dataset.token', $panel, 'each citation chip carries its exact token for resolution (provenance fidelity)');
        $this->assertStringContainsString('id="source-preview"', $panel, 'a dedicated source-preview container exists — the click has a visible destination');
    }

    public function testAnswerRegistersRenderSeparately(): void
    {
        $panel = self::panel();

        // The three registers (§4 / corpus README): patient-record facts,
        // detector flags, guideline evidence — visually distinct, never
        // interleaved as one undifferentiated citation soup.
        $this->assertStringContainsString('ref-guideline', $panel, 'guideline citations carry their own visual class');
        $this->assertStringContainsString('ref-detector', $panel, 'detector citations carry their own visual class');
        $this->assertStringContainsString('Guideline evidence', $panel, 'guideline evidence renders under its own heading (corpus README §2: never interleaved with patient-record facts)');
    }

    public function testSourceResolverRouteIsRegisteredThroughTheGuardedRegistrar(): void
    {
        $bootstrap = file_get_contents(self::BOOTSTRAP_PATH);
        $this->assertIsString($bootstrap);

        $this->assertStringContainsString("'POST /api/copilot/source'", $bootstrap, 'the resolver route is registered');
        $this->assertStringContainsString('SourceResolverEndpoint', $bootstrap, 'the route delegates to the shaped endpoint');
    }

    public function testLatencyCostReportIsCommittedWithMeasuredBasis(): void
    {
        $this->assertFileExists(self::REPORT_PATH, 'the MVP latency/cost report is a committed artifact');
        $report = file_get_contents(self::REPORT_PATH);
        $this->assertIsString($report);

        $this->assertStringContainsString('## Latency', $report);
        $this->assertStringContainsString('## Cost', $report);
        $this->assertStringContainsString('Measured 2026-07-14', $report, 'numbers carry their measurement date — measured, never invented (founder decision 2026-07-09)');
        $this->assertStringContainsString('p95', $report, 'latency reports percentiles, not averages');

        foreach (['snapshot', 'turn', 'ingestion'] as $flow) {
            $this->assertStringContainsString($flow, $report, "the {$flow} flow is covered");
        }

        // The two scaling curves stay separate (PS-9): extraction is
        // per-document, retrieval + answer is per-question.
        $this->assertStringContainsString('per-document', $report);
        $this->assertStringContainsString('per-question', $report);
    }
}
