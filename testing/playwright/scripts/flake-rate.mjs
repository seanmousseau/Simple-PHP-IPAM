#!/usr/bin/env node
// Compute the flake rate from a Playwright JSON report.
//
// Usage:
//   node scripts/flake-rate.mjs [path/to/results.json]
//
// Default path is playwright-report/results.json (set by the 'json' reporter
// in playwright.config.ts).
//
// Output: single line  "flake_rate=X.XX% (Y flaky / Z total)"  plus an exit
// code of 0 (green), 1 (yellow, 2–5%), or 2 (red, >5%). CI can gate releases
// on the exit code or just log the number.
//
// A test is counted as "flaky" when it has more than one attempt and ultimately
// passed (i.e. failed on attempt 1, passed on retry). Tests that fail on all
// attempts are real failures, not flakes.

import { readFile } from 'node:fs/promises';
import { argv, exit } from 'node:process';

const path = argv[2] ?? 'playwright-report/results.json';

let data;
try {
  data = JSON.parse(await readFile(path, 'utf8'));
} catch (err) {
  console.error(`flake-rate: cannot read ${path}: ${err.message}`);
  exit(3);
}

let total = 0;
let flaky = 0;

function walk(node) {
  if (Array.isArray(node?.suites)) for (const s of node.suites) walk(s);
  if (Array.isArray(node?.specs)) {
    for (const spec of node.specs) {
      for (const t of spec.tests ?? []) {
        total += 1;
        const results = t.results ?? [];
        const attempts = results.length;
        const finalStatus = results[results.length - 1]?.status;
        if (attempts > 1 && finalStatus === 'passed') flaky += 1;
      }
    }
  }
}

walk(data);

if (total === 0) {
  console.error('flake-rate: no tests found in report');
  exit(3);
}

const rate = (flaky / total) * 100;
console.log(`flake_rate=${rate.toFixed(2)}% (${flaky} flaky / ${total} total)`);

if (rate > 5)      exit(2);   // red
else if (rate > 2) exit(1);   // yellow
else               exit(0);   // green
