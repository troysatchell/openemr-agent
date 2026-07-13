<?php

/**
 * FROZEN acceptance tests — TRO-13: intake_form extraction DTO (W2_ARCHITECTURE §3).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: an intake-form extraction carries a chief concern plus
 * list-shaped current medications, allergies, family history, and demographics —
 * each entry an ExtractedField with its own per-group citation — tied to a
 * non-blank source document id. Absent groups are empty lists, never guessed
 * content; every list entry must be an ExtractedField.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Extraction;

use OpenEMR\Modules\Copilot\Extraction\ExtractedField;
use OpenEMR\Modules\Copilot\Extraction\ExtractionConfidence;
use OpenEMR\Modules\Copilot\Extraction\IntakeFormExtraction;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\TestCase;

class IntakeFormExtractionTest extends TestCase
{
    private function field(string $value): ExtractedField
    {
        return ExtractedField::present($value, new ExtractionConfidence(0.88), new SourceRef('intake_form', 'doc-7'));
    }

    private function extraction(string $documentId = 'doc-7'): IntakeFormExtraction
    {
        return new IntakeFormExtraction(
            $documentId,
            $this->field('chest pain'),
            [$this->field('metoprolol 50mg'), $this->field('lisinopril 10mg')],
            [$this->field('penicillin')],
            [$this->field('father: MI at 60')],
            [$this->field('DOB 1970-01-01')],
        );
    }

    public function testConstructsAndExposesFieldGroups(): void
    {
        $extraction = $this->extraction();

        $this->assertSame('doc-7', $extraction->documentId);
        $this->assertSame('chest pain', $extraction->chiefConcern->value);
        $this->assertCount(2, $extraction->currentMedications);
        $this->assertCount(1, $extraction->allergies);
        $this->assertCount(1, $extraction->familyHistory);
        $this->assertCount(1, $extraction->demographics);
    }

    public function testEmptyGroupsAreAllowed(): void
    {
        $extraction = new IntakeFormExtraction(
            'doc-7',
            ExtractedField::absent(),
            [],
            [],
            [],
            [],
        );

        $this->assertSame([], $extraction->currentMedications);
        $this->assertSame([], $extraction->allergies);
        $this->assertFalse($extraction->chiefConcern->isPresent);
    }

    public function testBlankDocumentIdIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->extraction('   ');
    }

    public function testNonExtractedFieldInAListIsRejected(): void
    {
        $this->expectException(\Throwable::class);
        /** @phpstan-ignore-next-line intentional bad input for the frozen guard */
        new IntakeFormExtraction(
            'doc-7',
            $this->field('chest pain'),
            ['not-an-extracted-field'],
            [],
            [],
            [],
        );
    }
}
