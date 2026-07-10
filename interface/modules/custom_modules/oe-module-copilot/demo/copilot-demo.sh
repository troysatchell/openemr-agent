#!/bin/sh
# Clinical Co-Pilot — one-command demo setup against a deployed OpenEMR.
#
# Usage:
#   OE_PASS='<admin password>' sh copilot-demo.sh          # full flow
#   BASE='https://your-host' OE_USER=admin OE_PASS=... sh copilot-demo.sh
#   sh copilot-demo.sh token                                # just a fresh token
#   sh copilot-demo.sh provider                             # resolve/record the demo physician's numeric id
#   sh copilot-demo.sh schedule                             # seed today's 3-patient schedule
#   sh copilot-demo.sh turn                                 # just re-run the smoke turn
#
# Prerequisites (one-time, in the OpenEMR UI — see demo/DEMO.md):
#   1. Module enabled: Modules -> Manage Modules -> oe-module-copilot
#      (Register -> Install -> Enable).
#   2. Admin -> Globals -> Connectors: Standard REST API ON, FHIR REST API ON,
#      OAuth2 Password Grant ON.
#   3. When this script registers its OAuth client it will PAUSE and ask you
#      to enable the client (Admin -> System -> API Clients) — one click.
#
# State (client credentials, patient uuid) is stored OUTSIDE the repo in
# ~/.copilot-demo/ so nothing secret can be committed. Demo data is synthetic.

set -eu
BASE="${BASE:-https://openemr-production-4eba.up.railway.app}"
OE_USER="${OE_USER:-admin}"
STATE="${HOME}/.copilot-demo"
mkdir -p "$STATE"
STEP="${1:-all}"

# Pull OE_PASS from Railway if not supplied and the CLI is available.
if [ -z "${OE_PASS:-}" ] && command -v railway >/dev/null 2>&1; then
    OE_PASS="$(railway variables --service openemr --json 2>/dev/null \
        | python3 -c 'import json,sys; print(json.load(sys.stdin).get("OE_PASS",""))' || true)"
fi
[ -n "${OE_PASS:-}" ] || { echo "ERROR: set OE_PASS (admin password)"; exit 1; }
export BASE OE_USER OE_PASS STATE

py() { python3 - "$@"; }

register() {
    [ -f "$STATE/client.json" ] && { echo "client already registered ($STATE/client.json)"; return; }
    # NOTE: if $STATE/client.json was registered before user/appointment.write,
    # user/facility.read, and user/user.read were added below (T21: the
    # in-EMR panel's provider/schedule demo steps), delete it and
    # re-register — an OAuth client's granted scope set is fixed at
    # registration time and does not pick up new scopes retroactively.
    py <<'PY'
import json, os, subprocess, sys, time
base, state = os.environ['BASE'], os.environ['STATE']
body = json.dumps({
  "application_type": "private",
  "client_name": f"copilot-demo-{int(time.time())}",
  "token_endpoint_auth_method": "client_secret_post",
  "redirect_uris": [base],
  "scope": "openid offline_access api:oemr api:fhir "
           "user/patient.read user/patient.write user/medication.read user/medication.write "
           "user/allergy.read user/allergy.write user/Patient.read user/Observation.read "
           "user/MedicationRequest.read user/AllergyIntolerance.read "
           "user/appointment.write user/facility.read user/user.read "
           "user/encounter.read user/encounter.write "
           "user/ping.read user/health.read user/ready.read user/turn.write"})
r = subprocess.run(['curl','-s','-X','POST',f'{base}/oauth2/default/registration',
    '-H','Content-Type: application/json','-d',body], capture_output=True, text=True)
d = json.loads(r.stdout)
if 'client_id' not in d:
    sys.exit(f"registration failed: {d}")
open(f'{state}/client.json','w').write(r.stdout)
print(f"registered client: {d['client_name']}")
PY
    echo ""
    echo ">>> ACTION: in OpenEMR go to Administration -> System -> API Clients,"
    echo ">>> find the client above, tick Enabled, Save. Then press Enter."
    read -r _
}

