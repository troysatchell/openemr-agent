<?php

/**
 * Contract for producing a fresh, delegated chart read (T12; UC2;
 * AUDIT S4/S6/D7/D9; ARCHITECTURE.md §2 ORCH, §3.5, §4).
 *
 * Abstracts the FHIR read, data-trust normalization, and one-pass
 * ChartSnapshot synthesis behind the delegated physician principal — every
 * call executes as the named physician, never a service account or the
 * native background path (S4/S6). The production, DB-backed adapter (FHIR
 * read + data-trust + synthesis wiring) is a separate ticket; this
 * orchestrator only depends on the seam.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Orchestration;

use OpenEMR\Modules\Copilot\Chart\PhysicianContext;

interface ChartSnapshotProvider
{
    /**
     * Every call reads fresh — never cached. Stale context is a wrong answer
     * waiting to happen (§3.5).
     */
    public function provide(PhysicianContext $physician, string $patientUuid): ProvidedChart;
}
