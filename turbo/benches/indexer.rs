fn main() {
    divan::main();
}

// Benchmark the actual CLI implementation without adding a public library API
// solely for benchmark access. No copied production algorithms in this module.
#[allow(dead_code, unused_imports)]
mod indexer {
    include!("../src/main.rs");

    const CONTENT: &str = include_str!("../../tests/content/film/pirates-roxanne/film.txt");

    #[divan::bench]
    fn parse_fast() {
        divan::black_box(content_from_string(divan::black_box(CONTENT)));
    }

    #[divan::bench]
    fn parse_fallback(bencher: divan::Bencher) {
        let text = format!("\u{feff}{}", CONTENT.replace('\n', "\r\n"));
        bencher.bench(|| content_from_string(divan::black_box(&text)));
    }

    #[divan::bench(args = ["Title", "Very-Long Field-Name", "ÜBER Straße"])]
    fn parse_key(bencher: divan::Bencher, key: &str) {
        let text = format!("{key}: content");
        bencher.bench(|| parse_field(divan::black_box(&text)));
    }

    fn records(count: usize) -> Vec<FileInfo> {
        (0..count)
            .map(|n| FileInfo {
                dir: format!("/site/content/{}", n / 4),
                path: format!("/site/content/{}/{}.txt", n / 4, n),
                slug: format!("{n}.txt"),
                modified: Some(1_700_000_000),
                content: Some(content_from_string(CONTENT)),
            })
            .collect()
    }

    #[divan::bench(args = [100, 1000, 24000], sample_count = 20)]
    fn reduce(bencher: divan::Bencher, count: usize) {
        bencher
            .with_inputs(|| records(count))
            .bench_values(|files| build_output(files, 0, 0));
    }

    #[divan::bench(args = [100, 1000, 24000], sample_count = 20)]
    fn serialize(bencher: divan::Bencher, count: usize) {
        let output = build_output(records(count), 0, 0);
        bencher.bench(|| serde_json::to_string(divan::black_box(&output)).unwrap());
    }

    #[divan::bench(args = [1, 2, 4, 8], sample_count = 10)]
    fn scan_metadata(bencher: divan::Bencher, threads: usize) {
        let root = canonicalize_scan_root(concat!(env!("CARGO_MANIFEST_DIR"), "/../tests/content"));
        let allowed = HashSet::new();
        bencher.bench(|| scan(&root, true, false, &allowed, threads));
    }

    #[divan::bench(args = [1, 2, 4, 8], sample_count = 10)]
    fn scan_content(bencher: divan::Bencher, threads: usize) {
        let root = canonicalize_scan_root(concat!(env!("CARGO_MANIFEST_DIR"), "/../tests/content"));
        let allowed = WalkBuilder::new(&root)
            .parents(false)
            .build()
            .filter_map(Result::ok)
            .filter_map(|entry| {
                (entry
                    .path()
                    .extension()
                    .is_some_and(|extension| extension == "txt"))
                .then(|| entry.file_name().to_string_lossy().into_owned())
            })
            .collect();
        bencher.bench(|| scan(&root, true, true, &allowed, threads));
    }
}