token() {
    py <<'PY'
import json, os, subprocess, sys, urllib.parse
base, state = os.environ['BASE'], os.environ['STATE']
c = json.load(open(f'{state}/client.json'))
body = urllib.parse.urlencode({
  'grant_type':'password','client_id':c['client_id'],'client_secret':c['client_secret'],
  'user_role':'users','username':os.environ['OE_USER'],'password':os.environ['OE_PASS'],
  'scope':('openid api:oemr api:fhir user/patient.read user/patient.write '
           'user/medication.read user/medication.write user/allergy.read user/allergy.write '
           'user/Patient.read user/MedicationRequest.read user/AllergyIntolerance.read '
           'user/appointment.write user/facility.read user/user.read '
           'user/encounter.read user/encounter.write '
           'user/health.read user/ready.read user/turn.write')})
r = subprocess.run(['curl','-s','-X','POST',f'{base}/oauth2/default/token',
    '-H','Content-Type: application/x-www-form-urlencoded','-d',body],
    capture_output=True, text=True)
d = json.loads(r.stdout)
if 'access_token' not in d:
    sys.exit(f"token failed (is the client Enabled?): { {k:v for k,v in d.items() if 'token' not in k} }")
open(f'{state}/token.json','w').write(r.stdout)
print("token OK (1h) ->", f'{state}/token.json')
PY
}

seed() {
    [ -f "$STATE/patient.json" ] && { echo "demo patient already seeded ($STATE/patient.json)"; return; }
    py <<'PY'
import json, os, subprocess, sys
base, state = os.environ['BASE'], os.environ['STATE']
tok = json.load(open(f'{state}/token.json'))['access_token']
def call(method, path, payload=None):
    args = ['curl','-s','-X',method,f'{base}/apis/default{path}',
            '-H',f'Authorization: Bearer {tok}','-H','Content-Type: application/json']
    if payload is not None: args += ['-d', json.dumps(payload)]
    return json.loads(subprocess.run(args, capture_output=True, text=True).stdout)
d = call('POST','/api/patient',{'fname':'Alma','lname':'Reyes','DOB':'1961-03-14','sex':'Female'})
pid, uuid = d['data']['pid'], d['data']['uuid']
print(f"patient created pid={pid} uuid={uuid}")
for m in ['Warfarin 5mg Tablet','Aspirin 81mg','Amoxicillin 500mg Capsule']:
    call('POST',f'/api/patient/{pid}/medication',{'title':m,'begdate':'2026-06-01 00:00:00'})
    print(f"  med: {m}")
call('POST',f'/api/patient/{uuid}/allergy',{'title':'Penicillin','begdate':'2020-01-01'})
print("  allergy: Penicillin (title-only — see DEMO.md note on coded allergies)")
open(f'{state}/patient.json','w').write(json.dumps({'pid':pid,'uuid':uuid}))
PY
}

