# Indexer performance: issue #6

This suite measures the Rust CLI, not the packaged `bin/turbo` binaries. It includes [Divan](https://docs.rs/divan/0.1.21/divan/) component benchmarks and a Linux whole-process harness. The latter is the decision metric: `meta.duration_ms` excludes reduction, JSON serialization, root substitution, and writing stdout.

## Experimenting with worker counts

The plugin selects the worker count automatically by default. For benchmarking, the Rust indexer accepts `--threads N` (or `-t N`). Omitting it uses the CPUs available to the process. `--threads 1` runs entirely on the calling thread; larger values set the number of traversal workers, with no separate IO pool. Zero and negative counts are invalid.

After building, run from `turbo/`:

```sh
target/release/turbo --dir ../tests/content --modified --content --filenames film.txt,actor.txt --threads 2
```

To test through Kirby, set a positive integer or a closure returning one in `site/config/config.php`:

```php
return [
    'bnomei.turbo.inventory.threads' => 2,
];
```

The default `null` leaves selection to the binary. This option only applies to the Rust indexer, not `find`, and requires a binary built from the updated Rust source. When testing through Kirby, point `bnomei.turbo.inventory.indexer` at that executable; changing the thread setting does not rebuild the packaged binaries.

Compare 1, 2, 4, and automatic selection using the harness below. Measure elapsed **and** CPU time with content preloading enabled and disabled. More workers are not always faster, especially on small inventories or shared hosts. The single-thread walker preserves ignore rules but can perform more ignore-file probes than the parallel walker.

## Reproduce the final implementation

Run from `turbo/`. Keep builds in this directory's normal `target/`. Rust 1.99.0 was used; `Cargo.lock` records the final dependency resolution. No `target-cpu=native`, allocator replacement, unsafe code, or release-profile changes were retained.

```sh
cargo build --release --locked
cargo test --locked
cargo test --release --locked
cargo bench --locked --bench indexer
# Narrower component runs:
cargo bench --locked --bench indexer -- parse
cargo bench --locked --bench indexer -- reduce

mkdir -p target/experiments
cp target/release/turbo target/experiments/final
uv run benches/fixtures.py target/bench-fixtures
uv run benches/process.py \
  --binary final:target/experiments/final:1 \
  --binary final:target/experiments/final:2 \
  --binary final:target/experiments/final:4 \
  --binary final:target/experiments/final:8 \
  --fixture ../tests/content \
  --fixture target/bench-fixtures/small \
  --fixture target/bench-fixtures/mixed \
  --fixture target/bench-fixtures/large-content \
  --cpus 0,1,2,3 --repeats 15 --load both \
  --output target/experiments/process.csv
```

Choose CPUs in the process's allowed affinity (`taskset -pc $$`). The fixture generator deliberately refuses to overwrite an existing destination; reuse the generated fixtures for subsequent runs. The Python scripts require no third-party packages. Do not run builds, tests, or another benchmark concurrently with measurements.

Each binary argument is `label:path:threads`; `-` omits `--threads` (both for the legacy binary and for testing automatic worker selection). Metadata mode passes `--modified`; content mode adds `--content` with all distinct `.txt` basenames present in the fixture. `--modes inventory` measures neither content nor modified timestamps. Filename filtering affects **content reads only**, not inventory membership.

Before timing, every candidate's parsed JSON must match the first candidate for that fixture/mode after dropping `meta` and sorting directory arrays. To check against the old implementation, add `--binary baseline:target/experiments/baseline:-` **first**. Records include wall time, child user+system CPU, and voluntary/involuntary context switches. The raw `rss_kib` field includes memory inherited during process launch from the Python validator: it is **not a trustworthy indexer-only memory measurement**, and no memory claims use it. Summaries report median, nearest-rank p95, and sample standard deviation / mean (CV). With only 15 samples, p95 is the maximum: use more repetitions for tail-latency decisions.

Whole-process time includes taskset/exec, CLI parsing, scan, parse, reduce, serialize, root replacement, stdout to a temporary regular file, and process teardown. It also includes the harness's small process-launch/file-open overhead, important on tiny fixtures. `os.wait4` collects each child's own CPU/resource usage, not competitors' usage. Warmups and JSON comparison precede timing; randomized complete blocks use seed 6. No page-cache flushing is performed. “Idle” means no harness-generated competition, not exclusive physical-host access. Contention is one runnable Python busy-loop process pinned to each allowed CPU, terminated and joined after the run; it does not simulate disk contention, stolen VM time, or a particular hosting provider.

## Build the historical baseline without overwriting working source

The baseline source is [the pre-change indexer](https://github.com/bnomei/kirby-turbo/commit/340bb7b76496203b85fda4f125b5af5be9e7fa75). Fetch full history first if the checkout is shallow. Extract into ignored `target/`, but explicitly use the **normal** shared `target/` for all Cargo output. Use `git show`, not `git archive`: this repository marks `/turbo` as `export-ignore`.

```sh
mkdir -p target/baseline-source/src target/experiments
git show 340bb7b76496203b85fda4f125b5af5be9e7fa75:turbo/Cargo.toml > target/baseline-source/Cargo.toml
git show 340bb7b76496203b85fda4f125b5af5be9e7fa75:turbo/src/main.rs > target/baseline-source/src/main.rs
cp benches/baseline.lock target/baseline-source/Cargo.lock
cargo build --release --locked --manifest-path target/baseline-source/Cargo.toml --target-dir target
cp target/release/turbo target/experiments/baseline
# Restore the current crate's release executable after building an alternative.
cargo build --release --locked
```

The original baseline resolved Tokio 1.53.2, futures 0.3.34, num_cpus 1.17.0, ignore 0.4.33, serde_json 1.0.151, and xxhash-rust 0.8.19. The historical crate had no tracked lockfile. The reconstructed [baseline.lock](baseline.lock) was verified by rebuilding to the **same SHA-256 binary** as the original measured baseline on the experiment orb. The final lockfile is also tracked. Blindly resolving newer dependencies is not the same experiment.

## Measured workloads and hardware

Measured 2026-10-05 on a Linux x86_64 Amp orb: 16 visible logical CPUs, Intel Xeon 2.60 GHz, Linux 6.1.158+, Rust/Cargo 1.99.0, LLVM 23. Native GNU release builds; no packaged release binary was replaced. The underlying physical-host load and storage cache are uncontrolled. These results do **not** reproduce the reporter's Hetzner host or its reported 3× patched-parallel/patched-serial ratio.

| Workload | Files | Shape |
|---|---:|---|
| `../tests/content` | 23,765 | Repository's many small Kirby content files; roughly one file per directory |
| `small` | 100 | 25 pages, each with 256-byte body plus three binary media files |
| `mixed` | 4,096 | 1,024 pages, each with 1 KiB body plus three 4 KiB media files |
| `large-content` | 512 | 512 pages with 64 KiB bodies, eight sections |

The final matrix has 1,200 measured processes: 4 fixtures × 2 modes × 5 configurations × 2 loads × 15 repetitions. Another 192 processes cover one-CPU affinity, and 100 cover 16-CPU affinity with automatic worker selection. Candidate experiments add 888 process measurements, for 2,380 total. Raw measurements, profiles, environment details, and trial patches were archived separately during release preparation, not retained as repository files. The experiment log below preserves the findings; CSV and trace names identify those archived runs. Keep new generated results under ignored `target/`.

The repository retains only the reusable harness, benchmark code, baseline lockfile, and this log. The existing `/turbo export-ignore` rule excludes this entire directory, Rust source, tests, and lockfiles from Git release archives; only the prepared binaries under `bin/` are distributed with the plugin.

### Four allowed CPUs, full repository fixture

Each cell is median **elapsed / CPU milliseconds**. “Legacy” uses its automatic async architecture; other rows use explicit actual scan workers.

| Workers | Metadata idle | Metadata contended | Content idle | Content contended |
|---|---:|---:|---:|---:|
| Legacy | 691 / 1,162 | 984 / 962 | 1,297 / 2,226 | 1,650 / 1,607 |
| 1 | 354 / 349 | 720 / 350 | 517 / 506 | 1,078 / 523 |
| 2 | 166 / 255 | 347 / 257 | 266 / 419 | 564 / 421 |
| 4 | 124 / 276 | 296 / 251 | 189 / 440 | 476 / 417 |
| 8 | 134 / 296 | 270 / 259 | 219 / 473 | 473 / 434 |

At four workers, idle metadata is 5.6× faster and uses 76% less CPU; content is 6.9× faster and uses 80% less CPU. Under contention these become 3.3×/74% and 3.5×/74%. Idle elapsed CV is 9.1% metadata / 5.4% content, versus legacy 4.8% / 4.3%; final p95 is 157 / 217 ms versus legacy 789 / 1,440 ms. Contended final CV is 6.5% in both modes, with p95 333 / 501 ms. The optimization lowers latency and CPU cost, **not every relative variance measure**.

### The best worker count depends on workload

- Small fixture, idle: one worker takes 5.6 ms metadata / 5.8 ms content; four take 6.5 / 7.7 ms. Under contention, one takes 17 / 16 ms versus four at 42 / 38 ms. Thread startup/scheduling dominates.
- Mixed-media fixture, idle: legacy takes 125 / 145 ms; four workers take 20 / 26 ms. Contended: 180 / 222 ms versus 57 / 64 ms.
- 64 KiB content fixture: idle content improves only 178→136 ms (legacy→four workers); serial JSON/content work limits the benefit. Contended: 397→295 ms. Metadata-only has very little useful work; one worker wins under contention (26 ms vs four at 44 ms).
- One allowed CPU, full fixture: idle metadata legacy/one/two/four = 453/370/255/248 ms; content = 872/545/404/413 ms. Under contention **one-worker metadata regresses slightly**, 735→765 ms, while CPU falls 473→379 ms. Two workers take 410 ms and four 373 ms. Do not promise an across-the-board win for `--threads 1`.
- Sixteen allowed CPUs, full fixture, idle: legacy metadata/content = 802/1,516 ms; automatic final (16 workers) = 101/165 ms, CPU = 389/671 ms versus 1,459/2,784 ms. Explicit two workers cost less CPU (278/455 ms) but take 180/292 ms. Four and eight lie between these extremes.

The default remains available CPUs as requested, rather than selecting an unproven threshold after a partial scan. Kirby's `bnomei.turbo.inventory.threads` lets the operator choose latency versus CPU cost. `1` is truly sequential, not one background walker plus a hidden IO pool.

### Linux release artifact verification — 2026-10-05

Release summary: **approximately 2.5×–3.5× faster inventory indexing on the tested large content repository**, compared with the previous packaged Linux binary. This is a workload-specific measured range, not a guaranteed speedup on every host.

After integrating the upstream request-policy changes and preparing Composer version 5.4.0, the x86_64 musl release binary was rebuilt. Do not apply the GNU benchmark multipliers above directly to this artifact: musl was slower on this workload. A separate randomized comparison against the previously packaged `bin/turbo` verified semantic output equality and measured five repetitions per case on the full fixture with four allowed CPUs. The new binary used four workers; the previous binary used its automatic settings.

| Mode | Previous packaged binary | New musl binary | Speedup |
|---|---:|---:|---:|
| Metadata, idle | 1,006 ms | 360 ms | 2.8× |
| Content, idle | 1,780 ms | 697 ms | 2.6× |
| Metadata, CPU contention | 1,820 ms | 515 ms | 3.5× |
| Content, CPU contention | 2,772 ms | 976 ms | 2.8× |

These are warm-cache whole-process medians, not a controlled same-toolchain rebuild of the old artifact or a Hetzner reproduction. The musl release tests passed all nine Rust tests; the combined upstream/plugin PHP suite passed 77 tests / 501 assertions using the rebuilt Linux binary, and PHPStan passed. macOS artifact rebuilding and validation remain separate release steps.

## Experiments and decisions

Each row names the mechanism, evidence, and decision. Compare candidates **within** their randomized CSV rather than between runs on different host load. Several small effects are within noise; they are not independent speedup multipliers.

| # | Candidate / risk | Evidence | Decision |
|---|---|---|---|
| 1 | Synchronous metadata/content on the discovering walker worker, remove Tokio/futures handoffs | `first.csv`: full-fixture four-worker metadata 754→136 ms, content 1,397→264 ms | Retain; dominant mechanism |
| 2 | Borrow one immutable filename allowlist instead of cloning it per file | Included in #1; no isolated speedup claim | Retain; same membership rules without allocation/copying |
| 3 | Worker-local record vectors, one publication lock per worker instead of per-file delivery | Included in #1; futex profile below | Retain; ownership stays local until worker termination; no per-file result lock |
| 4 | Genuine calling-thread `WalkBuilder::build()` for one worker | Full matrix demonstrates small-workload benefit and full-tree ignore-probe cost | Retain as explicit control, not a universal default |
| 5 | Move `FileInfo` into output rather than deep-clone content/maps | `reduction.csv`: content 234→201 ms; metadata effect inconclusive | Retain; reduces duplicate live allocations |
| 6 | Pre-size final file map using known record count | `reduction.csv`: metadata 134→127 ms, content neutral 201→202 ms | Retain; exact capacity known, trivial change; small effect not statistically established |
| 7 | Add immediate parent-directory memberships once per distinct directory | `reduction.csv`: content 202→197 ms, metadata neutral | Retain once-per-directory work; initial cached-slug shortcut rejected because non-UTF-8 filenames use `<unknown>` slug but lossy directory entry |
| 8 | Collect directory entries in vectors, then sort/deduplicate instead of per-directory hash sets | `buffer.csv`: content 192→187 ms, metadata 129→126 ms, CPU also modestly lower | Retain; correct uniqueness preserved including lossy filename collisions; stable array order is incidental |
| 9 | Reuse one read `String` per worker | `buffer.csv`: content 187→191 ms; `open-stat.csv`: 186→188 ms | Revert; extra state and retention of largest file without useful gain |
| 10 | Open allowed content first, use `File::metadata`, then read from same handle | `open-stat.csv`: 186→183 ms; other shapes show mixed results, 64 KiB content 134→141 ms and CPU 161→178 ms | Revert; marginal small-file result does not justify larger-file regression / changed race behavior |
| 11 | Replace key hyphens/spaces in one `replace(['-', ' '], "_")` | Divan `Title` 98→81 ns, long key 111→137 ns, Unicode 168→177 ns; whole-process other shapes neutral | Revert; parser changes not justified by broad evidence |
| 12 | Preallocate JSON byte buffer at 256 bytes/file, `to_writer`, then validate UTF-8 | `serialization.csv`: metadata 124→123 ms, content 196→195 ms; adds guesswork and validation pass | Revert; no useful improvement |
| 13 | Disable ignore/hidden filters / use raw traversal semantics | `no-filters.csv` shows possible metadata savings but `filter-edge.log` fails inventory equality on a hidden file | Reject regardless of timing; inventory membership cannot change |
| 14 | Thin LTO + one codegen unit | `lto.csv`: content 189→177 ms, metadata 118→120 ms; mixed-media and large-file checks neutral; LTO dependency build ~11 s (build-cost comparison not isolated) | Do not change release profile on this evidence; optional host-specific follow-up, untested on macOS/musl |
| 15 | Byte-slice `contains` instead of iterator search for CR | `contains.csv`: full content 214→214 ms; 64 KiB content 145→154 ms, noisy tails | Revert; Clippy suggestion is not evidence of an end-to-end win |

## Profiling and correctness

```sh
taskset -c 0-3 strace -f -c -o target/experiments/profile.txt \
  target/experiments/final --dir ../tests/content --modified --threads 4 >/dev/null
strace -f -e trace=clone,clone3 -o target/experiments/threads.txt \
  target/experiments/final --dir ../tests/content --modified --threads 1 >/dev/null
```

`baseline.strace` records 213,985 futex calls versus 32 for the worker-local/vector implementation. Both have 47,550 `getdents64` calls and approximately 23,800 file/directory opens and metadata calls. This attributes the dominant improvement to removed synchronization, not skipped files. **Do not compare strace elapsed times**: tracing perturbs threaded scheduling drastically. The one-worker trace records no `clone`/`clone3`; four workers produce four thread creations, not a second IO pool.

The sequential `ignore` walker probes `.ignore`, `.gitignore`, `.git`, etc. by path. Its parallel walker uses already-read directory entries to avoid unsuccessful probes. Calling `build_parallel().threads(1)` would improve that cost but still spawn a worker, violating the requested calling-thread sequential mode. A custom raw `read_dir` walker would need to reproduce ignore semantics; it is not an acceptable drop-in shortcut.

Correctness checks retain xxh3 keys of **absolute** paths, root sentinel substitution, filename allowlisting, all file inventory entries, modified timestamps, duplicate-field parsing, BOM/CRLF/escaped separators, and omission of unreadable content. Integration tests cover worker counts 1/2/4/8, empty/missing roots, parent ignore isolation, local `.ignore`/`.gitignore`, hidden files, empty and deep directories, symlinked roots, nested file/directory links, dangling links/loops, invalid UTF-8 names/content, and rejected thread arguments. Directory arrays are compared as sets, not walker order. Traversal remains best-effort, not an atomic filesystem snapshot; concurrent mutations were not made transactional.

Validation: 5 existing unit tests + 4 new Rust integration tests pass in debug and release; the Divan suite executes; PHP Pest reports 36 tests / 118 assertions; PHPStan reports no errors. Direct PHP `Turbo::execWithTurbo()` + `unwrap()` against the new native executable returns 23,765 files and equal inventories for threads 1 and 4. Clippy completes with three pre-existing parser suggestions (`manual_contains`, `manual_strip`, `collapsible_str_replace`); no claim of a warning-free baseline. The ordinary PHP suite still uses the unchanged packaged binary for its existing binary tests; argument-passing tests use a stub, and the explicit native integration check bridges that gap.

Sources: [issue #6](https://github.com/bnomei/kirby-turbo/issues/6), [Tokio filesystem batching guidance](https://docs.rs/tokio/1.53.2/tokio/fs/index.html), [ignore walker APIs](https://docs.rs/ignore/0.4.33/ignore/struct.WalkBuilder.html), and [Divan](https://docs.rs/divan/0.1.21/divan/). Tavily research confirmed Tokio's documented blocking-pool behavior; source inspection of `ignore` explained the serial/parallel probe difference. Measurements, not external benchmark multipliers, determined the retained changes.
