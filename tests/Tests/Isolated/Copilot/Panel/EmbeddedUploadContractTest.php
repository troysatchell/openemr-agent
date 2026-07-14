<?php

/**
 * FROZEN acceptance tests — demo-night slice of TRO-54 (founder-directed
 * 2026-07-14): document upload lives in the in-EMR session panel, reachable
 * like every other Co-Pilot action — no standalone URL, no bearer token, no
 * pasted patient UUID.
 *
 * Contract, string-pinned like the other committed-artifact contracts:
 * ajax.php gains an 'upload' action wired through the SAME session gate and
 * ACL pattern as 'turn', delegating to the existing DocumentUploadEndpoint
 * over Bootstrap::buildDocumentIngestion() (no new persistence semantics);
 * index.php gains the upload controls targeting the SELECTED patient, sends
 * the browser-reachable content-mode wire, and renders extraction_failed as
 * a failure state — never styled as success.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Panel;

use PHPUnit\Framework\TestCase;

class EmbeddedUploadContractTest extends TestCase
{
    private const PUBLIC_DIR = __DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-copilot/public';

    private static function read(string $file): string
    {
        $raw = file_get_contents(self::PUBLIC_DIR . '/' . $file);
        self::assertIsString($raw);

        return $raw;
    }

    public function testAjaxGainsAnUploadActionThroughTheExistingGuardPattern(): void
    {
        $ajax = self::read('ajax.php');

        $this->assertStringContainsString("case 'upload':", $ajax, 'upload is a first-class session-panel action');
        $this->assertStringContainsString('DocumentUploadEndpoint', $ajax, 'delegates to the existing shaped endpoint — no new upload semantics');
        $this->assertStringContainsString('buildDocumentIngestion', $ajax, 'composed from the same Bootstrap builder the REST route uses');
    }

    public function testEmbeddedPanelCarriesTheUploadControls(): void
    {
        $index = self::read('index.php');

        $this->assertStringContainsString('id="doc-type"', $index, 'doc-type selector exists');
        $this->assertStringContainsString('value="lab_pdf"', $index);
        $this->assertStringContainsString('value="intake_form"', $index);
        $this->assertStringContainsString('id="doc-file"', $index, 'file picker exists');
        $this->assertStringContainsString('id="upload-btn"', $index, 'upload trigger exists');
        $this->assertStringContainsString('file_content_b64', $index, 'sends the browser-reachable content-mode wire');
        $this->assertStringContainsString('post("upload"', $index, 'rides the same csrf-carrying post helper as every other action');
        $this->assertStringContainsString('extraction_failed', $index, 'failure state is branched on — extraction_failed never renders as success');
    }
}