# Resolves (or records) the demo physician's numeric users.id — needed as
# pc_aid on the seeded appointments and, in the in-EMR panel, as the
# authenticated session's authUserID. Physician account creation itself is a
# manual UI step (Administration -> Users -> New User): this script only
# reads the result back afterwards, via the guarded REST API with the admin
# token, never a raw table read. Resolution is attempted FIRST — if the
# physician already exists (idempotent reruns, or you created the user ahead
# of time), the creation pause is skipped entirely and DR_PASS is not needed.
provider() {
    [ -f "$STATE/provider.json" ] && { echo "provider already resolved ($STATE/provider.json)"; return; }
    DR_USER="${DR_USER:-dr.tran}"
    export DR_USER

    match="$(resolve_provider_id)"
    if [ "${match#OK }" = "$match" ]; then
        # Not resolvable yet — walk through creating the user, then retry.
        : "${DR_PASS:?set DR_PASS (the demo physician login password) before running the provider step -- never hardcoded, never echoed}"
        echo ""
        echo ">>> ACTION: in OpenEMR go to Administration -> Users -> New User and create the"
        echo ">>> demo physician: username '${DR_USER}', password from \$DR_PASS (type it into the"
        echo ">>> form yourself — this script never echoes it), name Ellis / Tran, check the"
        echo ">>> Provider and Calendar boxes, Access Control 'Physicians', Save. Then press Enter."
        read -r _
        match="$(resolve_provider_id)"
    fi

    case "$match" in
        "OK "*)
            provider_id="${match#OK }"
            python3 -c 'import json,sys; open(sys.argv[1],"w").write(json.dumps({"id": int(sys.argv[2]), "username": sys.argv[3]}))' \
                "$STATE/provider.json" "$provider_id" "$DR_USER"
            echo "provider resolved: id=$provider_id username=$DR_USER"
            ;;
        *)
            echo ""
            echo "Could not uniquely resolve ${DR_USER}'s numeric users.id from GET /api/user."
            echo "(If rows_seen=0 above: your OAuth client predates the user/user.read scope —"
            echo " delete $STATE/client.json and rerun the register + token steps.)"
            echo "Or look it up yourself: Administration -> Users -> edit the user; the numeric"
            echo "id is shown in the edit page's URL (...&id=<N>)."
            printf "Enter the numeric users.id: "
            read -r provider_id
            case "$provider_id" in
                ''|*[!0-9]*) echo "ERROR: not a positive integer: '$provider_id'"; exit 1 ;;
            esac
            python3 -c 'import json,sys; open(sys.argv[1],"w").write(json.dumps({"id": int(sys.argv[2]), "username": sys.argv[3]}))' \
                "$STATE/provider.json" "$provider_id" "$DR_USER"
            echo "provider recorded: id=$provider_id username=$DR_USER"
            ;;
    esac
}

# GET /api/user exposes the numeric users.id (PractitionerRestController's
# /api/practitioner does not — see src/RestControllers/UserRestController.php
# WHITELISTED_FIELDS vs PractitionerRestController's). Filter client-side
# by username, falling back to first/last name, since UserService only
# includes 'username' in the response when explicitly requested.
# Prints "OK <id>" on a unique match; "AMBIGUOUS" otherwise.
resolve_provider_id() {
    py <<'PY'
import json, os, subprocess, sys
base, state = os.environ['BASE'], os.environ['STATE']
dr_user = os.environ['DR_USER']
tok = json.load(open(f'{state}/token.json'))['access_token']
r = subprocess.run(['curl','-s','-X','GET',f'{base}/apis/default/api/user',
    '-H',f'Authorization: Bearer {tok}'], capture_output=True, text=True)
try:
    d = json.loads(r.stdout)
except json.JSONDecodeError:
    d = {}
rows = d.get('data') or []
if isinstance(rows, dict):
    rows = [rows]

def is_match(row):
    if not isinstance(row, dict):
        return False
    if str(row.get('username', '')) == dr_user:
        return True
    fname = str(row.get('fname', '')).strip().lower()
    lname = str(row.get('lname', '')).strip().lower()
    return fname == 'ellis' and lname == 'tran'

ids = sorted({
    int(row['id']) for row in rows if is_match(row)
    and (isinstance(row.get('id'), int) or (isinstance(row.get('id'), str) and row['id'].isdigit()))
})
if len(ids) == 1:
    print(f"OK {ids[0]}")
else:
    sys.stderr.write(
        f"provider(): could not uniquely resolve dr_user={dr_user!r} from GET /api/user "
        f"(rows_seen={len(rows)}, matching_ids={ids})\n"
    )
    print("AMBIGUOUS")
PY
}

