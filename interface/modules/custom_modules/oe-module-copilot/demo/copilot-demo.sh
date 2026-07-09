#!/bin/sh
# Clinical Co-Pilot — one-command demo setup against a deployed OpenEMR.
#
# Usage:
#   OE_PASS='<admin password>' sh copilot-demo.sh          # full flow
#   BASE='https://your-host' OE_USER=admin OE_PASS=... sh copilot-demo.sh
#   sh copilot-demo.sh token                                # just a fresh token
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
    all)      register; token; seed; turn; show ;;
    register) register ;;
    token)    token; show ;;
    seed)     token 2>/dev/null || true; seed ;;
    turn)     turn ;;
    show)     show ;;
    *) echo "usage: sh copilot-demo.sh [all|register|token|seed|turn|show]"; exit 1 ;;
esac
