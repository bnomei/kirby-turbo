use clap::Parser;
use ignore::WalkBuilder;
use serde::Serialize;
use std::collections::{HashMap, HashSet};
use std::fs;
use std::num::NonZeroUsize;
use std::path::{Path, PathBuf};
use std::sync::Mutex;
use std::time::{Instant, SystemTime, UNIX_EPOCH};

const ROOT_SENTINEL: &str = "@~";

/// Metadata information
#[derive(Serialize, Debug)]
struct Meta {
    duration_ms: u128, // Execution duration in milliseconds
    timestamp: u64,    // Current time in seconds since UNIX epoch
}

/// Struct representing file information
#[derive(Serialize, Debug, Clone)]
struct FileInfo {
    dir: String,
    path: String,
    slug: String,
    modified: Option<u64>, // Modification date in seconds since UNIX epoch
    content: Option<HashMap<String, String>>, // Parsed key-value pairs
}

/// Struct representing the final output (files, directories, and meta information)
#[derive(Serialize, Debug)]
struct Output {
    files: HashMap<String, FileInfo>, // File path as the key, FileInfo as the value
    dirs: HashMap<String, Vec<String>>, // Directory path as the key, list of filenames as the value
    meta: Meta,                       // Metadata about execution time and timestamp
}

/// CLI program to list files' metadata
#[derive(Parser, Debug)]
#[clap(author, version, about, long_about = None)]
struct Args {
    /// Directory to scan
    #[clap(short, long, value_parser)]
    dir: String,

    /// Include modification timestamps in the output
    #[clap(short = 'm', long, action, default_value_t = false)]
    modified: bool,

    /// Read and parse file content into key-value pairs
    #[clap(short = 'c', long, action, default_value_t = false)]
    content: bool,

    /// Comma-separated list of filenames to filter (content read)
    #[clap(short = 'f', long, value_parser, default_value = "")]
    filenames: String,

    /// Scan workers (default: available CPUs); 1 runs entirely on the calling thread
    #[clap(short = 't', long)]
    threads: Option<NonZeroUsize>,
}

fn main() {
    let args = Args::parse();
    let dir = canonicalize_scan_root(&args.dir);
    let allowed_files: HashSet<String> = args
        .filenames
        .split(',')
        .filter(|s| !s.trim().is_empty())
        .map(|s| s.trim().to_owned())
        .collect();

    let start_time = Instant::now();
    let threads = args
        .threads
        .unwrap_or_else(|| std::thread::available_parallelism().unwrap_or(NonZeroUsize::MIN));
    let files = scan(
        &dir,
        args.modified,
        args.content,
        &allowed_files,
        threads.get(),
    );

    // Capture the execution duration and current timestamp for metadata
    let duration = start_time.elapsed();
    let current_time = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap()
        .as_secs();

    let output = build_output(files, duration.as_millis(), current_time);
    let json_output = serde_json::to_string(&output).unwrap();
    let json_output = json_output.replace(&dir, ROOT_SENTINEL);

    println!("{}", json_output);
}

