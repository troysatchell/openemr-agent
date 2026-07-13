# Clinical-Guideline Corpus — Clinical Co-Pilot (Week 2)

Source of truth for the guideline corpus the `evidence-retriever` worker
searches (`W2_ARCHITECTURE.md` §5). This directory **is** the corpus: the
indexer builds from the document files beside this README, and the eval suite
points golden retrieval cases at the chunk IDs defined in them. Small,
curated, and scoped to Dr. Ellis Tran's practice (internal-medicine
follow-up: HTN, T2DM, lipids, anticoagulation — plus the office
critical-value response protocol added under the first governance widening).
It exists to answer **UC7**'s *"what evidence supports the recommendation?"*
(`USERS.md` §5 — UC7 extends UC2) and, through the conditional edge
(`W2_ARCHITECTURE.md` §6), to back **UC4**'s *"why is this flagged — and what
do we do?"* for critical findings — never to recall guideline content from
model weights.

Where this fits in the Week 2 deliverables: the RAG **design** is documented
in `W2_ARCHITECTURE.md` §5 (the named architecture deliverable); this
directory is the **corpus data** that §5 references, committed in-repo so the
index is reproducible from a clone alone (§11).

One document per file; `source_id` = filename stem. This layout is
deliberate: each license is a file-level property (a ShareAlike source, if
ever added, stays physically separate — §Licensing), the citation contract's
`source_id` has a physical referent for the click-to-source document preview,
and corpus widening — a clinical-governance event — arrives as a reviewable
new file, not an edit inside a monolith.

Note the persona linkage: `USERS.md` §11 open question 5 asks whether the
practice *has* agreed protocols in writing. This corpus is that question
answered **by stipulation** — the same founder-authored-archetype discipline
as the rest of the persona, carried with the same caveat.

---

## 1. Purpose and shape (why it's built this way)

The Week 2 spec (Stage 2) asks for *"agreed clinical practices the hospital/
office follows"* — a **local protocol library**, not a redistribution of
national guideline PDFs. Every gold-standard guideline for these domains
(ADA 2026, AHA/ACC 2025 HTN, 2023 AF, 2018 lipids) is all-rights-reserved; the
ADA license explicitly forbids text/data-mining and machine-learning use.
Committing that text and embedding it would violate copyright twice over.

So the corpus is in two layers:

- **Practice protocols** (`protocol-*.md`) — **authored** text (our repo
  license) that operationalizes each national guideline and carries a
  `derived_from` reference back to it. No copyrighted guideline prose is
  reproduced; clinical facts (thresholds, drug classes, targets) are not
  copyrightable.
- **Verbatim public-domain sources** (`uspstf-*.md`) — **USPSTF**
  recommendation text, which is U.S. federal task-force work in the public
  domain when taken from uspstf.gov (the JAMA versions carry separate AMA
  copyright and are not used).

This shape also buys two engineering properties the hard gate needs:

1. **Deterministic retrieval eval.** Golden retrieval cases assert
   *question → expected chunk IDs*. Because we author the chunks and assign
   **stable IDs**, those assertions don't flake when the index is rebuilt —
   which is what "reproducible from the repo alone" (`W2_ARCHITECTURE.md`
   §11) requires. A real guideline PDF re-chunks unpredictably and would
   break the gate.
2. **Checkable adjudication.** The corpus is founder-adjudicated with no
   clinician review (same limitation as the golden-chart labels — see
   `USERS.md` epistemic status). Every chunk's `derived_from` makes that
   adjudication *checkable against a named source* rather than freestanding.

---

## 2. Chunking convention (stable IDs)

The retriever splits on chunk markers, not on headings or file boundaries —
so a heading can be reworded without changing a chunk's identity (and without
breaking a golden case that points at it).

```markdown
<!-- chunk: htn.bp-target | source: protocol-htn-v1 | derived_from: AHA/ACC 2025 §Treatment Thresholds -->
#### Blood-pressure target
<chunk body...>
```

