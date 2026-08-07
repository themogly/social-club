// Prompt 179 — copy the in-browser MRZ reader's runtime into public/ocr/ so it is served SAME-ORIGIN.
//
// Why a copy step rather than a CDN: prompt 128 ruled out sending Article 9 material to a third party, and
// the whole argument for reading in the browser is that the ID image never leaves the applicant's device.
// Fetching the engine from unpkg would not send the image anywhere — but it would put a third party on the
// critical path of an identity-document flow, and it is avoidable, so it is avoided.
//
// Why a copy step rather than committing the binaries: they are ~6 MB, they are already an npm dependency,
// and `npm ci && npm run build` is already the deploy sequence. public/ocr is gitignored for the same
// reason public/build is.
//
// If this has not run, the files are simply absent — the reader fails to load and the applicant fills the
// form as they do today. That is the specified behaviour for an unsupported browser, so a missing build
// degrades rather than breaks.

import { mkdirSync, copyFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

const OUT = resolve('public/ocr');
mkdirSync(OUT, { recursive: true });

// One core variant only. The package ships several (simd / relaxed-simd / lstm-only); the LSTM build is the
// smallest that reads an MRZ, and an MRZ is machine-printed OCR-B rather than handwriting.
const FILES = [
  ['node_modules/tesseract.js/dist/worker.min.js', 'worker.min.js'],
  ['node_modules/tesseract.js-core/tesseract-core-lstm.wasm.js', 'tesseract-core-lstm.wasm.js'],
  ['node_modules/tesseract.js-core/tesseract-core-lstm.wasm', 'tesseract-core-lstm.wasm'],
  // `best_int` is 3 MB against 10.9 MB for the full model, which matters on a phone on club wifi.
  ['node_modules/@tesseract.js-data/eng/4.0.0_best_int/eng.traineddata.gz', 'eng.traineddata.gz'],
];

let copied = 0;
for (const [from, to] of FILES) {
  const src = resolve(from);
  if (!existsSync(src)) {
    console.warn(`vendor-ocr: missing ${from} — run npm install`);
    continue;
  }
  copyFileSync(src, resolve(OUT, to));
  copied++;
}

console.log(`vendor-ocr: ${copied}/${FILES.length} files → public/ocr/`);
