<?php

/**
 * FROZEN acceptance tests — TRO-44 (panel side): claim-to-pixels in one motion.
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract, string-pinned like the other committed-artifact contracts: the
 * token panel vendors PDF.js under public/vendor/pdfjs/ (self-contained —
 * no CDN, with the version/license recorded), renders the resolved source
 * document ('document_base64') onto a canvas via pdfjsLib.getDocument at the
 * cited page, and positions a normalized-coordinate overlay ('bbox-overlay')
 * from the preview's bbox — skipping the overlay honestly when bbox is null.
 * The three-register separation (patient facts / detector flags / guideline
 * evidence) already ships (TRO-34) and is re-pinned here because TRO-44's
 * acceptance names it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Panel;

use PHPUnit\Framework\TestCase;

class BboxOverlayContractTest extends TestCase
{
    private const MODULE_PUBLIC = __DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-copilot/public';

    private static function panel(): string
    {
        $raw = file_get_contents(self::MODULE_PUBLIC . '/panel.html');
        self::assertIsString($raw);

        return $raw;
    }

    public function testPdfJsIsVendoredSelfContained(): void
    {
        $lib = self::MODULE_PUBLIC . '/vendor/pdfjs/pdf.min.js';
        $worker = self::MODULE_PUBLIC . '/vendor/pdfjs/pdf.worker.min.js';

        $this->assertFileExists($lib, 'PDF.js main library is vendored — no CDN reachable from the panel');
        $this->assertFileExists($worker, 'PDF.js worker is vendored');

        $libSize = filesize($lib);
        $workerSize = filesize($worker);
        $this->assertIsInt($libSize);
        $this->assertIsInt($workerSize);
        $this->assertGreaterThan(50_000, $libSize, 'the real library, not a stub');
        $this->assertGreaterThan(50_000, $workerSize, 'the real worker, not a stub');

        $this->assertFileExists(
            self::MODULE_PUBLIC . '/vendor/pdfjs/VERSION.md',
            'vendored version + license provenance is recorded',
        );
    }

    public function testPanelLoadsTheVendoredViewerNeverACdn(): void
    {
        $panel = self::panel();

        $this->assertStringContainsString('vendor/pdfjs/pdf.min.js', $panel);
        $this->assertStringContainsString('vendor/pdfjs/pdf.worker.min.js', $panel, 'workerSrc points at the vendored worker');
        $this->assertStringContainsString('pdfjsLib', $panel);
        $this->assertStringContainsString('getDocument', $panel);
        $this->assertStringNotContainsString('cdn.jsdelivr', $panel, 'self-contained: no CDN fallback');
        $this->assertStringNotContainsString('unpkg.com', $panel, 'self-contained: no CDN fallback');
    }

    public function testPreviewRendersTheSourcePageWithTheOverlay(): void
    {
        $panel = self::panel();

        $this->assertStringContainsString('document_base64', $panel, 'consumes the enriched document preview');
        $this->assertStringContainsString('bbox', $panel, 'reads the stored box from the preview');
        $this->assertStringContainsString('bbox-overlay', $panel, 'a dedicated overlay element positions the box over the rendered page');
        $this->assertStringContainsString('doc-preview', $panel, 'the document preview container exists');
    }

    public function testRegistersStayVisuallySeparate(): void
    {
        $panel = self::panel();

        $this->assertStringContainsString('Guideline evidence', $panel, 'guideline citations render under their own heading (TRO-34, re-pinned by TRO-44)');
        $this->assertStringContainsString('ref-detector', $panel);
        $this->assertStringContainsString('ref-guideline', $panel);
    }
}