- `chunk` — the **stable ID**, `{domain}.{topic}`. This is the
  `field_or_chunk_id` in the `SourceRef` citation contract. **Never renumber;
  append only.**
- `source` — the parent document's `source_id`; MUST equal the filename stem
  of the file the marker lives in. Redundant with the filename by design:
  chunks stay self-describing when files are concatenated for indexing, and
  CI asserts the agreement.
- `derived_from` — the national-guideline reference this chunk
  operationalizes; populates a `derived_from` field on the retrieval citation
  so a snippet can be traced past our protocol to its source guideline.
- The chunk body runs to the next `<!-- chunk: -->` marker or end of file.

The **corpus index** (§3) is the machine-readable list of every document and
chunk ID; the indexer and eval enumerate valid IDs from it without parsing
prose.

**Citation shape this produces** — a retrieved snippet fills the Week 2
`SourceRef` (`{source_type, source_id, page_or_section, field_or_chunk_id,
quote_or_value}`): `source_type = guideline`, `source_id` = the chunk's
`source`, `page_or_section` = the chunk's heading, `field_or_chunk_id` = the
chunk ID, `quote_or_value` = the snippet. Guideline evidence renders under
its own heading in the answer — never interleaved with patient-record facts.

---

## 3. Corpus index (manifest)

Source of truth for **what documents and chunk IDs exist**. If a chunk ID
appears in a golden retrieval case, it must appear here.

### Documents

| source_id | file | source_type | license | provenance |
|---|---|---|---|---|
| `protocol-htn-v1` | `protocol-htn-v1.md` | practice_protocol | authored (repo license) | Derived from AHA/ACC 2025 HTN; WHO 2021 (CC BY-NC-SA); USPSTF HTN screening |
| `protocol-t2dm-v1` | `protocol-t2dm-v1.md` | practice_protocol | authored (repo license) | Derived from ADA 2026 (cited, not reproduced); USPSTF DM screening |
| `protocol-lipids-v1` | `protocol-lipids-v1.md` | practice_protocol | authored (repo license) | Derived from USPSTF 2022 statin; AHA/ACC 2025 (PREVENT) |
| `protocol-af-anticoag-v1` | `protocol-af-anticoag-v1.md` | practice_protocol | authored (repo license) | Derived from 2023 ACC/AHA/ACCP/HRS AF |
| `protocol-critical-values-v1` | `protocol-critical-values-v1.md` | practice_protocol | authored (repo license) | Derived from ARUP critical values (detector threshold basis); CLSI GP47; TJC NPSG.02.03.01; per-chunk analyte anchors |
| `uspstf-statin-2022` | `uspstf-statin-2022.md` | guideline_verbatim | Public domain (uspstf.gov) | USPSTF, Statin Use for Primary Prevention (2022) |
| `uspstf-dm-screening-2021` | `uspstf-dm-screening-2021.md` | guideline_verbatim | Public domain (uspstf.gov) | USPSTF, Prediabetes and T2DM Screening (2021) |

### Chunk inventory (33 chunks)