# Books the three demo patients onto the provider's calendar for the next
# DAYS days (default 14, 3 appointments/day) so the demo persists: anyone
# with the dr.tran login sees a populated "Today's patients" dropdown without
# running any script. Rerun with DAYS=<more> to extend the horizon.
schedule() {
    [ -f "$STATE/patients.json" ] || { echo "ERROR: run the clinical step first ('sh copilot-demo.sh clinical')"; exit 1; }
    [ -f "$STATE/provider.json" ] || { echo "ERROR: run the provider step first ('sh copilot-demo.sh provider')"; exit 1; }
    DAYS="${DAYS:-14}"
    export DAYS
    py <<'PY'
import json, os, subprocess, sys
from datetime import date, timedelta
base, state = os.environ['BASE'], os.environ['STATE']
days = int(os.environ['DAYS'])
tok = json.load(open(f'{state}/token.json'))['access_token']
provider_id = json.load(open(f'{state}/provider.json'))['id']
patients = json.load(open(f'{state}/patients.json'))

def call(method, path, payload=None):
    args = ['curl', '-s', '-X', method, f'{base}/apis/default{path}',
            '-H', f'Authorization: Bearer {tok}', '-H', 'Content-Type: application/json']
    if payload is not None:
        args += ['-d', json.dumps(payload)]
    return json.loads(subprocess.run(args, capture_output=True, text=True).stdout)

facilities = call('GET', '/api/facility')
frows = facilities.get('data') or []
if isinstance(frows, dict):
    frows = [frows]
if not frows:
    sys.exit("ERROR: GET /api/facility returned no rows — create a facility in the UI first")
facility_id = frows[0]['id']

# Idempotent top-up: schedule.json records the last day already booked, so a
# rerun extends the horizon instead of double-booking. (Migrates the legacy
# single-day {'day': ...} format.)
start = date.today()
if os.path.exists(f'{state}/schedule.json'):
    sched = json.load(open(f'{state}/schedule.json'))
    prev = sched.get('seeded_through') or sched.get('day')
    if prev:
        start = max(start, date.fromisoformat(prev) + timedelta(days=1))
end = date.today() + timedelta(days=days - 1)
if start > end:
    print(f"schedule already seeded through {end.isoformat()} — rerun with DAYS=<more> to extend")
    sys.exit(0)

count = 0
day = start
while day <= end:
    for patient in patients:
        appt = {
            'pc_catid': 9,
            'pc_title': 'Office Visit',
            'pc_duration': 900,
            'pc_hometext': 'copilot demo',
            'pc_apptstatus': '-',
            'pc_eventDate': day.isoformat(),
            'pc_startTime': patient['slot'],
            'pc_facility': facility_id,
            'pc_billing_location': facility_id,
            'pc_aid': provider_id,
        }
        d = call('POST', f"/api/patient/{patient['pid']}/appointment", appt)
        if d.get('id') is None:
            sys.exit(f"ERROR: appointment creation failed for pid={patient['pid']} on {day}: {d}")
        count += 1
    day += timedelta(days=1)

open(f'{state}/schedule.json', 'w').write(json.dumps({'seeded_through': end.isoformat()}))
print(f"booked {count} appointments ({start.isoformat()} .. {end.isoformat()}, 3/day) for provider id={provider_id}")
PY
}

