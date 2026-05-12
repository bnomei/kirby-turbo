set shell := ["bash", "-eu", "-o", "pipefail", "-c"]

root := justfile_directory()
turbo_dir := root + "/turbo"

default:
    @just --list

rust-targets:
    rustup target add x86_64-unknown-linux-musl x86_64-apple-darwin aarch64-apple-darwin

test-rust:
    cd "{{turbo_dir}}" && cargo test --locked

build-linux: rust-targets
    cd "{{turbo_dir}}" && cargo build --release --target x86_64-unknown-linux-musl --locked
    cp "{{turbo_dir}}/target/x86_64-unknown-linux-musl/release/turbo" "{{root}}/bin/turbo"
    chmod +x "{{root}}/bin/turbo"
    file "{{root}}/bin/turbo"

build-darwin: rust-targets
    cd "{{turbo_dir}}" && cargo build --release --target x86_64-apple-darwin --locked
    cd "{{turbo_dir}}" && cargo build --release --target aarch64-apple-darwin --locked
    lipo -create "{{turbo_dir}}/target/x86_64-apple-darwin/release/turbo" "{{turbo_dir}}/target/aarch64-apple-darwin/release/turbo" -output "{{root}}/bin/turbo-darwin"
    chmod +x "{{root}}/bin/turbo-darwin"
    lipo -archs "{{root}}/bin/turbo-darwin"
    file "{{root}}/bin/turbo-darwin"

build-bins: build-linux build-darwin
    @echo "built bin/turbo and bin/turbo-darwin"

verify-bins:
    file "{{root}}/bin/turbo" "{{root}}/bin/turbo-darwin"
    lipo -archs "{{root}}/bin/turbo-darwin"
