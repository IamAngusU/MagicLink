# Performance

MagicLink keeps the public request path small: validate, apply durable rate
limits, create one link, enqueue one encrypted message and return. SMTP is not
part of that transaction. With `MAIL_AUTO_DISPATCH=true`, one queued message is
attempted only after the response can be flushed; for predictable latency under
load, use the worker and set `MAIL_AUTO_DISPATCH=false`.

## Reproduce the SQLite service benchmark

```bash
php scripts/benchmark.php
```

The benchmark uses a fresh temporary SQLite WAL database and reports p50, p95
and p99 for link creation, a single state read and a 32-item state batch. It
excludes HTTP parsing, TLS, SMTP and network latency, so it describes the storage
and service layer rather than promising end-to-end production response times.

## Measured local baseline

Five runs on 2026-10-08 used PHP 8.4.21, Sodium, SQLite 3.51.3, Windows and an
Intel Core i9-12900K. The table reports the median observation across those runs:

| Path | p50 | p95 | p99 | Throughput |
| --- | ---: | ---: | ---: | ---: |
| Create link + encrypted outbox + audit | 0.443 ms | 0.671 ms | 1.363 ms | 2,083 calls/s |
| Read one state | 0.008 ms | 0.008 ms | 0.015 ms | 122,955 calls/s |
| Read 32 states in one query | 0.053 ms | 0.068 ms | 0.107 ms | 578,437 state items/s |

Peak allocated PHP memory was 2 MiB in every run. These are warm, in-process
microbenchmarks on a fast local SSD, not end-to-end hosting claims. Cheap shared
storage can make SQLite commits much slower; real HTTP and mail measurements must
be taken on the target host.

SQLite serializes writes and is a good fit for one host with moderate sign-in
volume. Use MySQL when several application nodes or mail workers need to write
concurrently. The API publishes effective batch and polling limits through
`GET /api/v1/config`; clients should use those values instead of hard-coding
their own request rhythm.

## Reading the result

- `request` includes rate-limit counters, encryption, link and outbox writes,
  and the audit event in one eager transaction.
- `state_single` is the ordinary polling path for one browser.
- `state_batch_32` measures one query for 32 selectors and reports both calls
  and state items per second.
- Mail delivery remains provider-bound. Benchmark SMTP separately against the
  provider and region used in production.
