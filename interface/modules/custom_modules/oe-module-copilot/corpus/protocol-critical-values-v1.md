# `protocol-critical-values-v1` · Critical Laboratory Values — Office Response Protocol

- **source_type:** practice_protocol · **license:** authored (repo license)
- **adjudication:** founder-adjudicated, no clinician review (`USERS.md`); corpus widening approved by the acting clinical-governance owner 2026-07-13
- **last_reviewed:** 2026-07-13
- **derived_from:** ARUP Laboratories Critical Values List (CORP-APPEND-0104A — the detector threshold basis, `PHASE0.md` §3a); CLSI GP47 Management of Critical- and Significant-Risk Results; Joint Commission NPSG.02.03.01 (critical-result communication); analyte anchors per chunk (ADA Standards of Care §Hypoglycemia; KDIGO 2020 dyskalemia controversies conference; Hyponatremia Expert Panel Recommendations 2013; AABB Red Blood Cell Transfusion Guidelines 2023; ASH ITP Guidelines 2019)
- **scope:** the *initial office response* to a critical laboratory value in an outpatient internal-medicine practice — verification, communication, and disposition. Not definitive inpatient management. **Threshold authority:** the numeric panic cutoffs live in the co-pilot's detector draft tables (ARUP-derived, founder-signed) and are deliberately **not restated here** — one source of truth per data type (`W2_ARCHITECTURE.md` §10); this protocol governs what happens *after* a value meets them.

<!-- chunk: critical.response-general | source: protocol-critical-values-v1 | derived_from: CLSI GP47; Joint Commission NPSG.02.03.01 -->
#### General response to any critical value

Verify before acting when the value does not fit the clinical picture: order a
stat repeat where a spurious cause is plausible (hemolyzed sample for
potassium, prolonged tourniquet time, EDTA contamination, pseudothrombocytopenia)
— but **never delay an emergency response on a convincing critical value** to
wait for a re-draw. Communicate the result to the ordering clinician promptly;
telephone results are **read back** and acknowledged. Document the value, the
time, who was notified, and the action taken. Make an explicit disposition
decision at first contact — office management, same-day recheck, or emergency
department — based on symptoms, trajectory, and comorbidity. If the patient
cannot be reached, follow the escalating-contact procedure and document every
attempt.

<!-- chunk: critical.potassium | source: protocol-critical-values-v1 | derived_from: ARUP Critical Values (threshold basis); KDIGO 2020 dyskalemia conference -->
#### Critical potassium

**Hyperkalemia:** if hemolysis or sampling artifact is plausible and the
patient is asymptomatic, repeat stat — but obtain an **ECG promptly** for any
convincing critical potassium. ECG conduction changes, bradyarrhythmia, muscle
weakness, or a value far beyond threshold → **emergency department**. Review
contributors in parallel: ACE inhibitors/ARBs, potassium-sparing diuretics,
trimethoprim, NSAIDs, potassium supplements, and underlying CKD.
**Hypokalemia:** assess arrhythmia risk (highest with digoxin or underlying
cardiac disease), replete potassium — and magnesium, which hypokalemia
correction depends on — identify losses (diuretics, GI), and recheck on a
defined interval.

<!-- chunk: critical.glucose | source: protocol-critical-values-v1 | derived_from: ARUP Critical Values (threshold basis); ADA Standards of Care §Hypoglycemia -->
#### Critical glucose

**Hypoglycemia:** if the patient is conscious and able to swallow, give **15 g
of fast-acting carbohydrate, recheck in 15 minutes**, and repeat until
resolved; impaired consciousness is an **emergency** (glucagon if available,
EMS). Review the insulin or sulfonylurea regimen and renal function before the
patient leaves — a sulfonylurea-driven low recurs. **Severe hyperglycemia:**
assess for DKA/HHS — ketones, mental status, volume status, precipitating
illness. Any DKA/HHS concern → **emergency department**; otherwise same-day
management: hydration, medication adjustment, and early follow-up with a
defined recheck.

<!-- chunk: critical.sodium | source: protocol-critical-values-v1 | derived_from: ARUP Critical Values (threshold basis); Hyponatremia Expert Panel Recommendations 2013 -->
#### Critical sodium

**Severe hyponatremia** with neurologic symptoms — confusion, severe headache,
vomiting, seizure — is an **emergency department referral, immediately**. An
asymptomatic severe value still warrants urgent same-day evaluation: review
thiazides, SSRIs/SNRIs, and fluid status, and reassess the trend. Do **not**
attempt rapid outpatient correction — overly fast correction risks osmotic
demyelination, and correction-rate management is inpatient territory. Do not
start empiric fluid restriction or salt supplementation before the cause is
established.

<!-- chunk: critical.hemoglobin | source: protocol-critical-values-v1 | derived_from: ARUP Critical Values (threshold basis); AABB RBC Transfusion Guidelines 2023 -->
#### Critical hemoglobin

**Severe anemia** that is symptomatic — chest pain, dyspnea at rest, syncope,
hemodynamic signs — or accompanied by active bleeding → **emergency department
for transfusion evaluation** (restrictive transfusion thresholds inform the
decision; the clinical state governs it). If stable: urgent evaluation of the
source (GI loss, menstrual loss, marrow suppression), orthostatic vitals, and
a **review of anticoagulants and antiplatelets** — hold or adjust pending
evaluation where bleeding is suspected. Trend against prior values: an acute
drop is a different problem from a chronic plateau at the same number.

<!-- chunk: critical.platelets | source: protocol-critical-values-v1 | derived_from: ARUP Critical Values (threshold basis); ASH ITP Guidelines 2019 -->
#### Critical platelets

**Severe thrombocytopenia:** verify it is real — pseudothrombocytopenia from
EDTA clumping is excluded with a citrate-tube re-draw. Institute bleeding
precautions; **hold antiplatelets and anticoagulants pending urgent
evaluation**; defer elective procedures. Wet purpura, neurologic symptoms, or
fever with thrombocytopenia → **emergency department** — thrombotic
thrombocytopenic purpura is an emergency, not a referral. Otherwise urgent
hematology referral. **Extreme thrombocytosis:** assess thrombosis and
bleeding risk, screen for reactive causes (infection, inflammation, iron
deficiency), and refer urgently to hematology.