# Per-patient clinical showcases so every panel interaction demonstrates a
# different part of the system (founder ask, 2026-07-09). Resolves-or-creates
# the three demo patients (idempotent), then seeds:
#   Reyes    — warfarin+aspirin (DDI card) + last visit 21d ago
#   Mendoza  — amoxicillin + CODED penicillin allergy (drug-allergy card;
#              title-only allergies read as honest "Unknown" — Wave-3 note)
#              + last visit 14d ago
#   Park     — lisinopril only + last visit 7d ago (earned-quiet showcase)
# Labs are seeded separately ('labs' step) — they have no REST write surface.
clinical() {
    [ -f "$STATE/provider.json" ] || { echo "ERROR: run the provider step first ('sh copilot-demo.sh provider')"; exit 1; }
    py <<'PY'
import json, os, subprocess, sys, urllib.parse
from datetime import date, timedelta
base, state = os.environ['BASE'], os.environ['STATE']
tok = json.load(open(f'{state}/token.json'))['access_token']
provider_id = json.load(open(f'{state}/provider.json'))['id']

def call(method, path, payload=None):
    args = ['curl', '-s', '-X', method, f'{base}/apis/default{path}',
            '-H', f'Authorization: Bearer {tok}', '-H', 'Content-Type: application/json']
    if payload is not None:
        args += ['-d', json.dumps(payload)]
    return json.loads(subprocess.run(args, capture_output=True, text=True).stdout)

def find_patient(fname, lname):
    q = urllib.parse.urlencode({'fname': fname, 'lname': lname})
    d = call('GET', f'/api/patient?{q}')
    rows = d.get('data') or []
    if isinstance(rows, dict):
        rows = [rows]
    for row in rows:
        if isinstance(row, dict) \
                and str(row.get('fname', '')).lower() == fname.lower() \
                and str(row.get('lname', '')).lower() == lname.lower():
            return {'pid': int(row['pid']), 'uuid': row['uuid']}
    return None

SPEC = [
    {'fname': 'Alma', 'lname': 'Reyes', 'DOB': '1961-03-14', 'sex': 'Female', 'slot': '09:00'},
    {'fname': 'Rafael', 'lname': 'Mendoza', 'DOB': '1958-11-02', 'sex': 'Male', 'slot': '09:30'},
    {'fname': 'June', 'lname': 'Park', 'DOB': '1979-06-21', 'sex': 'Female', 'slot': '10:15'},
]
patients = []
for spec in SPEC:
    p = find_patient(spec['fname'], spec['lname'])
    if p is None:
        d = call('POST', '/api/patient',
                 {'fname': spec['fname'], 'lname': spec['lname'], 'DOB': spec['DOB'], 'sex': spec['sex']})
        p = {'pid': d['data']['pid'], 'uuid': d['data']['uuid']}
        print(f"patient created: {spec['fname']} {spec['lname']} pid={p['pid']}")
    else:
        print(f"patient exists:  {spec['fname']} {spec['lname']} pid={p['pid']}")
    p.update({'fname': spec['fname'], 'lname': spec['lname'], 'slot': spec['slot']})
    patients.append(p)
open(f'{state}/patients.json', 'w').write(json.dumps(patients))

if os.path.exists(f'{state}/clinical.json'):
    print('clinical showcases already seeded (~/.copilot-demo/clinical.json)')
    sys.exit(0)

facilities = call('GET', '/api/facility')
frows = facilities.get('data') or []
if isinstance(frows, dict):
    frows = [frows]
fid, fname_ = frows[0]['id'], frows[0].get('name', '')

def encounter(p, days_ago, reason):
    day = (date.today() - timedelta(days=days_ago)).isoformat()
    d = call('POST', f"/api/patient/{p['uuid']}/encounter", {
        'date': day, 'reason': reason, 'facility': fname_, 'pc_catid': '5',
        'facility_id': str(fid), 'billing_facility': str(fid), 'sensitivity': 'normal',
        'referral_source': '', 'pos_code': '0', 'provider_id': str(provider_id),
        'class_code': 'AMB'})
    if not (d.get('data') or {}).get('encounter') and not (d.get('data') or {}).get('id'):
        print(f"WARNING: encounter for pid={p['pid']} may have failed: {d}")
    else:
        print(f"  encounter {day} — {p['lname']} ({reason})")

reyes, mendoza, park = patients

# Reyes already carries warfarin+aspirin+amoxicillin and the deliberately
# title-only Penicillin allergy from the seed step (honest-"Unknown" showcase).
encounter(reyes, 21, 'Hypertension follow-up')

med = call('POST', f"/api/patient/{mendoza['pid']}/medication",
           {'title': 'Amoxicillin 875mg Tablet', 'begdate': (date.today() - timedelta(days=10)).isoformat() + ' 00:00:00'})
print(f"  med: Amoxicillin 875mg Tablet — Mendoza ({(med.get('data') or {}) and 'ok'})")
alg = call('POST', f"/api/patient/{mendoza['uuid']}/allergy",
           {'title': 'Penicillin', 'diagnosis': 'RXNORM:70618', 'begdate': '2019-05-01'})
print(f"  allergy: Penicillin (CODED RXNORM:70618) — Mendoza ({(alg.get('data') or {}) and 'ok'})")
encounter(mendoza, 14, 'URI, resolved')

med = call('POST', f"/api/patient/{park['pid']}/medication",
           {'title': 'Lisinopril 10mg Tablet', 'begdate': (date.today() - timedelta(days=90)).isoformat() + ' 00:00:00'})
print(f"  med: Lisinopril 10mg Tablet — Park ({(med.get('data') or {}) and 'ok'})")
encounter(park, 7, 'Annual physical')

open(f'{state}/clinical.json', 'w').write(json.dumps({'seeded': date.today().isoformat()}))
print('clinical showcases seeded')
PY
}