fn scan(
    dir: &str,
    modified: bool,
    content: bool,
    allowed_files: &HashSet<String>,
    threads: usize,
) -> Vec<FileInfo> {
    let mut walker = WalkBuilder::new(dir);
    // Parent ignore files must not exclude the explicitly selected root.
    walker
        .standard_filters(true)
        .parents(false)
        .threads(threads);
    if threads == 1 {
        return walker
            .build()
            .filter_map(Result::ok)
            .filter(|entry| entry.file_type().is_some_and(|kind| kind.is_file()))
            .map(|entry| process_file(entry.into_path(), modified, content, allowed_files))
            .collect();
    }

    // Each worker owns its records. The only result lock is once per worker,
    // after traversal, rather than once per file or filesystem operation.
    struct Batch<'a> {
        files: Vec<FileInfo>,
        batches: &'a Mutex<Vec<Vec<FileInfo>>>,
    }
    impl Drop for Batch<'_> {
        fn drop(&mut self) {
            self.batches
                .lock()
                .unwrap()
                .push(std::mem::take(&mut self.files));
        }
    }
    let batches = Mutex::new(Vec::new());
    walker.build_parallel().run(|| {
        let mut batch = Batch {
            files: Vec::new(),
            batches: &batches,
        };
        Box::new(move |entry| {
            if let Ok(entry) = entry {
                if entry.file_type().is_some_and(|kind| kind.is_file()) {
                    batch.files.push(process_file(
                        entry.into_path(),
                        modified,
                        content,
                        allowed_files,
                    ));
                }
            }
            ignore::WalkState::Continue
        })
    });
    batches
        .into_inner()
        .unwrap()
        .into_iter()
        .flatten()
        .collect()
}

fn canonicalize_scan_root(dir: &str) -> String {
    PathBuf::from(dir)
        .canonicalize()
        .unwrap_or_else(|_| PathBuf::from(dir))
        .to_string_lossy()
        .to_string()
}

/// Processes a single file, collecting metadata and parsing content if necessary
fn process_file(
    path: PathBuf,
    include_modification_date: bool,
    read_content: bool,
    allowed_files: &HashSet<String>,
) -> FileInfo {
    let path_str = path.display().to_string();
    let dir_str = path
        .parent()
        .map(|p| p.display().to_string())
        .unwrap_or_else(|| String::from("<unknown>"));
    let filename = path
        .file_name()
        .and_then(|name| name.to_str())
        .map(|name| name.to_string())
        .unwrap_or_else(|| String::from("<unknown>"));
    let mut modified: Option<u64> = None;
    let mut content: Option<HashMap<String, String>> = None;

    if include_modification_date {
        if let Ok(metadata) = fs::metadata(&path) {
            if let Ok(modified_time) = metadata.modified() {
                if let Ok(duration) = modified_time.duration_since(UNIX_EPOCH) {
                    modified = Some(duration.as_secs());
                }
            }
        }
    }

    if read_content {
        if let Some(file_name) = path.file_name().and_then(|f| f.to_str()) {
            // An empty allowlist reads no content, not every file (including media).
            if allowed_files.contains(file_name) {
                if let Ok(file_content) = fs::read_to_string(&path) {
                    content = Some(content_from_string(&file_content));
                }
            }
        }
    }

    FileInfo {
        dir: dir_str,
        path: path_str,
        slug: filename,
        modified,
        content,
    }
}

/// Builds the final Output struct, including metadata
fn build_output(files: Vec<FileInfo>, duration_ms: u128, timestamp: u64) -> Output {
    let mut file_map: HashMap<String, FileInfo> = HashMap::with_capacity(files.len());
    let mut dirs_map: HashMap<String, Vec<String>> = HashMap::new();

    for file in files {
        if let Some((dir, filename)) = split_dir_and_file(&file.path) {
            dirs_map.entry(dir.to_owned()).or_default().push(filename);
        }
        file_map.insert(
            format!("#{:016x}", xxhash_rust::xxh3::xxh3_64(file.path.as_bytes())),
            file,
        );
    }

    // Add precisely the immediate parents of directories containing files.
    // Do not recursively synthesize ancestors or expose empty directories.
    let parents: Vec<_> = dirs_map
        .keys()
        .filter_map(|dir| {
            let path = Path::new(dir);
            Some((
                path.parent()?.to_str()?.to_owned(),
                path.file_name()?.to_str()?.to_owned(),
            ))
        })
        .collect();
    for (parent, name) in parents {
        dirs_map.entry(parent).or_default().push(name);
    }

    let dirs: HashMap<String, Vec<String>> = dirs_map
        .into_iter()
        .map(|(dir, mut entries)| {
            entries.sort_unstable();
            entries.dedup();
            (dir, entries)
        })
        .collect();

    Output {
        files: file_map,
        dirs,
        meta: Meta {
            duration_ms,
            timestamp,
        },
    }
}

