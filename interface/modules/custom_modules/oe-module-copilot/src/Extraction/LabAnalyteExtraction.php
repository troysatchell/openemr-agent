<?php

/**
 * A single analyte row extracted from a lab_pdf source document (W2_ARCHITECTURE §3).
 *
 * Carries test name, value, unit, reference range, and abnormal flag — each
 * an ExtractedField — plus a defensively-parsed collection date. Units are
 * REQUIRED, never inferred: a present value with an absent unit is a
 * \DomainException, because a unitless lab value is dangerous. An analyte
 * must carry a present test name. The collection date goes through
 * ClinicalDate (D0/D6); a non-blank but unparseable date is rejected, while
 * an absent date is simply unknown.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Extraction;

use OpenEMR\Modules\Copilot\DataTrust\ClinicalDate;

final readonly class LabAnalyteExtraction
{
    /**
     * D0: sql_mode='' lets MySQL store '0000-00-00' (with an all-zero time,
     * if any) as the RDBMS's own "no value recorded" sentinel — that is a
     * *known* unknown, not malformed input. Anything else ClinicalDate
     * cannot parse (free text, rollover dates, wrong formats) is a genuine
     * extraction defect and is rejected rather than silently swallowed.
     *
     * @var list<string>
     */
    private const KNOWN_UNKNOWN_DATE_MARKERS = ['0000-00-00', '0000-00-00 00:00:00'];

    public ?\DateTimeImmutable $collectionDate;

    public function __construct(
        public ExtractedField $testName,
        public ExtractedField $value,
        public ExtractedField $unit,
        public ExtractedField $referenceRange,
        public ExtractedField $abnormalFlag,
        ?string $collectionDateRaw = null,
    ) {
        if (!$testName->isPresent) {
            throw new \DomainException('LabAnalyteExtraction requires a present test name — an unnamed analyte cannot be reconciled (W2 §3)');
        }

        if ($value->isPresent && !$unit->isPresent) {
            throw new \DomainException('LabAnalyteExtraction rejects a present value with an absent unit — units are required, never inferred');
        }

        if ($collectionDateRaw !== null) {
            $trimmedDateRaw = trim($collectionDateRaw);
            $isKnownUnknownMarker = in_array($trimmedDateRaw, self::KNOWN_UNKNOWN_DATE_MARKERS, true);
            if ($trimmedDateRaw !== '' && !$isKnownUnknownMarker && ClinicalDate::tryParse($collectionDateRaw) === null) {
                throw new \DomainException('LabAnalyteExtraction rejects an unparseable collection date (D0/D6)');
            }
        }

        $this->collectionDate = ClinicalDate::tryParse($collectionDateRaw);
    }
}
