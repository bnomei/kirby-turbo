use clap::Parser;
use serde::Serialize;
use tokio::fs;
use tokio::task;
use tokio::time::Instant;
use ignore::WalkBuilder;
use tokio::sync::mpsc;
use futures::stream::StreamExt;
use std::collections::{HashMap, HashSet};
use std::path::{Path, PathBuf};
use std::time::{SystemTime, UNIX_EPOCH};
use num_cpus;

/// Metadata information
#[derive(Serialize, Debug)]
struct Meta {
    duration_ms: u128,    // Execution duration in milliseconds
    timestamp: u64,       // Current time in seconds since UNIX epoch
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
    files: HashMap<String, FileInfo>,   // File path as the key, FileInfo as the value
    dirs: HashMap<String, Vec<String>>, // Directory path as the key, list of filenames as the value
    meta: Meta,                         // Metadata about execution time and timestamp
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
}

#[tokio::main]
async fn main() {
    let args = Args::parse();
    let dir = args.dir.clone();
    let dir_cloned = dir.clone();
    let allowed_files: HashSet<String> = args
        .filenames
        .split(',')
        .filter(|s| !s.trim().is_empty())
        .map(|s| s.trim().to_owned())
        .collect();

    let concurrency_limit = 2 * num_cpus::get();
    let include_modification_date = args.modified;
    let read_content = args.content;

    let start_time = Instant::now();

    let (tx, mut rx) = mpsc::channel(100);

    let walker_thread = task::spawn_blocking(move || {
        WalkBuilder::new(&dir_cloned)
            .standard_filters(true)
            // Don't let parent .gitignore (e.g. repo-level "content/")
            // exclude the explicitly targeted directory.
            .parents(false)
            .threads(num_cpus::get())
            .build_parallel()
            .run(move || {
                let tx = tx.clone();
                Box::new(move |entry| {
                    if let Ok(entry) = entry {
                        if entry.file_type().map(|t| t.is_file()).unwrap_or(false) {
                            if tx.blocking_send(entry.into_path()).is_err() {
                                return ignore::WalkState::Quit;
                            }
                        }
                    }
                    ignore::WalkState::Continue
                })
            });
    });

    let files = futures::stream::unfold(&mut rx, |rx| async {
        rx.recv().await.map(|path| (path, rx))
    })
        .map(|path| process_file(path, include_modification_date, read_content, allowed_files.clone()))
        .buffer_unordered(concurrency_limit)
        .collect::<Vec<_>>()
        .await;

    if walker_thread.await.is_err() {
        eprintln!("Error in directory traversal.");
    }

    // Capture the execution duration and current timestamp for metadata
    let duration = start_time.elapsed();
    let current_time = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap()
        .as_secs();

    let output = build_output(files, duration.as_millis(), current_time);
    let json_output = serde_json::to_string(&output).unwrap();
    let json_output = json_output.replace(&dir, "@");

    println!("{}", json_output);
}

/// Processes a single file, collecting metadata and parsing content if necessary
async fn process_file(
    path: PathBuf,
    include_modification_date: bool,
    read_content: bool,
    allowed_files: HashSet<String>
) -> FileInfo {
    let path_str = path.display().to_string();
    let dir_str = path.parent()
        .map(|p| p.display().to_string())
        .unwrap_or_else(|| String::from("<unknown>"));
    let filename = path.file_name()
        .and_then(|name| name.to_str())
        .map(|name| name.to_string())
        .unwrap_or_else(|| String::from("<unknown>"));
    let mut modified: Option<u64> = None;
    let mut content: Option<HashMap<String, String>> = None;

    if include_modification_date {
        if let Ok(metadata) = fs::metadata(&path).await {
            if let Ok(modified_time) = metadata.modified() {
                if let Ok(duration) = modified_time.duration_since(UNIX_EPOCH) {
                    modified = Some(duration.as_secs());
                }
            }
        }
    }

    if read_content {
        let file_name = path.file_name().and_then(|f| f.to_str());
        if let Some(file_name) = file_name {
            // allowed_files.is_empty() NOT as that would read ALL files, like images as well
            if allowed_files.contains(file_name) {
                if let Ok(file_content) = fs::read_to_string(&path).await {
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
    let mut file_map: HashMap<String, FileInfo> = HashMap::new();
    let mut dirs_map: HashMap<String, HashSet<String>> = HashMap::new();

    for file in files {
        // Insert file into `file_map` using its hash as the key
        file_map.insert(
            format!("#{:016x}", xxhash_rust::xxh3::xxh3_64(file.path.as_bytes())), // Add leading zeros like PHP
            file.clone(),
        );

        // Process the file path and add it to the directory map
        if let Some((dir, filename)) = split_dir_and_file(&file.path) {
            // Add the file to its parent directory
            dirs_map
                .entry(dir.to_string())
                .or_insert_with(HashSet::new)
                .insert(filename);

            // Ensure the directory itself is added to its parent directory
            if let Some(parent_dir) = Path::new(&dir).parent().and_then(|p| p.to_str()) {
                if let Some(dir_basename) = Path::new(&dir).file_name().and_then(|d| d.to_str()) {
                    dirs_map
                        .entry(parent_dir.to_string())
                        .or_insert_with(HashSet::new)
                        .insert(dir_basename.to_string());
                }
            }
        }
    }

    let dirs: HashMap<String, Vec<String>> = dirs_map
        .into_iter()
        .map(|(dir, entries)| (dir, entries.into_iter().collect()))
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
    use std::path::PathBuf;

    fn fixture_path(relative: &str) -> PathBuf {
        PathBuf::from(env!("CARGO_MANIFEST_DIR"))
            .join("..")
            .join(relative)
    }

    fn read_fixture(relative: &str) -> String {
        std::fs::read_to_string(fixture_path(relative))
            .expect("fixture file should be readable")
    }

    #[test]
    fn parses_store_file_fast_path() {
        let content = read_fixture("tests/content/store/store-1/store.txt");
        let parsed = content_from_string(&content);

        assert_eq!(parsed.get("title"), Some(&"Store 1".to_string()));
        assert_eq!(parsed.get("store_id"), Some(&"1".to_string()));
        assert_eq!(parsed.get("address"), Some(&"page://QXfNniA66zakdNBv".to_string()));
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

        let features = parsed.get("special_features").expect("special_features should exist");
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
}
