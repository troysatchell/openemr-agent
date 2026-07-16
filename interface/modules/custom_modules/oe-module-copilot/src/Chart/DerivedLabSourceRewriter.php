<?php

/**
 * Rewrites lineage-backed lab refs so citations ground in the source
 * document, never the derived pointer (W2_ARCHITECTURE.md §4; PS-6).
 *
 * The scoped write amendment's write (b) persists extracted analytes as
 * `procedure_result` rows the FHIR read path then serves back as ordinary
 * Observations — so the live chart mint labeled them `Observation:<uuid>`,
 * a token the source resolver can only answer with a bare chart pointer.
 * The citation contract says a derived observation is a pointer, never
 * evidence: its citation must resolve through the extraction lineage to
 * the source document (page, field, bbox) or it grounds nothing. This
 * class closes that gap at the mint: for every lab whose Observation uuid
 * has a real extraction-lineage row, the ref becomes
 * `derived_observation:<procedure_result_id>` — the token
 * SourceResolverEndpoint already resolves to the document preview the
 * panel's bbox overlay renders. Labs without lineage (interface feed,
 * manual entry) keep their chart refs untouched: chart-native facts ground
 * to the chart.
 *
 * One rewrite = at most ONE lookup call, batched over every Observation
 * uuid on the chart — never a query per lab, and no lookup at all when the
 * chart carries no Observation refs. The lookup is injected as a \Closure
 * so the isolated suite exercises the rewrite logic without a database;
 * forLiveLookup() binds the real lineage join.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Chart;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\Copilot\Persistence\ExtractionLineageSchema;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;

final readonly class DerivedLabSourceRewriter
{
    private const OBSERVATION_SOURCE_TYPE = 'Observation';

    private const DERIVED_SOURCE_TYPE = 'derived_observation';

    /**
     * @param \Closure(list<string>): array<string, int> $lineageResultIdsByUuid
     *        Maps FHIR Observation uuid strings to the `procedure_result_id`
     *        of rows carrying an extraction-lineage row; a uuid without
     *        lineage is simply absent from the returned map — never mapped
     *        to a guess.
     */
    public function __construct(private \Closure $lineageResultIdsByUuid)
    {
    }

    /**
     * Binds the real lineage join: Observation uuids are the uuids
     * DerivedObservationWriter registered on its `procedure_result` rows,
     * so one IN() query over the lineage join answers the whole chart.
     * Malformed uuid strings are skipped — an unmatchable key can never
     * match, and skipping it keeps the query shape valid. Every original
     * spelling of a uuid maps back from its normalized hex, so a
     * case-variant duplicate still grounds each ref that carried it.
     *
     * This is a module-owned-provenance read, not a chart read: the
     * lineage table exists only in this module (no FHIR surface can serve
     * it), the join returns ids alone — never clinical values — and it
     * executes inside the same guarded-route PhysicianContext boundary as
     * SourceResolverEndpoint's identical lineage joins. The chart content
     * this class rewrites still arrives exclusively via the FHIR
     * read-through path.
     */
    public static function forLiveLookup(): self
    {
        return new self(static function (array $uuids): array {
            $bytesByHex = [];
            $originalsByHex = [];
            foreach ($uuids as $uuid) {
                $hex = strtolower(str_replace('-', '', $uuid));
                if (strlen($hex) !== 32 || !ctype_xdigit($hex)) {
                    continue;
                }
                $bytes = hex2bin($hex);
                if ($bytes === false) {
                    continue;
                }
                $bytesByHex[$hex] = $bytes;
                $originalsByHex[$hex][] = $uuid;
            }
            if ($bytesByHex === []) {
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($bytesByHex), '?'));
            $rows = QueryUtils::fetchRecords(
                'SELECT LOWER(HEX(prr.uuid)) AS uuid_hex, prr.procedure_result_id AS rid'
                    . ' FROM procedure_result prr'
                    . ' JOIN ' . ExtractionLineageSchema::LINEAGE_TABLE . ' mcel'
                    . ' ON mcel.procedure_result_id = prr.procedure_result_id'
                    . ' WHERE prr.uuid IN (' . $placeholders . ')',
                array_values($bytesByHex),
            );

            $map = [];
            foreach ($rows as $row) {
                $hex = $row['uuid_hex'] ?? null;
                $rid = $row['rid'] ?? null;
                if (!is_string($hex) || !isset($originalsByHex[$hex])) {
                    continue;
                }
                if (!is_int($rid) && !(is_string($rid) && ctype_digit($rid))) {
                    continue;
                }
                foreach ($originalsByHex[$hex] as $original) {
                    $map[$original] = (int) $rid;
                }
            }

            return $map;
        });
    }

    /**
     * @param list<LabResultEntry> $labs
     *
     * @return list<LabResultEntry>
     */
    public function rewrite(array $labs): array
    {
        $uuids = [];
        foreach ($labs as $lab) {
            foreach ($lab->sources as $source) {
                if ($source->sourceType === self::OBSERVATION_SOURCE_TYPE) {
                    $uuids[$source->sourceId] = true;
                }
            }
        }
        if ($uuids === []) {
            return $labs;
        }

        $resultIdByUuid = ($this->lineageResultIdsByUuid)(array_keys($uuids));
        if ($resultIdByUuid === []) {
            return $labs;
        }

        $rewritten = [];
        foreach ($labs as $lab) {
            $changed = false;
            $sources = [];
            foreach ($lab->sources as $source) {
                $resultId = $source->sourceType === self::OBSERVATION_SOURCE_TYPE
                    ? ($resultIdByUuid[$source->sourceId] ?? null)
                    : null;
                if ($resultId === null) {
                    $sources[] = $source;
                    continue;
                }
                $sources[] = new SourceRef(self::DERIVED_SOURCE_TYPE, (string) $resultId);
                $changed = true;
            }
            $rewritten[] = $changed
                ? new LabResultEntry($lab->analyte, $lab->value, $lab->unit, $lab->resultedAt, $sources)
                : $lab;
        }

        return $rewritten;
    }
}