| chunk_id | source_id | section |
|---|---|---|
| `htn.diagnosis` | `protocol-htn-v1` | Diagnosis and confirmation |
| `htn.bp-target` | `protocol-htn-v1` | Blood-pressure target |
| `htn.risk-assessment` | `protocol-htn-v1` | CV risk assessment / when to start drugs |
| `htn.first-line-pharma` | `protocol-htn-v1` | First-line pharmacologic therapy |
| `htn.lifestyle` | `protocol-htn-v1` | Lifestyle modification |
| `htn.secondary-screening` | `protocol-htn-v1` | Screening for secondary causes |
| `t2dm.diagnosis` | `protocol-t2dm-v1` | Diagnostic criteria |
| `t2dm.screening` | `protocol-t2dm-v1` | Screening (asymptomatic adults) |
| `t2dm.glycemic-target` | `protocol-t2dm-v1` | Glycemic targets |
| `t2dm.pharma-first-line` | `protocol-t2dm-v1` | Pharmacologic therapy |
| `t2dm.technology` | `protocol-t2dm-v1` | Diabetes technology |
| `lipids.statin-primary-high` | `protocol-lipids-v1` | Statin — higher-risk (offer) |
| `lipids.statin-primary-intermediate` | `protocol-lipids-v1` | Statin — intermediate-risk (selectively offer) |
| `lipids.statin-elderly` | `protocol-lipids-v1` | Adults 76 and older |
| `lipids.risk-tools` | `protocol-lipids-v1` | Estimating 10-year CV risk |
| `af.anticoag-indication` | `protocol-af-anticoag-v1` | When to anticoagulate |
| `af.doac-vs-warfarin` | `protocol-af-anticoag-v1` | DOAC versus warfarin |
| `af.bleeding-risk` | `protocol-af-anticoag-v1` | Assessing bleeding risk |
| `af.renal-hepatic-monitoring` | `protocol-af-anticoag-v1` | Renal/hepatic monitoring and dosing |
| `af.device-detected` | `protocol-af-anticoag-v1` | Device-detected AHRE |
| `uspstf-statin.recommendation-high` | `uspstf-statin-2022` | Recommendation — risk ≥10% (B) |
| `uspstf-statin.recommendation-intermediate` | `uspstf-statin-2022` | Recommendation — risk 7.5–<10% (C) |
| `uspstf-statin.older-adults` | `uspstf-statin-2022` | Adults 76+ (I statement) |
| `uspstf-statin.population` | `uspstf-statin-2022` | Population and scope |
| `uspstf-dm.recommendation` | `uspstf-dm-screening-2021` | Recommendation (B) |
| `uspstf-dm.interval` | `uspstf-dm-screening-2021` | Screening interval |
| `uspstf-dm.higher-risk` | `uspstf-dm-screening-2021` | Earlier or expanded screening |
| `critical.response-general` | `protocol-critical-values-v1` | General response to any critical value |
| `critical.potassium` | `protocol-critical-values-v1` | Critical potassium |
| `critical.glucose` | `protocol-critical-values-v1` | Critical glucose |
| `critical.sodium` | `protocol-critical-values-v1` | Critical sodium |
| `critical.hemoglobin` | `protocol-critical-values-v1` | Critical hemoglobin |
| `critical.platelets` | `protocol-critical-values-v1` | Critical platelets |

---

## 4. Licensing detail

Three licensing tiers, kept separate so the provenance story is auditable —
and, with one file per document, each tier is a **file-level property**.

**Authored protocols (`protocol-*.md`) — repo license.** Our own text,
operationalizing national guidelines. Each cites its source guideline
(`derived_from`) but reproduces no copyrighted guideline prose. Clinical
facts (thresholds, drug classes, targets) are not copyrightable.

**Verbatim public-domain sources (`uspstf-*.md`) — public domain.** USPSTF
recommendation statements are U.S. federal task-force work, public domain
**when taken from uspstf.gov**. The *JAMA* versions carry separate AMA
copyright and are not used.

**Creative Commons (optional, not currently included) — CC BY-NC-SA 3.0
IGO.** WHO guidelines (e.g., the 2021 pharmacological-treatment-of-
hypertension guideline) are eligible to add as verbatim documents. Two
obligations if added: **NonCommercial** (fine for this admission project; a
real hospital deployment is commercial and would need separate WHO
permission — a deployment-checklist flag, not a blocker) and **ShareAlike**
(a WHO-derived document must itself be marked CC BY-NC-SA and kept as its own
data file separate from licensed code; no WHO logo, no implied endorsement).

