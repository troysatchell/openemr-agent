#!/usr/bin/env python3
# Closed-loop HTTP load generator for docs/LOAD_TEST.md (TRO-submission evidence).
# Dependency-free. Usage: loadtest.py <url> <concurrency> <total-requests> [bearer-token]
# One persistent worker per simulated user; prints a one-line JSON summary
# (rps, p50/p95/p99/max ms, status-code counts).
import sys, time, json, statistics, urllib.request, ssl
from concurrent.futures import ThreadPoolExecutor

url, conc, total = sys.argv[1], int(sys.argv[2]), int(sys.argv[3])
auth = sys.argv[4] if len(sys.argv) > 4 else None
ctx = ssl.create_default_context()

def one(_):
    req = urllib.request.Request(url, headers={"User-Agent": "copilot-loadtest"})
    if auth:
        req.add_header("Authorization", "Bearer " + auth)
    t0 = time.perf_counter()
    try:
        with urllib.request.urlopen(req, timeout=60, context=ctx) as r:
            r.read()
            return (time.perf_counter() - t0, r.status)
    except Exception as e:
        return (time.perf_counter() - t0, getattr(e, 'code', 0))

t_start = time.perf_counter()
with ThreadPoolExecutor(max_workers=conc) as ex:
    results = list(ex.map(one, range(total)))
wall = time.perf_counter() - t_start

lat = sorted(r[0] * 1000 for r in results)
codes = {}
for _, c in results:
    codes[c] = codes.get(c, 0) + 1
q = lambda p: lat[min(len(lat) - 1, int(len(lat) * p))]
print(json.dumps({
    "url": url, "concurrency": conc, "requests": total,
    "wall_seconds": round(wall, 1), "rps": round(total / wall, 1),
    "p50_ms": round(q(0.50)), "p95_ms": round(q(0.95)), "p99_ms": round(q(0.99)),
    "max_ms": round(lat[-1]), "status_codes": codes,
}))
