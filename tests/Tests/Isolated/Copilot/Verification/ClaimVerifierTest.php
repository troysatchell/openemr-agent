<?php

/**
 * FROZEN acceptance tests — T14: claim verification layer (R6/R10;
 * ARCHITECTURE.md §2 VER, §3.4 — "unattributable claims are not stated as
 * fact").
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: LLM output is untrusted draft prose until grounded.
 * A draft claim is GROUNDED only when every source token it cites resolves
 * against the live chart's reference index — all-or-nothing; a claim citing
 * one unresolvable source is rejected wholesale. Unattributable claims
 * (no sources, unknown sources) are surfaced as rejected, never silently
 * dropped and never presented as fact. Claim text passes through
 * byte-identical — the verifier grounds, it never rewrites. Resolution is
 * exact-match only: chart content is untrusted free text, so no fuzzy or
 * case-insensitive matching can be allowed to manufacture provenance.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Verification;

use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use OpenEMR\Modules\Copilot\Synthesis\AllergyEntry;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Synthesis\FollowUpEntry;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use OpenEMR\Modules\Copilot\Verification\ClaimVerifier;
use OpenEMR\Modules\Copilot\Verification\DraftClaim;
use OpenEMR\Modules\Copilot\Verification\GroundedClaim;
use OpenEMR\Modules\Copilot\Verification\ReferenceIndex;
use OpenEMR\Modules\Copilot\Verification\VerifiedAnswer;
use PHPUnit\Framework\TestCase;

class ClaimVerifierTest extends TestCase
{
    private static function chart(): ChartSnapshot
    {
        return (new ChartSnapshotSynthesizer())->synthesize(
            [new MedicationEntry('Warfarin 5mg Tablet', CurrencyStatus::Current, [new SourceRef('lists', 'med-warf')])],
            [new LabResultEntry(
                'Potassium',
                4.1,
                'mmol/L',
                new \DateTimeImmutable('2026-07-07 07:00:00'),
                [new SourceRef('procedure_result', 'lab-k')],
            )],
            [new AllergyEntry('Penicillin', CurrencyStatus::Current, [new SourceRef('lists', 'all-pcn')])],
            [new FollowUpEntry(
                'Repeat CBC',
                new \DateTimeImmutable('2026-06-30'),
                true,
                [new SourceRef('lists', 'fu-cbc')],
            )],
        );
    }

    private static function index(): ReferenceIndex
    {
        return ReferenceIndex::fromChart(self::chart());
    }

    public function testTokenFormatIsTheOneCanonicalMint(): void
    {
        $ref = new SourceRef('lists', 'med-warf');

        $this->assertSame(
            'lists:med-warf',
            ReferenceIndex::tokenFor($ref),
            'One deterministic token format (sourceType:sourceId) — the same mint the payload flattener must use.',
        );
    }

    public function testEverySectionOfTheChartIsResolvable(): void
    {
        $index = self::index();

        foreach (['lists:med-warf', 'procedure_result:lab-k', 'lists:all-pcn', 'lists:fu-cbc'] as $token) {
            $resolved = $index->resolve($token);
            $this->assertInstanceOf(SourceRef::class, $resolved, "Token {$token} must resolve — meds, labs, allergies, AND follow-ups are all citable.");
            $this->assertSame($token, ReferenceIndex::tokenFor($resolved));
        }
    }

    public function testFullyResolvedClaimIsGroundedWithProvenance(): void
    {
        $answer = (new ClaimVerifier())->verify(
            [new DraftClaim('On warfarin; potassium was normal last week.', ['lists:med-warf', 'procedure_result:lab-k'])],
            self::index(),
        );

        $this->assertCount(1, $answer->grounded);
        $this->assertSame([], $answer->rejected);

        $claim = $answer->grounded[0];
        $this->assertInstanceOf(GroundedClaim::class, $claim);
        $this->assertSame('On warfarin; potassium was normal last week.', $claim->text);
        $this->assertCount(2, $claim->sources);
        $this->assertSame('lists', $claim->sources[0]->sourceType);
        $this->assertSame('med-warf', $claim->sources[0]->sourceId);
    }

    public function testAClaimWithNoSourcesIsRejected(): void
    {
        $unattributable = new DraftClaim('The patient is doing well overall.', []);

        $answer = (new ClaimVerifier())->verify([$unattributable], self::index());

        $this->assertSame([], $answer->grounded, 'No source, no fact — unattributable claims are never stated as fact (R6/R10).');
        $this->assertSame([$unattributable], $answer->rejected, 'Rejected claims are surfaced, never silently dropped (R11).');
        $this->assertTrue($answer->hasUnverifiedContent());
    }

    public function testAClaimCitingAnUnknownTokenIsRejected(): void
    {
        $answer = (new ClaimVerifier())->verify(
            [new DraftClaim('Patient has a documented statin intolerance.', ['lists:med-nonexistent'])],
            self::index(),
        );

        $this->assertSame([], $answer->grounded, 'A citation the chart cannot back is a hallucination with a footnote.');
        $this->assertCount(1, $answer->rejected);
    }

    public function testPartialResolutionRejectsTheWholeClaim(): void
    {
        $answer = (new ClaimVerifier())->verify(
            [new DraftClaim('On warfarin for atrial fibrillation.', ['lists:med-warf', 'lists:cond-afib-invented'])],
            self::index(),
        );

        $this->assertSame(
            [],
            $answer->grounded,
            'All-or-nothing: one unresolvable source poisons the claim — partial grounding is not grounding (R6).',
        );
        $this->assertCount(1, $answer->rejected);
    }

    public function testResolutionIsExactMatchOnly(): void
    {
        $index = self::index();

        $this->assertNull($index->resolve('LISTS:MED-WARF'), 'No case folding — fuzzy matching manufactures provenance.');
        $this->assertNull($index->resolve(' lists:med-warf'), 'No trimming — chart text is untrusted (D1).');
        $this->assertNull($index->resolve('lists:med'), 'No prefix matching.');
    }

    public function testDuplicateTokensInOneClaimDedupeToOneSource(): void
    {
        $answer = (new ClaimVerifier())->verify(
            [new DraftClaim('Warfarin is current.', ['lists:med-warf', 'lists:med-warf'])],
            self::index(),
        );

        $this->assertCount(1, $answer->grounded);
        $this->assertCount(1, $answer->grounded[0]->sources, 'The same source cited twice is one source, not two.');
    }

    public function testClaimTextIsNeverAltered(): void
    {
        $text = "  Odd spacing — and a µ-symbol, quotes \"…\" kept verbatim.  ";

        $answer = (new ClaimVerifier())->verify(
            [new DraftClaim($text, ['lists:med-warf'])],
            self::index(),
        );

        $this->assertSame($text, $answer->grounded[0]->text, 'The verifier grounds; it never rewrites (the model owns prose, code owns truth).');
    }

    public function testMixedClaimsPartitionPreservingOrder(): void
    {
        $groundedA = new DraftClaim('Claim A.', ['lists:med-warf']);
        $rejectedB = new DraftClaim('Claim B.', []);
        $groundedC = new DraftClaim('Claim C.', ['lists:all-pcn']);
        $rejectedD = new DraftClaim('Claim D.', ['bogus:token']);

        $answer = (new ClaimVerifier())->verify([$groundedA, $rejectedB, $groundedC, $rejectedD], self::index());

        $this->assertSame(['Claim A.', 'Claim C.'], array_map(static fn (GroundedClaim $c): string => $c->text, $answer->grounded));
        $this->assertSame([$rejectedB, $rejectedD], $answer->rejected, 'Both buckets preserve draft order — no silent re-ranking.');
    }

    public function testNoClaimsMeansAnEmptyAnswerNotAnError(): void
    {
        $answer = (new ClaimVerifier())->verify([], self::index());

        $this->assertInstanceOf(VerifiedAnswer::class, $answer);
        $this->assertSame([], $answer->grounded);
        $this->assertSame([], $answer->rejected);
        $this->assertFalse($answer->hasUnverifiedContent());
    }

    public function testDraftClaimRefusesBlankText(): void
    {
        $this->expectException(\DomainException::class);
        new DraftClaim('   ', ['lists:med-warf']);
    }

    public function testGroundedClaimRefusesToExistWithoutProvenance(): void
    {
        $this->expectException(\DomainException::class);
        new GroundedClaim('A grounded claim with no ground.', []);
    }
}