**Not usable (why they're absent).** The most authoritative current
guidelines are all-rights-reserved and cannot be committed or embedded —
**ADA Standards of Care 2026** (license explicitly prohibits reproduction,
distribution, and text/data-mining or ML use without written permission), and
the **AHA/ACC 2025 HTN**, **2023 AF**, and **2018 cholesterol** guidelines
(standard Circulation/JACC copyright). All are cited via `derived_from`; none
are reproduced.

---

## 5. CI invariants (checkable)

- The indexer and CI scan **exactly the files listed in the §3 Documents
  table** (`file` column) — a manifest-driven whitelist, not an exclusion
  rule. A stray example marker anywhere else (this README's §2 illustration
  included) can never become a phantom chunk, and a renamed or missing
  manifest file fails loud instead of being silently skipped.
- Every `chunk_id` in the §3 inventory resolves to exactly one
  `<!-- chunk: -->` marker across those files, and vice versa (no orphan
  markers, no missing markers). A contract test asserts inventory ⇄ marker
  agreement — the same discipline the extraction schemas use.
- Every detector `CriticalFindingType` has an entry in the deterministic
  finding-type → chunk map, and every mapped chunk id exists in this
  manifest — the conditional edge can never fire into a missing or fuzzy
  target.
- Every chunk marker's `source` matches a `source_id` in the §3 documents
  table **and** the filename stem of the file it lives in.
- Every chunk marker carries a non-empty `derived_from`.
- The inventory's declared chunk count (currently **33**) matches the number
  of markers found.
- Numeric panic thresholds are **never restated** in
  `protocol-critical-values-v1` — threshold authority is the detector draft
  tables (one source of truth per data type, `W2_ARCHITECTURE.md` §10). A
  chunk describing critical-value *response* that acquires a numeric cutoff
  is a review flag.
- `chunk_id`s are **append-only**. Renumbering an existing ID is a breaking
  change to any golden case that references it; add a new ID instead.
- No `guideline_verbatim` document carries an all-rights-reserved license —
  verbatim documents are public-domain or CC only.

---

## 6. Scope discipline (what's deliberately out)

Curated small, not comprehensive — the corpus covers the *decision points a
follow-up visit actually turns on* for the four domains, not the full text of
any guideline. Out of scope by design: inpatient management, pediatrics,
pregnancy-specific dosing, procedural/interventional recommendations, and any
domain outside the persona's panel. **Widening the corpus is a
clinical-governance decision** (like widening the must-not-miss subset), not
a mechanical edit.

**Governance record — first widening (2026-07-13):**
`protocol-critical-values-v1` (office response to panic labs) was added with
the founder's explicit clinical-governance sign-off, closing the named gap
where the conditional edge (`W2_ARCHITECTURE.md` §6: extraction surfaces a
critical finding → retrieve supporting evidence) had **nothing** to retrieve
for panic-lab findings. The division of labor is deliberate: the **detector**
supplies the threshold and its ARUP provenance ("why flagged"); the
**protocol chunks** supply the practice's response ("what we do about it").
Numeric cutoffs are not restated in the protocol — threshold authority stays
with the detector draft tables (§5 invariant).

Two properties of this widening, named plainly:

- **Retrieval mode:** `critical.*` chunks are reached from the conditional
  edge by a **deterministic finding-type → chunk map**, never similarity
  ranking — panic labs demand exact-match retrieval (`W2_ARCHITECTURE.md`
  §6). They remain in the hybrid index for free-text asks ("what's our
  hypoglycemia protocol?"); the chronic chunks carry the hybrid-RAG
  deliverable via UC7.
- **Adjudication burden:** heaviest exactly here. Each chronic protocol
  traces to one primary guideline; the six `critical.*` chunks synthesize
  five source bodies (KDIGO, the hyponatremia expert panel, AABB, ASH, ADA)
  across the highest-stakes content in the corpus. **First in line for the
  clinician review pass** when one happens.

---

*Corpus for the Clinical Co-Pilot Week 2 multimodal evidence agent. The RAG
design that consumes it is `W2_ARCHITECTURE.md` §5; the user and use cases it
serves are `USERS.md` §5 (UC7, extending UC2). Founder-adjudicated, no
clinician review — every chunk's `derived_from` is the check against a named
national guideline until a clinician reviews the set (2026-07-13).*
