<?php

/**
 * FROZEN acceptance tests — TRO-39: the gate is proven red before it is
 * trusted (PS-3; W2_ARCHITECTURE.md §7 R-W5 — the grading acceptance test).
 *
 * Authored by the orchestrator and frozen: implementation agents make these
 * pass and MUST NOT modify this file. Contract under test: a synthetic
 * regression is COMMITTED as a patch fixture
 * (eval/goldenset/synthetic-regression.patch) alongside this meta-test,
 * which applies it to the working tree, runs the real gate as a subprocess,
 * and asserts the gate exits NONZERO with a regressed rubric category named
 * — then reverts and proves the tree byte-clean. The R-W5 defense is a
 * demonstration, not a claim: a 50-case suite that has never been seen to
 * fail is gate theater, and this is the exact bar the graders probe (they
 * inject a regression; the gate MUST fail).
 *
 * The patch must break PRODUCTION agent code — module src/ outside the
 * Eval\ harness — so the demonstration is "a regression in extraction
 * parsing / citation minting / retrieval / verification turns the gate
 * red" (TRO-37's acceptance language), never "the harness was broken to
 * fake a red". "Running on baseline stays green" is the sibling
 * GoldenSetGateTest in this same suite run.
 *
 * DB-BACKED and subprocess-based: runs in the same environment as the gate
 * itself (dev container / CI eval-gate job, real MariaDB, git available).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Copilot\Eval;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class GateRedProofTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../../../../..';
    private const PATCH_RELATIVE = 'interface/modules/custom_modules/oe-module-copilot/eval/goldenset/synthetic-regression.patch';

    private const CATEGORY_PATTERN =
        '/(schema_valid|citation_present|factually_consistent|safe_refusal|no_phi_in_logs|critical_subset)/';

    /** Production-code discipline: the patch may only touch module src/ OUTSIDE the Eval harness. */
    private const ALLOWED_TARGET_PATTERN =
        '#^interface/modules/custom_modules/oe-module-copilot/src/(?!Eval/)[A-Za-z0-9/._-]+\.php$#';

    private static function repoRoot(): string
    {
        $root = realpath(self::REPO_ROOT);
        self::assertIsString($root, 'repo root must resolve');

        return $root;
    }

    private static function patchPath(): string
    {
        return self::repoRoot() . '/' . self::PATCH_RELATIVE;
    }

    /**
     * @param list<string> $command
     */
    private static function runCommand(array $command): Process
    {
        $process = new Process($command, self::repoRoot());
        $process->setTimeout(600);
        $process->run();

        return $process;
    }

    /**
     * Target files parsed from the committed patch's `+++ b/...` lines.
     *
     * @return list<string>
     */
    private static function patchTargets(): array
    {
        $raw = file_get_contents(self::patchPath());
        self::assertIsString($raw);

        $matched = preg_match_all('#^\+\+\+ b/(.+)$#m', $raw, $matches);
        self::assertNotFalse($matched);
        self::assertGreaterThan(0, $matched, 'the patch must declare at least one target file');

        return $matches[1];
    }

    public function testSyntheticRegressionPatchIsCommittedAndTargetsProductionCodeOnly(): void
    {
        $this->assertFileExists(self::patchPath(), 'the synthetic regression is a committed artifact (PS-3)');

        foreach (self::patchTargets() as $target) {
            $this->assertMatchesRegularExpression(
                self::ALLOWED_TARGET_PATTERN,
                $target,
                'the synthetic regression must break production agent code — module src/ outside the Eval harness; '
                    . 'never tests, cases, fixtures, the baseline, or the harness itself (gate theater)',
            );
        }

        $check = self::runCommand(['git', 'apply', '--check', self::PATCH_RELATIVE]);
        $this->assertTrue(
            $check->isSuccessful(),
            'the committed patch must apply cleanly to the committed tree — context drift means the patch '
                . 'needs regeneration alongside the code it targets: ' . $check->getErrorOutput(),
        );
    }

    public function testGateGoesRedOnTheSyntheticRegressionAndNamesTheCategory(): void
    {
        foreach (self::patchTargets() as $target) {
            $dirty = self::runCommand(['git', 'diff', '--quiet', '--', $target]);
            $this->assertTrue(
                $dirty->isSuccessful(),
                "refusing to run: patch target {$target} already carries uncommitted changes",
            );
        }

        $apply = self::runCommand(['git', 'apply', self::PATCH_RELATIVE]);
        $this->assertTrue($apply->isSuccessful(), 'git apply failed: ' . $apply->getErrorOutput());

        try {
            $gate = self::runCommand([
                'php',
                'vendor/bin/phpunit',
                '-c',
                'phpunit.xml',
                'tests/Tests/Services/Copilot/Eval/GoldenSetGateTest.php',
            ]);
            $output = $gate->getOutput() . $gate->getErrorOutput();

            $this->assertNotSame(
                0,
                $gate->getExitCode(),
                "the gate MUST exit nonzero under the synthetic regression — a gate that stays green on broken "
                    . "production code is theater. Gate output:\n" . $output,
            );

            $this->assertMatchesRegularExpression(
                self::CATEGORY_PATTERN,
                $output,
                'the red gate must name the regressed rubric category in its output',
            );
        } finally {
            $revert = self::runCommand(['git', 'apply', '-R', self::PATCH_RELATIVE]);
        }

        $this->assertTrue($revert->isSuccessful(), 'git apply -R failed — working tree may be dirty: ' . $revert->getErrorOutput());

        foreach (self::patchTargets() as $target) {
            $clean = self::runCommand(['git', 'diff', '--quiet', '--', $target]);
            $this->assertTrue($clean->isSuccessful(), "patch target {$target} not byte-clean after revert");
        }
    }
}
