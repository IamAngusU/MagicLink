# Performance

MagicLink keeps SMTP outside the public request transaction. A request validates
and rate-limits the caller, checks queue capacity, creates one encrypted link and
outbox item, records an audit event, and returns. For predictable latency under
load, run the worker separately with `MAIL_AUTO_DISPATCH=false`.

## Reproduce the SQLite benchmark

```bash
php scripts/benchmark.php
```

The default run creates five fresh temporary SQLite WAL databases. In each it
measures 400 successful requests, 5,000 single-state reads, 1,000 32-selector
state batches, 100 new-identity and 100 pending-identity rejections at the
500-item automatic queue cap, plus 5,000 no-op maintenance due-checks. A separate
fresh database receives a barrier-synchronised burst from four PHP processes,
25 requests each. The JSON result contains every run, aggregate medians and
ranges, peak memory, parameters, and SQLite query plans. Counts are configurable
with the script's named options.

## Measured local baseline

Measured 2026-10-08 on Windows 11 10.0.26200, an Intel Core i9-12900K and a local
WD_BLACK SN850X NVMe drive, using PHP 8.4.21, Sodium, SQLite 3.51.3 and schema
version 4. Each value is the median observation across five clean runs; brackets
show the minimum and maximum run observations.

| Path | p50 | p95 | p99 | Rate |
| --- | ---: | ---: | ---: | ---: |
| Request: link + encrypted outbox + audit | 0.515 ms [0.494–0.556] | 0.662 ms [0.633–0.772] | 0.793 ms [0.701–0.969] | 1,890 calls/s [1,738–1,960] |
| Read one state | 0.008 ms [0.008–0.008] | 0.008 ms [0.008–0.008] | 0.015 ms [0.009–0.031] | 124,827 calls/s [118,803–127,413] |
| Read 32 states in one query | 0.052 ms [0.052–0.052] | 0.066 ms [0.058–0.080] | 0.117 ms [0.106–0.141] | 580,715 items/s [568,864–592,400] |
| Full queue: reject a new identity | 0.355 ms [0.322–0.365] | 0.421 ms [0.399–0.494] | 0.481 ms [0.442–0.790] | 2,791 calls/s [2,605–3,010] |
| Full queue: reject a pending identity | 0.344 ms [0.322–0.358] | 0.425 ms [0.405–0.435] | 0.507 ms [0.471–0.512] | 2,818 calls/s [2,739–3,000] |
| Maintenance due-check, not due | 0.005 ms [0.005–0.005] | 0.006 ms [0.005–0.006] | 0.007 ms [0.006–0.010] | 189,943 calls/s [181,716–193,657] |
| SQLite burst: 4 × 25 writes | 0.477 ms [0.474–0.509] | 0.699 ms [0.621–0.763] | 35.166 ms [27.066–42.296] | 767 wall calls/s [718–813] |

All 500 burst requests completed. Burst wall time was 130.460 ms median
[123.062–139.328], including each child process's database connection startup.
The p99 increase is the expected cost of SQLite serialising competing writers;
it is why SQLite is positioned for one host and moderate sign-in traffic rather
than presented as a horizontally scalable write store. Peak allocated memory in
the parent process was 2 MiB [2–2] in all runs.

## Query-plan check

`EXPLAIN QUERY PLAN` on the same populated schema confirmed that the active-queue
count uses a covering `status` index (`idx_mail_outbox_retention`), while handoff
lookups use the unique indexes on `code_hash` and `request_hash`. The
benchmark emits the exact plan text so an upgrade can reveal a regression rather
than relying on this prose.

## What these numbers do not claim

The first six rows are warm, in-process service/storage timings. Their rates
are derived from summed call durations, not an HTTP load test. They exclude web
server and session work, request parsing, reverse proxies, TLS, network latency,
mail-worker execution and SMTP-provider latency. The bounded burst is local and
does not model cheap or networked shared-hosting storage. MySQL was not measured.
Benchmark the complete deployment and its mail provider before setting capacity
targets; clients should read effective poll, batch and queue settings from the
API instead of hard-coding them.
