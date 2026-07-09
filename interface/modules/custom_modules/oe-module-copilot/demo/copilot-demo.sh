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

# Seeds today's 3-patient schedule for the resolved provider (reuses Alma
# Reyes from $STATE/patient.json + 2 new synthetic patients), so the in-EMR
# panel's "Today's patients" dropdown has something to show.
schedule() {
    [ -f "$STATE/schedule.json" ] && { echo "schedule already seeded ($STATE/schedule.json)"; return; }
    [ -f "$STATE/provider.json" ] || { echo "ERROR: run the provider step first ('sh copilot-demo.sh provider')"; exit 1; }
    TODAY="$(date -u +%F)"
    export TODAY
    py <<'PY'
import json, os, subprocess, sys
base, state = os.environ['BASE'], os.environ['STATE']
today = os.environ['TODAY']
tok = json.load(open(f'{state}/token.json'))['access_token']
provider_id = json.load(open(f'{state}/provider.json'))['id']

def call(method, path, payload=None):
    args = ['curl', '-s', '-X', method, f'{base}/apis/default{path}',
            '-H', f'Authorization: Bearer {tok}', '-H', 'Content-Type: application/json']
    if payload is not None:
        args += ['-d', json.dumps(payload)]
    return json.loads(subprocess.run(args, capture_output=True, text=True).stdout)

patients = [json.load(open(f'{state}/patient.json'))]
for fname, lname, dob, sex in [
    ('Rafael', 'Mendoza', '1958-11-02', 'Male'),
    ('June', 'Park', '1979-06-21', 'Female'),
]:
    d = call('POST', '/api/patient', {'fname': fname, 'lname': lname, 'DOB': dob, 'sex': sex})
    pid, uuid = d['data']['pid'], d['data']['uuid']
    print(f"patient created: {fname} {lname} pid={pid} uuid={uuid}")
    patients.append({'pid': pid, 'uuid': uuid})

facilities = call('GET', '/api/facility')
frows = facilities.get('data') or []
if isinstance(frows, dict):
    frows = [frows]
if not frows:
    sys.exit("ERROR: GET /api/facility returned no rows — create a facility in the UI first")
facility_id = frows[0]['id']
print(f"facility id={facility_id}")

times = ['09:00', '09:30', '10:15']
eids = []
for patient, start_time in zip(patients, times):
    appt = {
        'pc_catid': 9,
        'pc_title': 'Office Visit',
        'pc_duration': 900,
        'pc_hometext': 'copilot demo',
        'pc_apptstatus': '-',
        'pc_eventDate': today,
        'pc_startTime': start_time,
        'pc_facility': facility_id,
        'pc_billing_location': facility_id,
        'pc_aid': provider_id,
    }
    d = call('POST', f"/api/patient/{patient['pid']}/appointment", appt)
    eid = d.get('id')
    if eid is None:
        sys.exit(f"ERROR: appointment creation failed for pid={patient['pid']}: {d}")
    print(f"appointment created: pid={patient['pid']} {start_time} eid={eid}")
    eids.append(eid)

open(f'{state}/schedule.json', 'w').write(json.dumps({'day': today, 'eids': eids}))
PY
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
    all)      register; token; seed; provider; schedule; turn; show ;;
    register) register ;;
    token)    token; show ;;
    seed)     token 2>/dev/null || true; seed ;;
    provider) token 2>/dev/null || true; provider ;;
    schedule) token 2>/dev/null || true; schedule ;;
    turn)     turn ;;
    show)     show ;;
    *) echo "usage: sh copilot-demo.sh [all|register|token|seed|provider|schedule|turn|show]"; exit 1 ;;
esac
