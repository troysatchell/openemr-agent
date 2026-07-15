# Load test report — 10 and 50 concurrent users (live deployment)

**Date:** 2026-07-15 · **Target:** the live Railway deployment
(`https://openemr-production-4eba.up.railway.app`, single `openemr` container +
single `mariadb` container, region sfo) · **Generator:**
[`bin/loadtest.py`](../bin/loadtest.py) (dependency-free Python 3, closed-loop
`ThreadPoolExecutor`, one persistent worker per simulated user), run from a
single client host over the public internet — latencies include TLS + WAN.

Two request classes were exercised:

- **Login page** (`GET /interface/login/login.php?site=default`) —
  unauthenticated, but a full PHP + session + DB bootstrap; the heaviest
  no-credential page.
- **Co-Pilot API** (`GET /apis/default/api/copilot/health`, Bearer token) —
  the shared API stack every module route rides: OAuth2 token validation and
  the module's default-deny guarded route (S5), both DB-backed. The handler
  itself is liveness-only (no DB probe — that is `/ready`), so these numbers
  measure the auth + route-guard path, not dependency-probe capacity.

The **LLM turn path was deliberately not load-tested**: each turn is a paid
vendor call (~$0.019 text, more with vision/evidence — see
[COST_MODEL.md](COST_MODEL.md)) and vendor rate limits, not app capacity,
dominate its concurrency behavior. This is a scope decision, stated rather
than hidden.

## Results

| Run | Concurrency | Requests | Success | RPS | p50 | p95 | p99 | max |
|---|---|---|---|---|---|---|---|---|
| Login page | 10 | 200 | **200/200 (100%)** | 25.5 | 213 ms | 1,940 ms | 3,758 ms | 3,766 ms |
| API /health | 10 | 200 | **200/200 (100%)** | 44.9 | 219 ms | 246 ms | 269 ms | 289 ms |
| Login page | 50 | 500 | **500/500 (100%)** | 42.6 | 795 ms | 4,940 ms | 6,052 ms | 6,333 ms |
| API /health | 50 | 500 | **391/500 (78.2%)** — 109 × HTTP 500 | 123.4 | 403 ms | 591 ms | 633 ms | 639 ms |

## Findings

1. **10 concurrent users: comfortable on both paths.** Sub-300 ms p95 on the
   authenticated API; the login page's p95 (~2 s) reflects its heavyweight
   unauthenticated bootstrap, not the API stack.
2. **50 concurrent users: the API path hits a hard database ceiling.**
   21.8% of requests failed with HTTP 500. Container logs attribute every
   failure to MariaDB `SQLSTATE[08004]/[HY000] "Too many connections"` —
   each PHP request opens its own DB connection for OAuth token validation
   and the guarded-route ACL check (the `/health` handler itself opens
   none), and 50 simultaneous connections exhaust the single MariaDB
   container's `max_connections` budget. The login page survived at
   50 users because its slower page build naturally staggers connections.
3. **Failure mode is honest, not silent:** over-capacity requests fail fast
   with a 5xx (p95 of the mixed run stayed under 600 ms) rather than queueing
   into timeouts.

## Remediation (named follow-ups, not yet implemented)

- Raise `max_connections` on the MariaDB service and/or cap Apache workers to
  the DB budget so requests queue at the web tier instead of erroring at the
  DB tier.
- Persistent/pooled DB connections for the API path.
- Horizontal scaling is NOT currently possible for the app container without
  session/store work — single-container is a known deployment constraint.

## Reproduction

```sh
# 10-user and 50-user runs, API path (token via demo/copilot-demo.sh token).
# The bearer token rides an env var, never argv (argv is visible to local
# process inspection):
export LOADTEST_BEARER_TOKEN="$TOKEN"
python3 interface/modules/custom_modules/oe-module-copilot/bin/loadtest.py \
  'https://openemr-production-4eba.up.railway.app/apis/default/api/copilot/health' 10 200
python3 interface/modules/custom_modules/oe-module-copilot/bin/loadtest.py \
  'https://openemr-production-4eba.up.railway.app/apis/default/api/copilot/health' 50 500
```

Raw JSON outputs from the recorded runs are inlined below.

```json
{"url": ".../interface/login/login.php?site=default", "concurrency": 10, "requests": 200, "wall_seconds": 7.8, "rps": 25.5, "p50_ms": 213, "p95_ms": 1940, "p99_ms": 3758, "max_ms": 3766, "status_codes": {"200": 200}}
{"url": ".../apis/default/api/copilot/health", "concurrency": 10, "requests": 200, "wall_seconds": 4.4, "rps": 44.9, "p50_ms": 219, "p95_ms": 246, "p99_ms": 269, "max_ms": 289, "status_codes": {"200": 200}}
{"url": ".../interface/login/login.php?site=default", "concurrency": 50, "requests": 500, "wall_seconds": 11.7, "rps": 42.6, "p50_ms": 795, "p95_ms": 4940, "p99_ms": 6052, "max_ms": 6333, "status_codes": {"200": 500}}
{"url": ".../apis/default/api/copilot/health", "concurrency": 50, "requests": 500, "wall_seconds": 4.1, "rps": 123.4, "p50_ms": 403, "p95_ms": 591, "p99_ms": 633, "max_ms": 639, "status_codes": {"200": 391, "500": 109}}
```

**Caveats:** closed-loop generator (each user waits for its previous response
— no coordinated-omission correction); single client host; public-internet
latency included; the deployment was otherwise idle during the runs.