# Synthetic lab results. Labs have NO REST/FHIR write surface in OpenEMR
# (procedure routes are GET-only, FHIR Observation is read-only), so this is
# the one seed step that writes SQL directly — setup tooling against the
# procedure_order/_report/_result chain the FHIR read path consumes; the
# module itself never touches these tables. Rows are tagged
# control_id='copilot-demo' and the generated SQL first deletes prior tagged
# rows for these patients, so reruns converge. Executes via 'railway ssh'
# when available (uses the container's MYSQL_* env), else prints the file to
# run by hand.
labs() {
    [ -f "$STATE/patients.json" ] || { echo "ERROR: run the clinical step first ('sh copilot-demo.sh clinical')"; exit 1; }
    py <<'PY'
import json, os
from datetime import date, timedelta
state = os.environ['STATE']
provider_id = json.load(open(f'{state}/provider.json'))['id']
patients = {p['lname']: p for p in json.load(open(f'{state}/patients.json'))}
today = date.today()

def dt(days_ago, time):
    return f"{(today - timedelta(days=days_ago)).isoformat()} {time}"

# lname, analyte, loinc, value, units, range, abnormal, resulted (None = undated)
LABS = [
    # Reyes: panic K AFTER her last visit (new lab + panic card) and an older
    # normal sodium BEFORE it (exercises the not-new exclusion).
    ('Reyes', 'Potassium', '2823-3', '6.8', 'mmol/L', '3.5-5.1', 'yes', dt(2, '07:30:00')),
    ('Reyes', 'Sodium', '2951-2', '141', 'mmol/L', '136-145', '', dt(35, '08:00:00')),
    # Mendoza: an UNDATED result — cannot be placed against his last visit,
    # surfaces as an honest unevaluable item (D0/D6).
    ('Mendoza', 'Vitamin D, 25-Hydroxy', '1989-3', '31', 'ng/mL', '30-100', '', None),
    # Park: one normal result BEFORE her last visit — nothing new, nothing
    # unevaluable: the earned-quiet banner showcase.
    ('Park', 'Hemoglobin', '718-7', '13.8', 'g/dL', '12.0-15.5', '', dt(30, '09:00:00')),
]

pids = sorted({p['pid'] for p in patients.values()})
sql = ["-- copilot-demo synthetic labs (setup tooling; module never writes)",
       "-- rerunnable: deletes prior copilot-demo rows for these patients first",
       f"DELETE presult, preport, pcode, porder FROM procedure_order porder"
       f" LEFT JOIN procedure_report preport ON preport.procedure_order_id = porder.procedure_order_id"
       f" LEFT JOIN procedure_result presult ON presult.procedure_report_id = preport.procedure_report_id"
       f" LEFT JOIN procedure_order_code pcode ON pcode.procedure_order_id = porder.procedure_order_id"
       f" WHERE porder.control_id = 'copilot-demo' AND porder.patient_id IN ({','.join(map(str, pids))});"]
for lname, analyte, loinc, value, units, rng, abnormal, resulted in LABS:
    pid = patients[lname]['pid']
    ordered = (resulted or dt(3, '08:00:00')).split(' ')[0]
    report_dt = f"'{resulted}'" if resulted else 'NULL'
    sql.append(
        f"INSERT INTO procedure_order (provider_id, patient_id, encounter_id, date_ordered, order_status,"
        f" activity, procedure_order_type, order_priority, control_id, specimen_type, specimen_location,"
        f" specimen_volume, clinical_hx, order_abn)"
        f" VALUES ({provider_id}, {pid}, 0, '{ordered}', 'complete', 1, 'laboratory_test', 'normal',"
        f" 'copilot-demo', '', '', '', '', 'not_required');")
    sql.append("SET @oid = LAST_INSERT_ID();")
    sql.append(
        f"INSERT INTO procedure_order_code (procedure_order_id, procedure_order_seq, procedure_code,"
        f" procedure_name, procedure_order_title) VALUES (@oid, 1, '{loinc}', '{analyte}', 'laboratory_test');")
    sql.append(
        f"INSERT INTO procedure_report (procedure_order_id, procedure_order_seq, date_collected, date_report,"
        f" report_status, review_status, source, specimen_num)"
        f" VALUES (@oid, 1, {report_dt}, {report_dt}, 'final', 'reviewed', 0, '');")
    sql.append("SET @rid = LAST_INSERT_ID();")
    sql.append(
        f"INSERT INTO procedure_result (procedure_report_id, result_data_type, result_code, result_text, date,"
        f" facility, units, result, `range`, abnormal, document_id, result_status)"
        f" VALUES (@rid, 'N', '{loinc}', '{analyte}', {report_dt}, '', '{units}', '{value}', '{rng}',"
        f" '{abnormal}', 0, 'final');")

open(f'{state}/seed-labs.sql', 'w').write('\n'.join(sql) + '\n')
print(f"generated {state}/seed-labs.sql ({len(LABS)} results)")
PY
    if command -v railway >/dev/null 2>&1; then
        b64="$(base64 < "$STATE/seed-labs.sql" | tr -d '\n')"
        echo "applying labs via railway ssh (service openemr)..."
        railway ssh -s openemr sh -c "'echo $b64 | base64 -d | mariadb -h\"\$MYSQL_HOST\" -P\"\$MYSQL_PORT\" -u\"\$MYSQL_USER\" -p\"\$MYSQL_PASS\" \"\$MYSQL_DATABASE\"'" \
            && echo "labs applied" \
            || { echo "ERROR: railway ssh apply failed — run $STATE/seed-labs.sql against the DB by hand"; exit 1; }
    else
        echo "railway CLI not found — apply $STATE/seed-labs.sql against the OpenEMR database yourself, e.g.:"
        echo "  docker compose -f docker/development-easy/docker-compose.yml exec -T openemr sh -c 'mariadb -h mysql -u openemr -popenemr openemr' < $STATE/seed-labs.sql"
    fi
}

