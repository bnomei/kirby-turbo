use serde_json::{json, Value};
use std::fs;
use std::path::{Path, PathBuf};
use std::process::Command;
use std::sync::atomic::{AtomicUsize, Ordering};

struct Fixture(PathBuf);
impl Fixture {
    fn new() -> Self {
        static NEXT: AtomicUsize = AtomicUsize::new(0);
        let root = std::env::temp_dir().join(format!(
            "turbo-inventory-{}-{}",
            std::process::id(),
            NEXT.fetch_add(1, Ordering::Relaxed)
        ));
        fs::create_dir_all(&root).unwrap();
        Self(root)
    }
    fn write(&self, path: &str, content: impl AsRef<[u8]>) {
        let path = self.0.join(path);
        fs::create_dir_all(path.parent().unwrap()).unwrap();
        fs::write(path, content).unwrap();
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        fs::remove_dir_all(&self.0).unwrap();
    }
}

fn scan(root: &Path, threads: usize, flags: &[&str]) -> Value {
    let output = Command::new(env!("CARGO_BIN_EXE_turbo"))
        .arg("--dir")
        .arg(root)
        .arg("--threads")
        .arg(threads.to_string())
        .args(flags)
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    let mut value: Value = serde_json::from_slice(&output.stdout).unwrap();
    value.as_object_mut().unwrap().remove("meta");
    for entries in value["dirs"].as_object_mut().unwrap().values_mut() {
        entries
            .as_array_mut()
            .unwrap()
            .sort_by(|a, b| a.as_str().cmp(&b.as_str()));
    }
    value
}

#[test]
fn workers_preserve_filters_content_hashes_and_directory_membership() {
    let fixture = Fixture::new();
    fixture.write(".ignore", "content/\n"); // parent must not hide explicitly selected root
    fixture.write(
        "content/.git/config",
        "[core]\nrepositoryformatversion = 0\n",
    );
    fixture.write("content/.gitignore", "git-skipped.txt\n");
    fixture.write("content/.ignore", "ignored/\n");
    fixture.write("content/git-skipped.txt", "no");
    fixture.write("content/ignored/default.txt", "no");
    fixture.write("content/.hidden.txt", "no");
    fixture.write(
        "content/nested/default.txt",
        "\u{feff}Title: First\r\n----\r\nTitle: Last\r\n----\r\nBody: A\r\n\\----\r\nB",
    );
    fixture.write("content/nested/image.jpg", b"\xff\x00");
    fixture.write("content/deep/only/leaf/default.txt", "Title: Deep");
    fixture.write("content/nested/bad.txt", b"Title: \xff");
    fs::create_dir_all(fixture.0.join("content/empty")).unwrap();
    #[cfg(unix)]
    {
        use std::os::unix::fs::symlink;
        symlink("nested", fixture.0.join("content/link-dir")).unwrap();
        symlink("nested/default.txt", fixture.0.join("content/link-file")).unwrap();
        symlink(".", fixture.0.join("content/loop")).unwrap();
        symlink("missing", fixture.0.join("content/dangling")).unwrap();
    }
    let root = fixture.0.join("content").canonicalize().unwrap();
    for flags in [
        vec![],
        vec!["--modified"],
        vec!["--content"],
        vec![
            "--modified",
            "--content",
            "--filenames",
            "default.txt,bad.txt",
        ],
    ] {
        let expected = scan(&root, 1, &flags);
        for threads in [2, 4, 8] {
            assert_eq!(scan(&root, threads, &flags), expected);
        }
        let files = expected["files"].as_object().unwrap();
        assert_eq!(files.len(), 4);
        let path = root.join("nested/default.txt").display().to_string();
        let hash = format!("#{:016x}", xxhash_rust::xxh3::xxh3_64(path.as_bytes()));
        let file = &files[&hash];
        assert_eq!(file["path"], "@~/nested/default.txt");
        assert_eq!(file["slug"], "default.txt");
        assert_eq!(file["modified"].is_number(), flags.contains(&"--modified"));
        if flags.contains(&"--filenames") {
            assert_eq!(
                file["content"],
                json!({"title": "Last", "body": "A\n----\nB"})
            );
        } else {
            assert!(files.values().all(|file| file["content"].is_null()));
        }
        assert!(files
            .values()
            .filter(|file| file["slug"] != "default.txt")
            .all(|file| file["content"].is_null()));
        assert_eq!(
            expected["dirs"]["@~/nested"],
            json!(["bad.txt", "default.txt", "image.jpg"])
        );
        assert_eq!(expected["dirs"]["@~/deep/only"], json!(["leaf"]));
        assert!(expected["dirs"].get("@~/deep").is_none());
        assert_eq!(expected["dirs"]["@~"], json!(["nested"]));
        #[cfg(unix)]
        {
            let link = fixture.0.join("root-link");
            if !link.exists() {
                std::os::unix::fs::symlink(&root, &link).unwrap();
            }
            assert_eq!(scan(&link, 2, &flags), expected);
        }
    }
}

#[test]
fn rejects_invalid_worker_counts() {
    for threads in ["0", "-1", "no", "1.5"] {
        let output = Command::new(env!("CARGO_BIN_EXE_turbo"))
            .args(["--dir", ".", "--threads", threads])
            .output()
            .unwrap();
        assert!(!output.status.success());
        assert!(output.stdout.is_empty());
    }
}

#[test]
fn empty_and_missing_roots_produce_empty_inventory() {
    let fixture = Fixture::new();
    for root in [&fixture.0, &fixture.0.join("missing")] {
        for threads in [1, 4] {
            assert_eq!(scan(root, threads, &[]), json!({"files": {}, "dirs": {}}));
        }
    }
}

#[cfg(unix)]
#[test]
fn non_utf8_filename_keeps_lossy_directory_entry_and_unknown_slug() {
    use std::os::unix::ffi::OsStrExt;
    let fixture = Fixture::new();
    let name = std::ffi::OsStr::from_bytes(b"bad-\xff.txt");
    fs::write(fixture.0.join(name), "Title: Unread").unwrap();
    let expected = scan(&fixture.0, 1, &["--content", "--filenames", "<unknown>"]);
    assert_eq!(
        scan(&fixture.0, 4, &["--content", "--filenames", "<unknown>"]),
        expected
    );
    assert_eq!(expected["dirs"]["@~"], json!(["bad-\u{fffd}.txt"]));
    let file = expected["files"]
        .as_object()
        .unwrap()
        .values()
        .next()
        .unwrap();
    assert_eq!(file["slug"], "<unknown>");
    assert!(file["content"].is_null());
}
