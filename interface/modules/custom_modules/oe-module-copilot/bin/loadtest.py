#!/usr/bin/env python3
# Closed-loop HTTP load generator for docs/LOAD_TEST.md (TRO-submission evidence).
# Dependency-free. Usage: loadtest.py <url> <concurrency> <total-requests>
# Bearer token (optional) is read from the LOADTEST_BEARER_TOKEN environment
# variable — never from argv, where process inspection would expose it.
# One persistent worker per simulated user; prints a one-line JSON summary
# (rps, p50/p95/p99/max ms, status-code counts).
import argparse, os, time, json, urllib.error, urllib.request, ssl
from concurrent.futures import ThreadPoolExecutor


def positive_int(value):
    n = int(value)
    if n <= 0:
        raise argparse.ArgumentTypeError("must be a positive integer")
    return n


parser = argparse.ArgumentParser(description="Closed-loop HTTP load generator.")
parser.add_argument("url")
parser.add_argument("concurrency", type=positive_int)
parser.add_argument("total_requests", type=positive_int)
args = parser.parse_args()
url, conc, total = args.url, args.concurrency, args.total_requests
auth = os.environ.get("LOADTEST_BEARER_TOKEN")
ctx = ssl.create_default_context()


def one(_):
    req = urllib.request.Request(url, headers={"User-Agent": "copilot-loadtest"})
    if auth:
        req.add_header("Authorization", "Bearer " + auth)
    t0 = time.perf_counter()
    # Catch only expected transport failures; a programming error (bad URL
    # scheme, type bug) should crash loudly, not count as status 0.
    try:
        with urllib.request.urlopen(req, timeout=60, context=ctx) as r:
            r.read()
            return (time.perf_counter() - t0, r.status)
    except urllib.error.HTTPError as e:
        return (time.perf_counter() - t0, e.code)
    except (urllib.error.URLError, TimeoutError, ConnectionError, OSError):
        return (time.perf_counter() - t0, 0)


t_start = time.perf_counter()
with ThreadPoolExecutor(max_workers=conc) as ex:
    results = list(ex.map(one, range(total)))
wall = time.perf_counter() - t_start

lat = sorted(r[0] * 1000 for r in results)
codes = {}
for _, c in results:
    codes[c] = codes.get(c, 0) + 1


def percentile(p):
    # Nearest-rank percentile (ceil convention): the smallest sample such
    # that at least p of the distribution is at or below it.
    rank = max(1, -(-len(lat) * p // 1))  # ceil(len * p), floor-div trick
    return lat[min(len(lat) - 1, int(rank) - 1)]


print(json.dumps({
    "url": url, "concurrency": conc, "requests": total,
    "wall_seconds": round(wall, 1), "rps": round(total / wall, 1),
    "p50_ms": round(percentile(0.50)), "p95_ms": round(percentile(0.95)),
    "p99_ms": round(percentile(0.99)),
    "max_ms": round(lat[-1]), "status_codes": codes,
}))
