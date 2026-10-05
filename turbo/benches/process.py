"""Linux whole-process benchmark. Run with uv run benches/process.py --help.

Each --binary is LABEL:PATH:THREADS (use '-' for a legacy binary).
Times include startup, walking, parsing, reduction, serialization and output.
Warm-cache randomized blocks; JSON equality is checked before measurement.
"""
import argparse
import csv
import json
import math
import os
from pathlib import Path
import random
import statistics
import subprocess
import tempfile
import time


def normalize(path):
    with open(path) as stream:
        value = json.load(stream)
    value.pop("meta")
    value["dirs"] = {key: sorted(names) for key, names in value["dirs"].items()}
    return value


def run(command, output):
    start = time.perf_counter_ns()
    with open(output, "wb") as stream:
        child = subprocess.Popen(command, stdout=stream)
        _, status, usage = os.wait4(child.pid, 0)
        child.returncode = os.waitstatus_to_exitcode(status)
    if child.returncode:
        raise RuntimeError(f"failed ({child.returncode}): {command}")
    return {
        "elapsed_ms": (time.perf_counter_ns() - start) / 1e6,
        "cpu_ms": (usage.ru_utime + usage.ru_stime) * 1000,
        "rss_kib": usage.ru_maxrss,
        "voluntary_switches": usage.ru_nvcsw,
        "involuntary_switches": usage.ru_nivcsw,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--binary", action="append", required=True)
    parser.add_argument("--fixture", action="append", required=True)
    parser.add_argument("--repeats", type=int, default=15)
    parser.add_argument("--cpus", default="0,1,2,3")
    parser.add_argument("--load", choices=["idle", "contended", "both"], default="both")
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--modes", default="metadata,content")
    args = parser.parse_args()
    if args.repeats < 1:
        parser.error("--repeats must be positive")
    cpus = [int(cpu) for cpu in args.cpus.split(",")]
    if not set(cpus).issubset(os.sched_getaffinity(0)):
        parser.error("requested CPUs are outside the current affinity")
    cases = []
    for fixture in args.fixture:
        root = Path(fixture).resolve()
        names = ",".join(sorted({path.name for path in root.rglob("*.txt")}))
        for mode in args.modes.split(","):
            for spec in args.binary:
                label, binary, threads = spec.split(":")
                command = ["taskset", "-c", args.cpus, str(Path(binary).resolve()),
                           "--dir", str(root), "--filenames", names]
                if mode in ("metadata", "content"):
                    command += ["--modified"]
                if mode == "content":
                    command += ["--content"]
                if threads != "-":
                    command += ["--threads", threads]
                cases.append((str(root), mode, label, threads, command))
    args.output.parent.mkdir(parents=True, exist_ok=True)
    records = []
    with tempfile.TemporaryDirectory(prefix="turbo-bench-") as temporary:
        output = Path(temporary) / "stdout.json"
        expected = {}
        for fixture, mode, label, threads, command in cases:
            run(command, output)
            actual = normalize(output)
            key = (fixture, mode)
            if key in expected:
                assert actual == expected[key], f"inventory mismatch: {label}/{threads}/{key}"
            else:
                expected[key] = actual
        del expected
        print("Semantic equality verified; warm-cache timing starts.", flush=True)
        loads = ["idle", "contended"] if args.load == "both" else [args.load]
        rng = random.Random(6)
        with args.output.open("w") as stream:
            fields = ["fixture", "mode", "binary", "threads", "load", "iteration",
                      "elapsed_ms", "cpu_ms", "rss_kib", "voluntary_switches", "involuntary_switches"]
            writer = csv.DictWriter(stream, fieldnames=fields)
            writer.writeheader()
            for load in loads:
                workers = []
                try:
                    if load == "contended":
                        # One runnable competing process per allowed CPU. No IO load.
                        for cpu in cpus:
                            workers.append(subprocess.Popen([
                                "taskset", "-c", str(cpu), "python3", "-c", "while True: pass"
                            ]))
                        time.sleep(0.5)
                    for iteration in range(args.repeats):
                        rng.shuffle(cases)
                        for fixture, mode, label, threads, command in cases:
                            row = dict(fixture=fixture, mode=mode, binary=label,
                                       threads=threads, load=load, iteration=iteration)
                            row.update(run(command, output))
                            writer.writerow(row)
                            stream.flush()
                            records.append(row)
                        print(f"{load}: block {iteration + 1}/{args.repeats}", flush=True)
                finally:
                    for worker in workers:
                        worker.terminate()
                    for worker in workers:
                        worker.wait()
    groups = {}
    for row in records:
        key = tuple(row[field] for field in fields[:5])
        groups.setdefault(key, []).append(row)
    print("fixture mode binary threads load: elapsed median / p95 / CV%; CPU median (ms)")
    for key, rows in sorted(groups.items()):
        elapsed = sorted(row["elapsed_ms"] for row in rows)
        cpu = [row["cpu_ms"] for row in rows]
        cv = statistics.stdev(elapsed) / statistics.mean(elapsed) * 100 if len(rows) > 1 else 0
        print(*key, f": {statistics.median(elapsed):.2f} / {elapsed[math.ceil(len(elapsed)*.95)-1]:.2f} / {cv:.1f}%; {statistics.median(cpu):.2f}")


if __name__ == "__main__":
    main()
