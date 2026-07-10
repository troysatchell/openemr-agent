<?php

/**
 * Session-bound Clinical Co-Pilot panel (T21; UC1/UC2/UC3; AUDIT S4/S5;
 * ARCHITECTURE.md §4 session-bound delegation).
 *
 * This file requires interface/globals.php — the sanctioned module-page
 * pattern (cf. oe-module-faxsms/messageUI.php), used ONLY to bootstrap the
 * session/CSRF/ACL machinery this entry file needs. The no-globals.php
 * bright line (CLAUDE.md) targets CLI/batch patient reads (S4: the native
 * background path sets $ignoreAuth = true) — it is not a prohibition on the
 * standard logged-in-user session page bootstrap every module uses. This
 * page performs no patient read itself: it renders static chrome and a CSRF
 * token, then drives ajax.php (this module's session AJAX endpoint) for
 * every actual read, each gated again by SessionGate (S4/S5).
 *
 * Preserve-distrust UX, adapted from this module's token-based API-consumer
 * panel (public/panel.html) with the same visual language: must-not-miss
 * findings are visually loud and carry their citations; the unevaluable and
 * unknown-currency sections render honest uncertainty as content; grounded
 * claims show only the citations that SURVIVED verification; rejected
 * claims are explicitly unverified — never styled as fact; a degraded
 * response shows an honest banner and nothing else (never an empty "quiet"
 * rendering standing in for a failed read); a quiet result says so
 * explicitly (earned silence, R5) — never a blank.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;

require_once __DIR__ . '/../../../../globals.php';

if (!AclMain::aclCheckCore('patients', 'med')) {
    die("<h3>" . xlt("Not Authorised!") . "</h3>");
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$csrfToken = CsrfUtils::collectCsrfToken($session);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Clinical Co-Pilot'); ?></title>
<!--
  Clinical Co-Pilot session panel (T21; UC1/UC2/UC3; R5/R6/R10/R11).

  Talks ONLY to this module's own session AJAX endpoint (ajax.php), which in
  turn talks ONLY to the guarded service layer / FHIR read path — patient
  data never takes any other path from this page. See the file docblock
  above for the preserve-distrust UX this renderer implements.
-->
<style>
  :root { --ink:#1a1f24; --mut:#5a6572; --line:#d9dee4; --crit:#b3261e; --warn:#9a6a00; --ok:#1b6e3c; --rej:#6b7280; --bg:#f7f8fa; }
  * { box-sizing: border-box; }
  body { margin:0; font:15px/1.55 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color:var(--ink); background:var(--bg); }
  .wrap { max-width: 860px; margin: 0 auto; padding: 20px 16px 60px; }
  h1 { font-size: 19px; margin: 0 0 2px; }
  .sub { color: var(--mut); font-size: 13px; margin-bottom: 18px; }
  fieldset { border:1px solid var(--line); border-radius:8px; background:#fff; padding:12px 14px; margin:0 0 14px; }
  legend { font-size:12px; color:var(--mut); padding:0 6px; text-transform:uppercase; letter-spacing:.04em; }
  label { display:block; font-size:12px; color:var(--mut); margin:8px 0 2px; }
  select, textarea { width:100%; padding:8px 10px; border:1px solid var(--line); border-radius:6px; font:inherit; background:#fff; }
  textarea { resize: vertical; min-height: 64px; }
  button { margin-top:10px; padding:9px 18px; border:0; border-radius:6px; background:#20476b; color:#fff; font:inherit; font-weight:600; cursor:pointer; }
  button.secondary { background:#fff; color:#20476b; border:1px solid #20476b; }
  button:disabled { opacity:.5; cursor:wait; }
  .row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .row > * { margin-top:10px; }
  .banner { border-radius:8px; padding:10px 14px; margin:14px 0; font-size:14px; }
  .banner.degraded { background:#fdf3e0; border:1px solid #e3c27a; color:var(--warn); }
  .banner.error { background:#fbeaea; border:1px solid #e0a9a5; color:var(--crit); }
  .banner.quiet { background:#eef4ef; border:1px solid #bcd4c2; color:var(--ok); }
  section.zone { background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px 14px; margin:14px 0; }
  section.zone h2 { font-size:13px; margin:0 0 8px; text-transform:uppercase; letter-spacing:.05em; }
  .crit-item { border-left:5px solid var(--crit); background:#fdf5f5; padding:8px 12px; margin:8px 0; border-radius:0 6px 6px 0; }
  .crit-item .type { display:inline-block; font-size:11px; font-weight:700; color:#fff; background:var(--crit); border-radius:4px; padding:1px 7px; margin-right:8px; text-transform:uppercase; letter-spacing:.03em; }
  .crit-item .summary { font-weight:600; }
  .unev-item { border-left:5px solid #d8a83a; background:#fdf9ee; padding:8px 12px; margin:8px 0; border-radius:0 6px 6px 0; }
  .claim { padding:8px 12px; margin:8px 0; border:1px solid var(--line); border-radius:6px; background:#fff; }
  .claim.rejected { border-style:dashed; color:var(--rej); background:#f4f5f7; }
  .claim.rejected .flag { display:block; font-size:11px; font-weight:700; color:var(--rej); text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px; }
  .refs { margin-top:5px; }
  .ref { display:inline-block; font:11px/1.6 ui-monospace, SFMono-Regular, Menlo, monospace; background:#eef1f5; border:1px solid var(--line); border-radius:4px; padding:0 6px; margin:0 4px 3px 0; color:#33475c; }
  .corr { color:var(--mut); font:11px ui-monospace, SFMono-Regular, Menlo, monospace; margin-top:16px; }
  .hint { font-size:12px; color:var(--mut); margin-top:6px; }
  .status { font-size:12px; color:var(--crit); margin-top:6px; }
</style>
</head>
<body>
<div class="wrap">
  <h1><?php echo xlt('Clinical Co-Pilot'); ?></h1>
  <div class="sub"><?php echo xlt('Read-only orientation panel. Critical findings are code-detected and shown whatever the model says; model prose is shown only when grounded against the live chart.'); ?></div>

  <fieldset>
    <legend><?php echo xlt("Today's patients"); ?></legend>
    <div class="row">
      <div><?php echo xlt('Day'); ?>: <strong id="schedule-day">&mdash;</strong></div>
      <button type="button" class="secondary" id="refresh"><?php echo xlt('Refresh'); ?></button>
    </div>
    <label for="patient-select"><?php echo xlt('Patient'); ?></label>
    <select id="patient-select">
      <option value=""><?php echo xlt('Loading…'); ?></option>
    </select>
    <div class="status" id="schedule-status"></div>
  </fieldset>

  <div id="snapshot"></div>

  <fieldset>
    <legend><?php echo xlt('Ask about this patient'); ?></legend>
    <textarea id="question" placeholder="<?php echo xla('e.g. Anything new on the potassium trend? Is the anticoagulation current?'); ?>"></textarea>
    <button id="ask" disabled><?php echo xlt('Ask'); ?></button>
    <div class="hint"><?php echo xlt('Every turn re-reads the live chart. Prior turns inform phrasing only — never facts.'); ?></div>
  </fieldset>

  <div id="out"></div>
</div>

<script>
"use strict";
const CSRF_TOKEN = <?php echo js_escape($csrfToken); ?>;
const $ = (id) => document.getElementById(id);
const priorTurns = [];
let currentPatient = null;
// Keys of the findings the rendered snapshot already shows, so a turn can
// collapse identical re-checked findings to one line instead of repeating
// the cards (DESIGN.md: repetition is information; R13 stays visible).
let snapshotFindingKeys = new Set();
let snapshotUnevKeys = new Set();

function findingKey(type, text, refs) {
  return type + "|" + text + "|" + (refs || []).join(",");
}

// Working-state helper (DESIGN.md): a busy control swaps its label to the
// verb in progress — never a bare disable with unchanged text.
function setWorking(btn, workingLabel) {
  btn.dataset.idleLabel = btn.dataset.idleLabel || btn.textContent;
  btn.textContent = workingLabel;
  btn.disabled = true;
}
function setIdle(btn) {
  if (btn.dataset.idleLabel) btn.textContent = btn.dataset.idleLabel;
  btn.disabled = false;
}

function el(tag, cls, text) {
  const node = document.createElement(tag);
  if (cls) node.className = cls;
  if (text !== undefined) node.textContent = text;
  return node;
}

function refChips(refs) {
  const box = el("div", "refs");
  (refs || []).forEach((r) => box.appendChild(el("span", "ref", r)));
  return box;
}

async function post(action, payload) {
  const body = Object.assign({ action: action, csrf_token: CSRF_TOKEN }, payload || {});
  const resp = await fetch("ajax.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  let parsed = null;
  try {
    parsed = await resp.json();
  } catch (e) {
    parsed = null;
  }
  if (!resp.ok) {
    const message = (parsed && parsed.error) ? parsed.error : ("Request failed (HTTP " + resp.status + ").");
    throw new Error(message);
  }
  return parsed;
}

function renderSchedule(data) {
  $("schedule-day").textContent = data.day || "—";

  const select = $("patient-select");
  select.replaceChildren();
  const placeholder = document.createElement("option");
  placeholder.value = "";
  placeholder.textContent = "Select a patient…";
  select.appendChild(placeholder);

  (data.appointments || []).forEach((appt) => {
    const opt = document.createElement("option");
    const time = appt.time || "--:--";
    const status = appt.status || "(no status)";
    opt.textContent = time + " — " + appt.patient_name + " (" + status + ")";
    if (appt.selectable && appt.patient_uuid) {
      opt.value = appt.patient_uuid;
      opt.dataset.name = appt.patient_name;
    } else {
      // Never hidden: a non-selectable row (no pid/uuid resolvable, or out
      // of scope for a patient dropdown) still renders its honest label —
      // it is only disabled, not dropped (D1/D7).
      opt.value = "";
      opt.disabled = true;
    }
    select.appendChild(opt);
  });
}

async function loadSchedule() {
  const select = $("patient-select");
  const status = $("schedule-status");
  const refresh = $("refresh");
  status.textContent = "";
  select.disabled = true;
  select.replaceChildren(el("option", null, "Loading today's schedule…"));
  setWorking(refresh, "Loading…");
  try {
    const data = await post("schedule", {});
    renderSchedule(data);
  } catch (e) {
    select.replaceChildren(el("option", null, "Schedule unavailable"));
    status.textContent = "Could not load today's schedule: " + e.message;
  } finally {
    select.disabled = false;
    setIdle(refresh);
  }
}

function renderSnapshot(data) {
  const zone = $("snapshot");
  zone.replaceChildren();
  snapshotFindingKeys = new Set();
  snapshotUnevKeys = new Set();

  if (data.degraded) {
    // Degraded means the chart read itself failed — show ONLY the honest
    // banner, never an empty-quiet rendering standing in for a failed read
    // (R11).
    zone.appendChild(el("div", "banner degraded",
      "Unable to load this patient's snapshot right now. " + (data.degraded_reason || "")));
    return;
  }

  const snap = data.snapshot;
  if (!snap) {
    zone.appendChild(el("div", "banner error", "No snapshot available for this patient."));
    return;
  }

  if ((snap.must_not_miss || []).length) {
    const section = el("section", "zone");
    section.appendChild(el("h2", null, "Must not miss — detected in code, not by the model"));
    snap.must_not_miss.forEach((f) => {
      snapshotFindingKeys.add(findingKey(f.type, f.summary, f.refs));
      const item = el("div", "crit-item");
      item.appendChild(el("span", "type", f.type));
      item.appendChild(el("span", "summary", f.summary));
      item.appendChild(refChips(f.refs));
      section.appendChild(item);
    });
    zone.appendChild(section);
  }

  if ((snap.unevaluable || []).length) {
    const section = el("section", "zone");
    section.appendChild(el("h2", null, "Could not be evaluated — check manually"));
    snap.unevaluable.forEach((u) => {
      snapshotUnevKeys.add(findingKey("unev", u.reason, u.refs));
      const item = el("div", "unev-item");
      item.appendChild(el("div", null, u.reason));
      item.appendChild(refChips(u.refs));
      section.appendChild(item);
    });
    zone.appendChild(section);
  }

  if ((snap.unknown_currency || []).length) {
    const section = el("section", "zone");
    section.appendChild(el("h2", null, "Currency unknown — verify"));
    snap.unknown_currency.forEach((u) => {
      const item = el("div", "unev-item");
      item.appendChild(el("div", null, "(" + u.kind + ") " + u.name));
      item.appendChild(refChips(u.refs));
      section.appendChild(item);
    });
    zone.appendChild(section);
  }

  const labsSection = el("section", "zone");
  labsSection.appendChild(el("h2", null, "New labs since last visit"));
  if (snap.changes_basis === "unknown_no_last_visit") {
    labsSection.appendChild(el("div", "banner degraded",
      "No last-visit date on record — changes cannot be computed."));
  } else if ((snap.new_labs || []).length) {
    snap.new_labs.forEach((lab) => {
      const item = el("div", "claim");
      let label = lab.analyte + ": " + (lab.value === null || lab.value === undefined ? "(no value)" : lab.value);
      if (lab.unit) { label += " " + lab.unit; }
      if (lab.resulted_at) { label += " — " + lab.resulted_at; }
      item.appendChild(el("div", null, label));
      item.appendChild(refChips(lab.refs));
      labsSection.appendChild(item);
    });
  } else {
    labsSection.appendChild(el("div", "hint", "No new labs since the last visit."));
  }
  zone.appendChild(labsSection);

  const medsSection = el("section", "zone");
  medsSection.appendChild(el("h2", null, "Current medications"));
  if ((snap.current_medications || []).length) {
    snap.current_medications.forEach((m) => {
      const item = el("div", "claim");
      item.appendChild(el("div", null, m.name));
      item.appendChild(refChips(m.refs));
      medsSection.appendChild(item);
    });
  } else {
    medsSection.appendChild(el("div", "hint", "None on record."));
  }
  zone.appendChild(medsSection);

  const allergiesSection = el("section", "zone");
  allergiesSection.appendChild(el("h2", null, "Current allergies"));
  if ((snap.current_allergies || []).length) {
    snap.current_allergies.forEach((a) => {
      const item = el("div", "claim");
      item.appendChild(el("div", null, a.substance));
      item.appendChild(refChips(a.refs));
      allergiesSection.appendChild(item);
    });
  } else {
    allergiesSection.appendChild(el("div", "hint", "None on record."));
  }
  zone.appendChild(allergiesSection);

  if (snap.quiet === true) {
    zone.appendChild(el("div", "banner quiet",
      "Nothing to surface for this patient right now — no critical findings, nothing unevaluable, nothing of unknown currency, no new labs since the last visit. Silence here is a checked result, not an error."));
  }
}

async function loadSnapshot() {
  const zone = $("snapshot");
  const select = $("patient-select");
  const ask = $("ask");
  if (!currentPatient) {
    zone.replaceChildren();
    return;
  }
  zone.replaceChildren(el("div", "hint", "Reading the live chart…"));
  select.disabled = true;
  ask.disabled = true;
  try {
    const data = await post("snapshot", { patient_uuid: currentPatient.uuid });
    renderSnapshot(data);
  } catch (e) {
    zone.replaceChildren(el("div", "banner error", "Could not load the snapshot: " + e.message));
  } finally {
    select.disabled = false;
    ask.disabled = !currentPatient;
  }
}

function render(turn) {
  const out = $("out");
  out.replaceChildren();

  if (turn.degraded) {
    out.appendChild(el("div", "banner degraded",
      "Assistant unavailable — the findings below are code-detected and unaffected. " + (turn.degraded_reason || "")));
  }

  // The critical subset is re-detected on every turn (R13). Findings the
  // snapshot above already shows collapse to one line — repeating identical
  // cards trains the reader to skip them (DESIGN.md); a NEW finding still
  // renders full-size.
  if ((turn.must_not_miss || []).length) {
    const fresh = turn.must_not_miss.filter((f) => !snapshotFindingKeys.has(findingKey(f.type, f.summary, f.refs)));
    const unchanged = turn.must_not_miss.length - fresh.length;
    const zone = el("section", "zone");
    zone.appendChild(el("h2", null, fresh.length
      ? "Must not miss — detected in code, not by the model"
      : "Must not miss — re-checked this turn"));
    fresh.forEach((f) => {
      const item = el("div", "crit-item");
      item.appendChild(el("span", "type", f.type));
      item.appendChild(el("span", "summary", f.summary));
      item.appendChild(refChips(f.refs));
      zone.appendChild(item);
    });
    if (unchanged > 0) {
      zone.appendChild(el("div", "hint", fresh.length
        ? unchanged + " earlier finding(s) re-checked — unchanged (shown above)."
        : "Critical findings re-checked this turn — unchanged (shown in the snapshot above)."));
    }
    out.appendChild(zone);
  }

  if ((turn.unevaluable || []).length) {
    const fresh = turn.unevaluable.filter((u) => !snapshotUnevKeys.has(findingKey("unev", u.reason, u.refs)));
    const unchanged = turn.unevaluable.length - fresh.length;
    if (fresh.length || unchanged > 0) {
      const zone = el("section", "zone");
      zone.appendChild(el("h2", null, fresh.length
        ? "Could not be evaluated — check manually"
        : "Uncertainty — re-checked this turn"));
      fresh.forEach((u) => {
        const item = el("div", "unev-item");
        item.appendChild(el("div", null, u.reason));
        item.appendChild(refChips(u.refs));
        zone.appendChild(item);
      });
      if (unchanged > 0) {
        zone.appendChild(el("div", "hint", fresh.length
          ? unchanged + " earlier item(s) re-checked — unchanged (shown above)."
          : "Uncertainty items re-checked this turn — unchanged (shown in the snapshot above)."));
      }
      out.appendChild(zone);
    }
  }

  const grounded = turn.answer ? (turn.answer.grounded || []) : [];
  const rejected = turn.answer ? (turn.answer.rejected || []) : [];
  if (grounded.length || rejected.length) {
    const zone = el("section", "zone");
    zone.appendChild(el("h2", null, "Answer — each claim cites the chart record it came from"));
    grounded.forEach((c) => {
      const item = el("div", "claim");
      item.appendChild(el("div", null, c.text));
      item.appendChild(refChips(c.refs));
      zone.appendChild(item);
    });
    rejected.forEach((c) => {
      const item = el("div", "claim rejected");
      item.appendChild(el("span", "flag", "Unverified — could not be grounded against the chart; not shown as fact"));
      item.appendChild(el("div", null, c.text));
      zone.appendChild(item);
    });
    out.appendChild(zone);
  }

  const quiet = !turn.degraded
    && !(turn.must_not_miss || []).length
    && !(turn.unevaluable || []).length
    && !grounded.length && !rejected.length;
  if (quiet) {
    out.appendChild(el("div", "banner quiet",
      "Nothing to surface for this question — no critical findings, nothing unevaluable, no grounded statements. Silence here is a checked result, not an error."));
  }

  if (turn.correlation_id) {
    out.appendChild(el("div", "corr", "correlation id: " + turn.correlation_id + " (joins the trace and disclosure logs)"));
  }
}

async function ask() {
  const btn = $("ask");
  const out = $("out");
  const question = $("question").value.trim();
  if (!currentPatient) {
    out.replaceChildren(el("div", "banner error", "Select a patient from today's schedule first."));
    return;
  }
  if (!question) {
    out.replaceChildren(el("div", "banner error", "A question is required."));
    return;
  }
  setWorking(btn, "Asking…");
  out.replaceChildren(el("div", "hint", "Re-reading the live chart and grounding the answer — model prose is only shown when it cites a chart record…"));
  try {
    const turn = await post("turn", {
      patient_uuid: currentPatient.uuid,
      question: question,
      prior_turns: priorTurns.slice(-6),
    });
    render(turn);
    const groundedText = turn.answer && turn.answer.grounded ? turn.answer.grounded.map((c) => c.text).join(" ") : "(no grounded answer)";
    priorTurns.push("Q: " + question + " A: " + groundedText);
  } catch (e) {
    out.replaceChildren(el("div", "banner error", e.message || "Request failed."));
  } finally {
    setIdle(btn);
  }
}

$("patient-select").addEventListener("change", (e) => {
  const opt = e.target.selectedOptions[0];
  priorTurns.length = 0;
  $("out").replaceChildren();
  if (!opt || !opt.value) {
    currentPatient = null;
    $("ask").disabled = true;
    $("snapshot").replaceChildren();
    return;
  }
  currentPatient = { uuid: opt.value, name: opt.dataset.name || "" };
  $("ask").disabled = false;
  loadSnapshot();
});

$("refresh").addEventListener("click", loadSchedule);
$("ask").addEventListener("click", ask);

loadSchedule();
</script>
</body>
</html>
