<?php

/**
 * FROZEN acceptance tests — TRO-37 (three-tier stub seam: the CI job gains
 * the real DB) + TRO-42 (split clinical-accuracy-gate.yml + committed speed
 * budget + prek hook). PS-1 / PS-13; W2_ARCHITECTURE.md §7.
 *
 * Authored by the orchestrator and frozen: implementation agents make these
 * pass and MUST NOT modify this file. Contract under test — three committed
 * artifacts, string/regex-pinned (no YAML parser is a project dependency,
 * and the contract is about committed text, not runtime semantics):
 *
 *  1. `.github/workflows/clinical-accuracy-gate.yml` splits into exactly the
 *     two §7 jobs: the fast isolated contract gate stays DB-less, and an
 *     `eval-gate` job gains a REAL MariaDB 11.8 service (FULLTEXT + VECTOR —
 *     the version the module's vector schema requires), installs a real
 *     OpenEMR via `./cli install` (the integration-tests.yml pattern), runs
 *     the DB-backed copilot gate suite, carries a `timeout-minutes` budget,
 *     and defines NO vendor API keys — zero network by construction, the
 *     committed fixtures are the vendors.
 *  2. `W2_ARCHITECTURE.md` records the measured budget in a fixed, greppable
 *     form, and the number AGREES with the workflow's timeout — the budget
 *     is a committed fact, not a workflow-local tweak (PS-13: exceeding it
 *     is a regression; a gate slow enough to route around is not a gate).
 *  3. `.pre-commit-config.yaml` carries the PR-blocking Git-hook half of the
 *     MVP gate row: a `clinical-eval-gate` hook at the pre-push stage
 *     running the same gate suite in-container.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Eval;

use PHPUnit\Framework\TestCase;

class GateWorkflowContractTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../../../../..';
    private const WORKFLOW_PATH = self::REPO_ROOT . '/.github/workflows/clinical-accuracy-gate.yml';
    private const ARCHITECTURE_PATH = self::REPO_ROOT . '/W2_ARCHITECTURE.md';
    private const PRECOMMIT_PATH = self::REPO_ROOT . '/.pre-commit-config.yaml';

    /** The doc's budget line format — fixed so the contract stays greppable. */
    private const DOC_BUDGET_PATTERN = '/PR-blocking eval-gate job budget: (\d+) minutes/';

    private static function workflow(): string
    {
        $raw = file_get_contents(self::WORKFLOW_PATH);
        self::assertIsString($raw);

        return $raw;
    }

    /**
     * The eval-gate job block: from the `  eval-gate:` job key to EOF. The
     * job is required to be the last job in the file precisely so this
     * contract can scope assertions without a YAML parser.
     */
    private static function evalJobBlock(): string
    {
        $workflow = self::workflow();
        $position = strpos($workflow, "\n  eval-gate:");
        self::assertIsInt($position, 'the workflow must define an eval-gate job (and it must be the last job)');

        return substr($workflow, $position);
    }

    /** Everything before the eval-gate job: triggers + the isolated contract job. */
    private static function beforeEvalJob(): string
    {
        $workflow = self::workflow();
        $position = strpos($workflow, "\n  eval-gate:");
        self::assertIsInt($position);

        return substr($workflow, 0, $position);
    }

    public function testWorkflowSplitsIntoIsolatedAndEvalGateJobs(): void
    {
        $before = self::beforeEvalJob();

        $this->assertStringContainsString(
            'clinical-accuracy-gate:',
            $before,
            'the fast isolated contract job survives the split',
        );
        $this->assertStringContainsString(
            'phpunit-isolated.xml tests/Tests/Isolated/Copilot',
            $before,
            'the isolated job still runs the DB-less contract surface',
        );
        $this->assertStringNotContainsString(
            'services:',
            $before,
            'the isolated contract job stays DB-less — only the eval-gate job gains the DB service',
        );
    }

    public function testEvalGateJobRunsTheRealDbGate(): void
    {
        $evalJob = self::evalJobBlock();

        $this->assertStringContainsString('services:', $evalJob, 'the eval-gate job carries a DB service container');
        $this->assertStringContainsString(
            'mariadb:11.8',
            $evalJob,
            'the service is MariaDB 11.8 — the FULLTEXT + native VECTOR tier the module schema requires; '
                . 'an in-memory fake of vector search is exactly the kind of fake that lies',
        );
        $this->assertStringContainsString(
            './cli install',
            $evalJob,
            'a real OpenEMR schema is installed (the integration-tests.yml pattern), never a hand-rolled subset',
        );
        $this->assertStringContainsString(
            'phpunit.xml tests/Tests/Services/Copilot',
            $evalJob,
            'the eval-gate job runs the DB-backed copilot gate suite',
        );
    }

    public function testEvalGateJobDefinesNoVendorCredentials(): void
    {
        $workflow = self::workflow();

        $this->assertStringNotContainsString('COHERE_API_KEY', $workflow, 'zero network by construction — fixtures are the vendors');
        $this->assertStringNotContainsString('ANTHROPIC_API_KEY', $workflow, 'zero network by construction — fixtures are the vendors');
    }

    public function testGateTriggersCoverTheWorkingBranch(): void
    {
        $before = self::beforeEvalJob();

        $this->assertStringContainsString(
            'deploy/railway',
            $before,
            'the gate must block where the work lands — deploy/railway rides the push/PR triggers',
        );
    }

    public function testBudgetIsCommittedAndAgreesWithTheWorkflowTimeout(): void
    {
        $evalJob = self::evalJobBlock();
        $matchedWorkflow = preg_match('/timeout-minutes:\s*(\d+)/', $evalJob, $workflowMatch);
        $this->assertSame(1, $matchedWorkflow, 'the eval-gate job must carry a timeout-minutes budget');

        $doc = file_get_contents(self::ARCHITECTURE_PATH);
        $this->assertIsString($doc);
        $matchedDoc = preg_match(self::DOC_BUDGET_PATTERN, $doc, $docMatch);
        $this->assertSame(
            1,
            $matchedDoc,
            'W2_ARCHITECTURE.md must record the measured budget as "PR-blocking eval-gate job budget: N minutes" (PS-13)',
        );

        $this->assertSame(
            $docMatch[1],
            $workflowMatch[1],
            'the committed budget and the workflow timeout must agree — the doc is the record, the workflow enforces it',
        );
    }

    public function testPrekPrePushHookCarriesTheGate(): void
    {
        $config = file_get_contents(self::PRECOMMIT_PATH);
        $this->assertIsString($config);

        $this->assertStringContainsString('clinical-eval-gate', $config, 'the PR-blocking Git hook exists (MVP gate row)');
        $this->assertStringContainsString('pre-push', $config, 'the gate hook runs at the pre-push stage');
        $this->assertStringContainsString(
            'tests/Tests/Services/Copilot/Eval',
            $config,
            'the hook runs the same gate suite CI runs',
        );
    }
}
