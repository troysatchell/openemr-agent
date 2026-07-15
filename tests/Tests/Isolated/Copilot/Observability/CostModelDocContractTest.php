<?php

/**
 * FROZEN acceptance tests — TRO-46 (projection side): the committed cost
 * model separates the two scaling curves and owns its behavioral assumption.
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract, string-pinned on the committed document: docs/COST_MODEL.md at
 * the module root names the four coexisting vendor price models, separates
 * the per-document curve from the per-question curve, and states — for each
 * of the four projection tiers (100 / 1K / 10K / 100K encounters) — its
 * explicit Q/encounter assumption and its architectural inflection. This is
 * the guard against the token-multiplication projection the spec rejects.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Observability;

use PHPUnit\Framework\TestCase;

class CostModelDocContractTest extends TestCase
{
    private const DOC_PATH = __DIR__
        . '/../../../../../interface/modules/custom_modules/oe-module-copilot/docs/COST_MODEL.md';

    private static function doc(): string
    {
        self::assertFileExists(self::DOC_PATH, 'the cost model is a committed artifact, not a dashboard afterthought');
        $raw = file_get_contents(self::DOC_PATH);
        self::assertIsString($raw);

        return $raw;
    }

    public function testTheFourVendorPriceModelsAreNamed(): void
    {
        $doc = self::doc();

        foreach (['vision', 'text', 'embed', 'rerank'] as $model) {
            $this->assertStringContainsStringIgnoringCase($model, $doc, "the {$model} price model is accounted for");
        }
    }

    public function testTheTwoScalingCurvesAreSeparated(): void
    {
        $doc = self::doc();

        $this->assertStringContainsString('per-document', $doc, 'extraction scales with document volume');
        $this->assertStringContainsString('per-question', $doc, 'retrieval + answer scales with question volume');
        $this->assertStringContainsString('Q/encounter', $doc, 'questions-per-encounter is an explicit behavioral variable');
    }

    public function testEveryProjectionTierOwnsItsAssumptionAndInflection(): void
    {
        $doc = self::doc();

        foreach (['100 ', '1K', '10K', '100K'] as $tier) {
            $this->assertStringContainsString($tier, $doc, "the {$tier} encounters tier exists");
        }

        $qAssumptions = substr_count($doc, 'Q/encounter');
        $this->assertGreaterThanOrEqual(4, $qAssumptions, 'each of the four tiers states its own Q/encounter assumption');

        $inflections = substr_count(strtolower($doc), 'inflection');
        $this->assertGreaterThanOrEqual(4, $inflections, 'each tier names its architectural inflection');
    }
}