/// Splits a file path into directory and filename
fn split_dir_and_file(file_path: &str) -> Option<(&str, String)> {
    let path = Path::new(file_path);
    let parent = path.parent()?.to_str()?;
    let file_name = path.file_name()?.to_string_lossy().to_string();
    Some((parent, file_name))
}

/// Parses the content of a file and splits it into key-value pairs as a HashMap
fn content_from_string(text: &str) -> HashMap<String, String> {
    if text.is_empty() {
        return HashMap::new();
    }

    if is_fast_path_safe(text) {
        return content_from_string_fast(text);
    }

    content_from_string_fallback(text)
}

fn is_fast_path_safe(text: &str) -> bool {
    let bytes = text.as_bytes();
    if bytes.starts_with(&[0xEF, 0xBB, 0xBF]) {
        return false;
    }
    if bytes.iter().any(|b| *b == b'\r') {
        return false;
    }
    if text.starts_with("----") {
        return false;
    }

    for line in text.split('\n') {
        if line.starts_with("\\----") {
            return false;
        }
        if line.starts_with("----") {
            if line.len() > 4 && line[4..].trim().is_empty() {
                return false;
            }
            continue;
        }
        if line.ends_with("----") {
            return false;
        }
    }

    true
}

fn content_from_string_fast(text: &str) -> HashMap<String, String> {
    let mut result = HashMap::new();
    for yml in text.split("----\n") {
        if let Some((key, value)) = parse_field(yml) {
            result.insert(key, value);
        }
    }
    result
}

fn content_from_string_fallback(text: &str) -> HashMap<String, String> {
    let mut normalized = text.replace("\r\n", "\n").replace('\r', "\n");
    if let Some(stripped) = normalized.strip_prefix('\u{FEFF}') {
        normalized = stripped.to_string();
    }

    let mut fields: Vec<String> = Vec::new();
    let mut current = String::new();

    for (index, line) in normalized.split('\n').enumerate() {
        let is_separator = index > 0 && line.starts_with("----") && line[4..].trim().is_empty();
        if is_separator {
            fields.push(current);
            current = String::new();
            continue;
        }

        if !current.is_empty() {
            current.push('\n');
        }
        current.push_str(line);
    }
    fields.push(current);

    let mut result = HashMap::new();
    for field in fields {
        if let Some((key, value)) = parse_field(&field) {
            result.insert(key, unescape_separators(&value));
        }
    }

    result
}

fn parse_field(raw: &str) -> Option<(String, String)> {
    let pos = raw.find(':')?;
    if pos == 0 {
        return None;
    }

    let mut key = raw[..pos].trim().to_lowercase();
    if key.is_empty() {
        return None;
    }
    key = key.replace('-', "_").replace(' ', "_");

    let value = raw[pos + 1..].trim().to_string();

    Some((key, value))
}

