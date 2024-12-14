use clap::Parser;
use serde::{Serialize};
use tokio::fs;
use tokio::task;
use tokio::time::Instant; // For measuring execution time
use ignore::WalkBuilder;
use tokio::sync::mpsc; // For async channel
use std::time::{SystemTime, UNIX_EPOCH};
use std::path::{PathBuf, Path};
use std::collections::{HashMap, HashSet};
use futures::stream::{StreamExt}; // For handling asynchronous streams
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
    path: String,
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

    /// Comma-separated list of filenames to filter
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
            .threads(num_cpus::get())
            .build_parallel()
            .run(move || {
                let tx = tx.clone();
                let allowed_files = allowed_files.clone();
                Box::new(move |entry| {
                    if let Ok(entry) = entry {
                        if entry.file_type().map(|t| t.is_file()).unwrap_or(false) {
                            let file_name = entry.path().file_name().and_then(|f| f.to_str());
                            if let Some(file_name) = file_name {
                                if allowed_files.is_empty() || allowed_files.contains(file_name) {
                                    if tx.blocking_send(entry.into_path()).is_err() {
                                        return ignore::WalkState::Quit;
                                    }
                                }
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
        .map(|path| process_file(path, include_modification_date, read_content))
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
) -> FileInfo {
    let path_str = path.display().to_string();
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
        if let Ok(file_content) = fs::read_to_string(&path).await {
            content = Some(content_from_string(&file_content));
        }
    }

    FileInfo {
        path: path_str,
        modified,
        content,
    }
}

/// Builds the final Output struct, including metadata
fn build_output(files: Vec<FileInfo>, duration_ms: u128, timestamp: u64) -> Output {
    let mut file_map: HashMap<String, FileInfo> = HashMap::new();
    let mut dirs_map: HashMap<String, Vec<String>> = HashMap::new();

    for file in files {
        file_map.insert(file.path.clone(), file.clone());
        if let Some((dir, filename)) = split_dir_and_file(&file.path) {
            dirs_map
                .entry(dir.to_string())
                .or_insert_with(Vec::new)
                .push(filename);
        }
    }

    Output {
        files: file_map,
        dirs: dirs_map,
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
    let mut result = HashMap::new();
    for yml in text.split("----\n") {
        let parts: Vec<&str> = yml.splitn(2, ":").collect();
        if parts.len() == 2 {
            let key = parts[0].trim().to_lowercase();
            let value = parts[1].trim().to_string();
            result.insert(key, value);
        }
    }
    result
}