turn() {
    py <<'PY'
import json, os, subprocess, time
base, state = os.environ['BASE'], os.environ['STATE']
tok = json.load(open(f'{state}/token.json'))['access_token']
uuid = json.load(open(f'{state}/patient.json'))['uuid']
t0 = time.time()
r = subprocess.run(['curl','-s','-X','POST',f'{base}/apis/default/api/copilot/turn',
    '-H',f'Authorization: Bearer {tok}','-H','Content-Type: application/json',
    '-d',json.dumps({'patient_uuid':uuid,
        'question':'Anything I should know before I walk in? Is the anticoagulation current?'})],
    capture_output=True, text=True)
d = json.loads(r.stdout)
print(f"turn completed in {time.time()-t0:.1f}s | degraded: {d.get('degraded')}")
for f in d.get('must_not_miss', []):
    print("  MUST-NOT-MISS:", f['type'], '—', f['summary'])
ans = d.get('answer') or {}
for c in ans.get('grounded', []): print("  grounded:", c['text'])
for c in ans.get('rejected', []): print("  REJECTED (unverified):", c['text'])
print("  correlation_id:", d.get('correlation_id'))
PY
}

show() {
    echo ""
    echo "================ PANEL SETUP ================"
    echo "URL:      $BASE/interface/modules/custom_modules/oe-module-copilot/public/panel.html"
    echo "API base: /apis/default"
    echo "Token:    $(python3 -c "import json;print(json.load(open('$STATE/token.json'))['access_token'])")"
    echo "Patient:  $(python3 -c "import json;print(json.load(open('$STATE/patient.json'))['uuid'])")"
    echo "Tokens expire after 1h — rerun 'sh copilot-demo.sh token' for a fresh one."
}

case "$STEP" in
    all)      register; token; seed; provider; clinical; schedule; labs; turn; show ;;
    register) register ;;
    token)    token; show ;;
    seed)     token 2>/dev/null || true; seed ;;
    provider) token 2>/dev/null || true; provider ;;
    clinical) token 2>/dev/null || true; clinical ;;
    schedule) token 2>/dev/null || true; schedule ;;
    labs)     labs ;;
    turn)     turn ;;
    show)     show ;;
    *) echo "usage: sh copilot-demo.sh [all|register|token|seed|provider|clinical|schedule|labs|turn|show]"; exit 1 ;;
esac
