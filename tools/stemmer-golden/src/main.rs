//! Emit the golden stems for one language using Pagefind's own stemmer crate.
//!
//! Usage: stemmer-golden <lang> <words.txt> <out.txt>
//!   lang ∈ {ca, da, de, en, es, fi, fr, it, nl, no, pt, ro, ru, sv}
//!
//! Reads one word per line, lowercases it (Pagefind lowercases query tokens
//! before stemming), stems it with `pagefind_stem`, and writes one stem per
//! line — preserving the input's trailing-newline structure exactly so the
//! output is byte-comparable to the committed expected-stems.txt fixture.

use pagefind_stem::{Algorithm, Stemmer};
use std::{env, fs};

fn algo(lang: &str) -> Algorithm {
    // The same code->algorithm map Pagefind 1.5.0 builds its per-language
    // query WASM with (pagefind_web/Cargo.toml): nl is the modern Dutch
    // algorithm, not Dutch_porter.
    match lang {
        "ca" => Algorithm::Catalan,
        "da" => Algorithm::Danish,
        "de" => Algorithm::German,
        "en" => Algorithm::English,
        "es" => Algorithm::Spanish,
        "fi" => Algorithm::Finnish,
        "fr" => Algorithm::French,
        "it" => Algorithm::Italian,
        "nl" => Algorithm::Dutch,
        "no" => Algorithm::Norwegian,
        "pt" => Algorithm::Portuguese,
        "ro" => Algorithm::Romanian,
        "ru" => Algorithm::Russian,
        "sv" => Algorithm::Swedish,
        other => panic!("unsupported language: {other}"),
    }
}

fn main() {
    let args: Vec<String> = env::args().collect();
    if args.len() != 4 {
        eprintln!("usage: stemmer-golden <lang> <words.txt> <out.txt>");
        std::process::exit(2);
    }
    let stemmer = Stemmer::create(algo(&args[1]));
    let text = fs::read_to_string(&args[2]).expect("read words file");
    let lines: Vec<&str> = text.split('\n').collect();
    let n = lines.len();
    let mut out = String::new();
    for (i, word) in lines.iter().enumerate() {
        // The corpus ends with a trailing newline, so the final split element is
        // empty — skip it to reproduce the fixture's structure.
        if i == n - 1 && word.is_empty() {
            break;
        }
        out.push_str(&stemmer.stem(&word.to_lowercase()));
        out.push('\n');
    }
    fs::write(&args[3], out).expect("write out file");
}
