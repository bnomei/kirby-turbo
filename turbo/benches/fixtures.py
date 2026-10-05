"""Generate disposable, deterministic IO workloads: uv run benches/fixtures.py target/bench-fixtures."""
import argparse
import os
from pathlib import Path

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("destination", type=Path)
args = parser.parse_args()
args.destination.mkdir(parents=True, exist_ok=False)
for name, pages, body_size, media in [
    ("small", 25, 256, True),
    ("mixed", 1024, 1024, True),
    ("large-content", 512, 65536, False),
]:
    for n in range(pages):
        folder = args.destination / name / f"section-{n % 8}" / f"page-{n}"
        folder.mkdir(parents=True, exist_ok=True)
        body = f"Title: Page {n}\n----\nText: " + "x" * body_size + "\n"
        (folder / "default.txt").write_text(body)
        if media:
            for suffix in ["jpg", "pdf", "webp"]:
                (folder / f"asset.{suffix}").write_bytes(bytes(range(256)) * 16)
        for path in folder.iterdir():
            os.utime(path, (1_700_000_000, 1_700_000_000))
    root = args.destination / name
    files = list(root.rglob("*"))
    print(name, sum(path.is_file() for path in files), "files")