fn unescape_separators(value: &str) -> String {
    let bytes = value.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    let mut line_start = true;

    while i < bytes.len() {
        if line_start
            && bytes[i] == b'\\'
            && i + 4 < bytes.len()
            && bytes[i + 1] == b'-'
            && bytes[i + 2] == b'-'
            && bytes[i + 3] == b'-'
            && bytes[i + 4] == b'-'
        {
            out.extend_from_slice(b"----");
            i += 5;
            line_start = false;
            continue;
        }

        let b = bytes[i];
        out.push(b);
        i += 1;
        line_start = b == b'\n';
    }

    String::from_utf8(out).unwrap_or_else(|_| value.to_string())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::fs;
    use std::path::PathBuf;
    use std::sync::atomic::{AtomicU64, Ordering};
    use std::time::{SystemTime, UNIX_EPOCH};

    #[cfg(unix)]
    use std::os::unix::fs::symlink;

    fn fixture_path(relative: &str) -> PathBuf {
        PathBuf::from(env!("CARGO_MANIFEST_DIR"))
            .join("..")
            .join(relative)
    }

    fn read_fixture(relative: &str) -> String {
        std::fs::read_to_string(fixture_path(relative)).expect("fixture file should be readable")
    }

    fn temp_path(name: &str) -> PathBuf {
        static COUNTER: AtomicU64 = AtomicU64::new(0);
        let unique = COUNTER.fetch_add(1, Ordering::Relaxed);

        std::env::temp_dir().join(format!(
            "kirby-turbo-{name}-{}-{unique}",
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .expect("time should be monotonic")
                .as_nanos()
        ))
    }

    #[test]
    fn parses_store_file_fast_path() {
        let content = read_fixture("tests/content/store/store-1/store.txt");
        let parsed = content_from_string(&content);

        assert_eq!(parsed.get("title"), Some(&"Store 1".to_string()));
        assert_eq!(parsed.get("store_id"), Some(&"1".to_string()));
        assert_eq!(
            parsed.get("address"),
            Some(&"page://QXfNniA66zakdNBv".to_string())
        );
        assert_eq!(parsed.get("uuid"), Some(&"84ohRKd6kBoYuc0q".to_string()));
    }

    #[test]
    fn parses_customer_file_fast_path() {
        let content = read_fixture("tests/content/customer/dennis-gilman/customer.txt");
        let parsed = content_from_string(&content);

        assert_eq!(parsed.get("customer_id"), Some(&"338".to_string()));
        assert_eq!(parsed.get("first_name"), Some(&"DENNIS".to_string()));
        assert_eq!(parsed.get("last_name"), Some(&"GILMAN".to_string()));
        assert_eq!(parsed.get("active"), Some(&"1".to_string()));
    }

    #[test]
    fn parses_film_file_fast_path() {
        let content = read_fixture("tests/content/film/pirates-roxanne/film.txt");
        let parsed = content_from_string(&content);

        let features = parsed
            .get("special_features")
            .expect("special_features should exist");
        assert!(features.starts_with("Commentaries"));
        assert!(features.contains("Deleted Scenes"));
        assert_eq!(parsed.get("film_id"), Some(&"681".to_string()));
        assert_eq!(parsed.get("release_year"), Some(&"2006".to_string()));
    }

    #[test]
    fn parses_store_file_with_bom_crlf_and_escaped_separator() {
        let content = read_fixture("tests/content/store/store-1/store.txt");
        let mut mutated = content.replace('\n', "\r\n");
        mutated = format!("\u{FEFF}{}", mutated);
        mutated = mutated.replacen(
            "Title: Store 1",
            "Title:\r\nLine one\r\n\\----\r\nLine two",
            1,
        );

        let parsed = content_from_string(&mutated);

        assert_eq!(
            parsed.get("title"),
            Some(&"Line one\n----\nLine two".to_string())
        );
        assert_eq!(parsed.get("store_id"), Some(&"1".to_string()));
    }

    #[cfg(unix)]
    #[test]
    fn canonicalizes_symlinked_scan_roots() {
        let root = temp_path("symlink-root");
        let real = root.join("real-content");
        let link = root.join("content-link");

        fs::create_dir_all(real.join("nested")).expect("real directory should be created");
        fs::write(
            real.join("nested").join("default.txt"),
            "Title: Nested\n----\n",
        )
        .expect("fixture file should be written");
        symlink(&real, &link).expect("symlink should be created");

        let canonical = canonicalize_scan_root(link.to_str().expect("utf-8 path"));
        let expected = real
            .canonicalize()
            .expect("real directory should canonicalize")
            .to_string_lossy()
            .to_string();

        assert_eq!(canonical, expected);

        fs::remove_dir_all(&root).expect("temporary root should be removed");
    }
}
