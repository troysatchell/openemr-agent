<?php

/**
 * FROZEN acceptance tests — TRO-35: the golden-set case loader
 * (eval/goldenset/README.md "Case schema"; W2_ARCHITECTURE.md §7).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and
 * frozen: implementation agents make these pass and MUST NOT modify this
 * file. Contract under test: Eval\GoldenSetCaseLoader parses the committed
 * case files into typed Eval\GoldenSetCase DTOs at the boundary
 * (parse-don't-validate) and FAILS LOUD on any file that violates the frozen
 * schema — a malformed case must never load as a half-case, and the loader
 * must refuse un-adjudicated data outright.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Eval;

use OpenEMR\Modules\Copilot\Eval\GoldenCaseKind;
use OpenEMR\Modules\Copilot\Eval\GoldenSetCase;
use OpenEMR\Modules\Copilot\Eval\GoldenSetCaseLoader;
use PHPUnit\Framework\TestCase;

class GoldenSetCaseLoaderTest extends TestCase
{
    private const COMMITTED_CASES_DIR = __DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-copilot/eval/goldenset/cases';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $files = glob($dir . '/*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }
    }

    private function tempCaseDir(): string
    {
        $dir = sys_get_temp_dir() . '/copilot-goldenset-' . uniqid('', true);
        mkdir($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * A minimal valid case body; tests mutate one aspect at a time.
     *
     * @return array<string, mixed>
     */
    private static function validCaseBody(string $id): array
    {
        return [
            'id' => $id,
            'kind' => 'retrieval',
            'category' => 'retrieval',
            'adjudicated' => true,
            'rubrics' => ['factually_consistent', 'no_phi_in_logs'],
            '_guards_against' => 'A named failure mode.',
            '_provenance' => 'Founder-adjudicated synthetic case.',
            'inputs' => ['question' => 'q', 'top_k' => 5],
            'expected' => ['chunk_ids_in_top_k' => []],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function writeCase(string $dir, string $filename, array $body): void
    {
        file_put_contents($dir . '/' . $filename, (string) json_encode($body, JSON_PRETTY_PRINT));
    }

    public function testLoadsTheCommittedGoldenSetAsFiftyTypedCases(): void
    {
        $cases = (new GoldenSetCaseLoader())->loadFromDirectory(self::COMMITTED_CASES_DIR);

        $this->assertCount(50, $cases);

        // Element type list<GoldenSetCase> is the loader's declared return —
        // enforced by PHPStan at the call sites below, not re-asserted here.
        $ids = array_map(static fn (GoldenSetCase $case): string => $case->id, $cases);
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'cases load in deterministic id order');
        $this->assertSame($ids, array_values(array_unique($ids)), 'ids are unique');
    }

    public function testTypedFieldsSurviveTheParse(): void
    {
        $cases = (new GoldenSetCaseLoader())->loadFromDirectory(self::COMMITTED_CASES_DIR);

        $byId = [];
        foreach ($cases as $case) {
            $byId[$case->id] = $case;
        }

        $this->assertArrayHasKey('composition-mixed-source-detector-guideline', $byId);
        $mixed = $byId['composition-mixed-source-detector-guideline'];
        $this->assertSame(GoldenCaseKind::Turn, $mixed->kind);
        $this->assertSame('composition', $mixed->category);
        $this->assertContains('citation_present', $mixed->rubrics);
        $this->assertContains('no_phi_in_logs', $mixed->rubrics);
        $this->assertNotSame('', trim($mixed->guardsAgainst));
        $this->assertNotSame('', trim($mixed->provenance));
        $this->assertIsArray($mixed->inputs['state'] ?? null);
        $this->assertIsArray($mixed->expected['plan_step_kinds'] ?? null);

        $this->assertArrayHasKey('extraction-lab-single-analyte-verbatim', $byId);
        $this->assertSame(GoldenCaseKind::Extraction, $byId['extraction-lab-single-analyte-verbatim']->kind);
        $this->assertArrayHasKey('retrieval-htn-bp-target', $byId);
        $this->assertSame(GoldenCaseKind::Retrieval, $byId['retrieval-htn-bp-target']->kind);
    }

    public function testMalformedJsonFailsLoud(): void
    {
        $dir = $this->tempCaseDir();
        file_put_contents($dir . '/broken.json', '{"id": "broken", ');

        $this->expectException(\JsonException::class);
        (new GoldenSetCaseLoader())->loadFromDirectory($dir);
    }

    public function testIdMustMatchFilenameStem(): void
    {
        $dir = $this->tempCaseDir();
        $this->writeCase($dir, 'some-other-name.json', self::validCaseBody('mismatched-id'));

        $this->expectException(\DomainException::class);
        (new GoldenSetCaseLoader())->loadFromDirectory($dir);
    }

    public function testUnknownKindIsRefused(): void
    {
        $dir = $this->tempCaseDir();
        $body = self::validCaseBody('unknown-kind');
        $body['kind'] = 'oracle';
        $this->writeCase($dir, 'unknown-kind.json', $body);

        $this->expectException(\DomainException::class);
        (new GoldenSetCaseLoader())->loadFromDirectory($dir);
    }

    public function testUnknownRubricIsRefused(): void
    {
        $dir = $this->tempCaseDir();
        $body = self::validCaseBody('unknown-rubric');
        $body['rubrics'] = ['vibes', 'no_phi_in_logs'];
        $this->writeCase($dir, 'unknown-rubric.json', $body);

        $this->expectException(\DomainException::class);
        (new GoldenSetCaseLoader())->loadFromDirectory($dir);
    }

    public function testMissingPhiRubricIsRefused(): void
    {
        $dir = $this->tempCaseDir();
        $body = self::validCaseBody('no-phi-rubric');
        $body['rubrics'] = ['factually_consistent'];
        $this->writeCase($dir, 'no-phi-rubric.json', $body);

        $this->expectException(\DomainException::class);
        (new GoldenSetCaseLoader())->loadFromDirectory($dir);
    }

    public function testUnadjudicatedDataIsRefusedOutright(): void
    {
        $dir = $this->tempCaseDir();
        $body = self::validCaseBody('not-adjudicated');
        $body['adjudicated'] = false;
        $this->writeCase($dir, 'not-adjudicated.json', $body);

        $this->expectException(\DomainException::class);
        (new GoldenSetCaseLoader())->loadFromDirectory($dir);
    }

    public function testBlankGuardsAgainstIsRefused(): void
    {
        $dir = $this->tempCaseDir();
        $body = self::validCaseBody('blank-guards');
        $body['_guards_against'] = '   ';
        $this->writeCase($dir, 'blank-guards.json', $body);

        $this->expectException(\DomainException::class);
        (new GoldenSetCaseLoader())->loadFromDirectory($dir);
    }

    public function testEmptyDirectoryIsRefusedNeverAnEmptyGate(): void
    {
        $dir = $this->tempCaseDir();

        $this->expectException(\DomainException::class);
        (new GoldenSetCaseLoader())->loadFromDirectory($dir);
    }
}
