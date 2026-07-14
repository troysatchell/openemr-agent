<?php

/**
 * FROZEN data contract — TRO-35: the 50-case Week 2 golden set is committed,
 * complete, and internally consistent (W2_ARCHITECTURE.md §7; eval/goldenset/README.md).
 *
 * Authored by the orchestrator and frozen WITH the case files themselves:
 * this test pins the adjudicated data — case counts, category distribution,
 * rubric totals (the comparator's quantization arithmetic depends on them),
 * schema shape, referential integrity against the corpus manifest, and the
 * presence of every armed golden case (TRO-29/31/34/23/40). Implementation
 * agents make the RUNNER pass; they MUST NOT modify this file or any case
 * file. A case bug is an orchestrator-owned re-freeze.
 *
 * This test is pure data validation (no runner involvement) and is GREEN at
 * freeze by design — it guards the frozen artifacts, not the implementation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Eval;

use JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

class GoldenSetDataContractTest extends TestCase
{
    private const MODULE_DIR = __DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-copilot';
    private const CASES_DIR = self::MODULE_DIR . '/eval/goldenset/cases';
    private const CORPUS_DIR = self::MODULE_DIR . '/corpus';
    private const SCHEMAS_DIR = self::MODULE_DIR . '/schemas/extraction';

    private const CASE_KEYS = ['id', 'kind', 'category', 'adjudicated', 'rubrics', '_guards_against', '_provenance', 'inputs', 'expected'];

    private const KINDS = ['extraction', 'retrieval', 'turn'];

    private const RUBRICS = ['schema_valid', 'citation_present', 'factually_consistent', 'safe_refusal', 'no_phi_in_logs'];

    /** Reporting-group distribution — a changed distribution is a reviewed re-freeze, never drift. */
    private const CATEGORY_COUNTS = [
        'extraction' => 10,
        'retrieval' => 10,
        'citation' => 8,
        'refusal' => 8,
        'missing_data' => 6,
        'composition' => 6,
        'injection' => 2,
    ];

    /**
     * Comparator-category totals. The gate's single-flip arithmetic
     * (eval/goldenset/README.md quantization table) is computed FROM these:
     * changing a total silently changes what a flip costs, so the numbers are
     * pinned here.
     */
    private const RUBRIC_TOTALS = [
        'schema_valid' => 15,
        'citation_present' => 13,
        'factually_consistent' => 39,
        'safe_refusal' => 13,
        'no_phi_in_logs' => 50,
    ];

    /** The armed golden cases named by their tickets — each must exist by exact id. */
    private const ARMED_CASE_IDS = [
        'composition-snapshot-zero-rag-with-finding',          // TRO-29
        'composition-prechart-zero-rag-evidence-flag-ignored', // TRO-29
        'composition-engaged-finding-fires-mapped-chunk',      // TRO-31
        'composition-unengaged-finding-never-fires',           // TRO-31 counterpart
        'composition-mixed-source-detector-guideline',         // TRO-34
        'refusal-no-grounding-by-proxy',                       // TRO-23
        'injection-vlm-embedded-instructions-inert',           // TRO-40 (a)
        'injection-extracted-field-steering-rejected',         // TRO-40 (b)
    ];

    /**
     * @return array<string, array<string, mixed>> caseId => decoded case
     */
    private static function loadAllCases(): array
    {
        $files = glob(self::CASES_DIR . '/*.json');
        self::assertIsArray($files);
        sort($files);

        $cases = [];
        foreach ($files as $file) {
            $raw = file_get_contents($file);
            self::assertIsString($raw, $file);
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, $file);

            /** @var array<string, mixed> $shaped */
            $shaped = [];
            foreach ($decoded as $key => $value) {
                self::assertIsString($key, $file);
                $shaped[$key] = $value;
            }

            $id = $shaped['id'] ?? null;
            self::assertIsString($id, $file);
            self::assertSame(basename($file, '.json'), $id, "case id must equal its filename stem: {$file}");
            self::assertArrayNotHasKey($id, $cases, "duplicate case id {$id}");
            $cases[$id] = $shaped;
        }

        return $cases;
    }

    /**
     * @return list<string> every stable chunk id declared by a corpus chunk marker
     */
    private static function corpusChunkIds(): array
    {
        $files = glob(self::CORPUS_DIR . '/*.md');
        self::assertIsArray($files);

        $ids = [];
        foreach ($files as $file) {
            if (basename($file) === 'README.md') {
                continue; // the README's §2 illustration is not a chunk (manifest-driven whitelist)
            }
            $text = file_get_contents($file);
            self::assertIsString($text, $file);
            $matched = preg_match_all('/<!--\s*chunk:\s*([a-z0-9.\-]+)\s*\|/', $text, $matches);
            self::assertNotFalse($matched, $file);
            foreach ($matches[1] as $chunkId) {
                $ids[] = $chunkId;
            }
        }

        self::assertNotSame([], $ids, 'corpus chunk markers must be discoverable');

        return $ids;
    }

    public function testExactlyFiftyCasesWithFrozenDistributionAndRubricTotals(): void
    {
        $cases = self::loadAllCases();
        $this->assertCount(50, $cases, 'the golden set is exactly 50 committed cases');

        $categoryCounts = [];
        $rubricTotals = [];
        foreach ($cases as $id => $case) {
            $category = $case['category'] ?? null;
            $this->assertIsString($category, $id);
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;

            $rubrics = $case['rubrics'] ?? null;
            $this->assertIsArray($rubrics, $id);
            foreach ($rubrics as $rubric) {
                $this->assertIsString($rubric, $id);
                $rubricTotals[$rubric] = ($rubricTotals[$rubric] ?? 0) + 1;
            }
        }

        ksort($categoryCounts);
        $expectedCategories = self::CATEGORY_COUNTS;
        ksort($expectedCategories);
        $this->assertSame($expectedCategories, $categoryCounts, 'category distribution is frozen');

        ksort($rubricTotals);
        $expectedTotals = self::RUBRIC_TOTALS;
        ksort($expectedTotals);
        $this->assertSame($expectedTotals, $rubricTotals, 'rubric totals are frozen — the quantization arithmetic depends on them');
    }

    public function testEveryCaseCarriesTheFrozenSchemaShape(): void
    {
        foreach (self::loadAllCases() as $id => $case) {
            $keys = array_keys($case);
            sort($keys);
            $expectedKeys = self::CASE_KEYS;
            sort($expectedKeys);
            $this->assertSame($expectedKeys, $keys, "case {$id} must carry exactly the schema keys");

            $this->assertTrue($case['adjudicated'] === true, "case {$id} must be adjudicated data");
            $this->assertContains($case['kind'], self::KINDS, $id);

            $guards = $case['_guards_against'];
            $this->assertIsString($guards, $id);
            $this->assertNotSame('', trim($guards), "case {$id} must name the failure mode it guards against (TRO-35 acceptance)");

            $provenance = $case['_provenance'];
            $this->assertIsString($provenance, $id);
            $this->assertNotSame('', trim($provenance), "case {$id} must state its ground-truth provenance");

            $rubrics = $case['rubrics'];
            $this->assertIsArray($rubrics, $id);
            $this->assertNotSame([], $rubrics, $id);
            $this->assertSame(array_values(array_unique($rubrics)), $rubrics, "case {$id} rubrics must be unique");
            foreach ($rubrics as $rubric) {
                $this->assertContains($rubric, self::RUBRICS, $id);
            }
            $this->assertContains('no_phi_in_logs', $rubrics, "case {$id}: every case is PHI-scanned");

            $this->assertIsArray($case['inputs'], $id);
            $this->assertNotSame([], $case['inputs'], $id);
            $this->assertIsArray($case['expected'], $id);
            $this->assertNotSame([], $case['expected'], $id);
        }
    }

    public function testKindSpecificInputContracts(): void
    {
        foreach (self::loadAllCases() as $id => $case) {
            $kind = $case['kind'];
            $this->assertIsString($kind, $id);
            $inputs = $case['inputs'];
            $this->assertIsArray($inputs, $id);
            $expected = $case['expected'];
            $this->assertIsArray($expected, $id);

            if ($kind === 'extraction') {
                $this->assertContains($inputs['doc_type'] ?? null, ['lab_pdf', 'intake_form'], $id);
                $this->assertIsString($inputs['filename'] ?? null, $id);
                $bytes = $inputs['document_bytes'] ?? null;
                $this->assertIsString($bytes, $id);
                $this->assertNotSame('', $bytes, $id);
                $this->assertIsArray($inputs['vlm_wire'] ?? null, $id);
                $this->assertContains($expected['extraction_status'] ?? null, ['extracted', 'extraction_failed'], $id);
                $this->assertTrue(($expected['document_attached'] ?? null) === true, "case {$id}: failure must leave the document attached");
            }

            if ($kind === 'retrieval') {
                $question = $inputs['question'] ?? null;
                $this->assertIsString($question, $id);
                $this->assertNotSame('', trim($question), $id);
                $topK = $inputs['top_k'] ?? null;
                $this->assertIsInt($topK, $id);
                $this->assertGreaterThanOrEqual(1, $topK, $id);
                $this->assertLessThanOrEqual(10, $topK, $id);
            }

            if ($kind === 'turn') {
                $state = $inputs['state'] ?? null;
                $this->assertIsArray($state, $id);
                foreach (
                    [
                    'is_snapshot_turn',
                    'has_pending_unextracted_document',
                    'question_asks_for_evidence',
                    'critical_finding_present',
                    'physician_engaged_critical_finding',
                    ] as $flag
                ) {
                    $this->assertIsBool($state[$flag] ?? null, "case {$id} state.{$flag}");
                }
                $this->assertFalse($state['has_pending_unextracted_document'], "case {$id}: the pending-doc turn path is TRO-32's frozen suite, not this set");
                if ($state['physician_engaged_critical_finding'] === true) {
                    $this->assertTrue($state['critical_finding_present'], "case {$id}: engagement requires a present finding");
                }
                $this->assertIsString($inputs['question'] ?? null, $id);
                $this->assertIsArray($inputs['chart'] ?? null, $id);
                $this->assertIsArray($inputs['draft_claims'] ?? null, $id);
            }
        }
    }

    public function testEveryReferencedChunkIdExistsInTheCorpusManifest(): void
    {
        $chunkIds = self::corpusChunkIds();

        foreach (self::loadAllCases() as $id => $case) {
            $inputs = $case['inputs'];
            $this->assertIsArray($inputs, $id);
            $expected = $case['expected'];
            $this->assertIsArray($expected, $id);

            $referenced = [];

            $aim = $inputs['fixture_aim_chunk_ids'] ?? [];
            $this->assertIsArray($aim, $id);
            foreach ($aim as $chunkId) {
                $referenced[] = $chunkId;
            }

            foreach (['chunk_ids_in_top_k', 'evidence_contains_chunk_ids'] as $key) {
                $list = $expected[$key] ?? [];
                $this->assertIsArray($list, $id);
                foreach ($list as $chunkId) {
                    $referenced[] = $chunkId;
                }
            }

            foreach (['top_chunk_id', 'mapped_chunk_id'] as $key) {
                if (($expected[$key] ?? null) !== null) {
                    $referenced[] = $expected[$key];
                }
            }

            $claims = $inputs['draft_claims'] ?? [];
            $this->assertIsArray($claims, $id);
            foreach ($claims as $claim) {
                $this->assertIsArray($claim, $id);
                $cites = $claim['cites'] ?? [];
                $this->assertIsArray($cites, $id);
                foreach ($cites as $cite) {
                    $this->assertIsArray($cite, $id);
                    if (($cite['source_type'] ?? null) === 'guideline') {
                        $referenced[] = $cite['field_or_chunk_id'] ?? null;
                    }
                }
            }

            foreach ($referenced as $chunkId) {
                $this->assertIsString($chunkId, "case {$id}: chunk references must be strings");
                $this->assertContains($chunkId, $chunkIds, "case {$id} references chunk '{$chunkId}' which is not in the corpus manifest");
            }
        }
    }

    public function testArmedGoldenCasesArePresent(): void
    {
        $cases = self::loadAllCases();
        foreach (self::ARMED_CASE_IDS as $armedId) {
            $this->assertArrayHasKey($armedId, $cases, "armed golden case {$armedId} must exist");
        }
    }

    public function testExtractionWiresValidateAgainstTheCommittedSchemasInBothDirections(): void
    {
        foreach (self::loadAllCases() as $id => $case) {
            if ($case['kind'] !== 'extraction') {
                continue;
            }
            $inputs = $case['inputs'];
            $this->assertIsArray($inputs, $id);
            $expected = $case['expected'];
            $this->assertIsArray($expected, $id);

            $docType = $inputs['doc_type'];
            $this->assertIsString($docType, $id);
            $schemaPath = self::SCHEMAS_DIR . '/' . $docType . '.schema.json';
            $this->assertFileExists($schemaPath, $id);

            // Round-trip through JSON so the validator sees stdClass objects.
            $wire = json_decode((string) json_encode($inputs['vlm_wire']), false, 512, JSON_THROW_ON_ERROR);
            $schemaRaw = file_get_contents($schemaPath);
            $this->assertIsString($schemaRaw, $id);
            $schema = json_decode($schemaRaw, false, 512, JSON_THROW_ON_ERROR);

            $validator = new Validator();
            $validator->validate($wire, $schema);

            if ($expected['extraction_status'] === 'extracted') {
                $this->assertTrue(
                    $validator->isValid(),
                    "case {$id}: a wire expected to extract must be schema-valid: " . (string) json_encode($validator->getErrors()),
                );
            } else {
                $this->assertFalse(
                    $validator->isValid(),
                    "case {$id}: a wire expected to whole-fail must actually violate the schema",
                );
            }
        }
    }

    public function testExtractionDocumentBytesAreUniqueAcrossTheSet(): void
    {
        $seen = [];
        foreach (self::loadAllCases() as $id => $case) {
            $inputs = $case['inputs'];
            $this->assertIsArray($inputs, $id);
            $bytes = $inputs['document_bytes'] ?? null;
            if (!is_string($bytes)) {
                continue;
            }
            $setup = $inputs['derived_setup'] ?? null;
            $this->assertArrayNotHasKey($bytes, $seen, "case {$id} reuses another case's document bytes — dedupe-by-hash would collide");
            $seen[$bytes] = $id;
            // derived_setup ingests too; its bytes ride a distinct suffix convention.
            if (is_array($setup) && isset($setup['document_bytes_suffix'])) {
                $this->assertIsString($setup['document_bytes_suffix'], $id);
            }
        }
    }
}
