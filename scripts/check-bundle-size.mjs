// Fails the build if the PWA's JavaScript bundle grows past its budget.
//
// The app is still nearly empty: today's ~190 kB is React and little else.
// Leaflet, framer-motion, Sentry, dexie, cbor-x and the Meshtastic client are
// all dependencies that nothing imports yet, and each will dwarf React when it
// lands. The budget exists to make that growth a deliberate decision rather
// than something noticed months later.
//
// Raising the limit is fine when a feature genuinely needs it. Raising it
// without noticing is what this prevents.
//
// Run from the repo root, after building: node scripts/check-bundle-size.mjs

import { gzipSync } from 'node:zlib';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const ASSETS_DIR = 'apps/pwa/dist/assets';

const LIMITS = {
  raw: 250 * 1024,
  gzip: 75 * 1024,
};

const kb = (bytes) => (bytes / 1024).toFixed(2) + ' kB';

let files;
try {
  files = readdirSync(ASSETS_DIR).filter((f) => f.endsWith('.js'));
} catch {
  console.error(`Bundle check: ${ASSETS_DIR} not found. Build the PWA first.`);
  process.exit(1);
}

if (files.length === 0) {
  console.error(`Bundle check: no .js files in ${ASSETS_DIR}. Did the build succeed?`);
  process.exit(1);
}

let raw = 0;
let gzip = 0;
const rows = [];

for (const file of files.sort()) {
  const contents = readFileSync(join(ASSETS_DIR, file));
  const g = gzipSync(contents).length;
  raw += contents.length;
  gzip += g;
  rows.push({ file, raw: kb(contents.length), gzip: kb(g) });
}

console.log('PWA JavaScript bundle:');
for (const row of rows) {
  console.log(`  ${row.file}  ${row.raw}  (${row.gzip} gzip)`);
}
console.log(`  total: ${kb(raw)} raw, ${kb(gzip)} gzip`);
console.log(`  budget: ${kb(LIMITS.raw)} raw, ${kb(LIMITS.gzip)} gzip`);

const failures = [];
if (raw > LIMITS.raw) {
  failures.push(`raw ${kb(raw)} exceeds ${kb(LIMITS.raw)}`);
}
if (gzip > LIMITS.gzip) {
  failures.push(`gzip ${kb(gzip)} exceeds ${kb(LIMITS.gzip)}`);
}

if (failures.length > 0) {
  console.error('\nBundle budget exceeded:');
  for (const f of failures) console.error(`  ${f}`);
  console.error(
    '\nIf this growth is intended, raise LIMITS in scripts/check-bundle-size.mjs\n' +
      'in the same commit, so the increase is reviewed rather than absorbed.',
  );
  process.exit(1);
}

const headroom = (((LIMITS.gzip - gzip) / LIMITS.gzip) * 100).toFixed(0);
console.log(`\nWithin budget. ${headroom}% gzip headroom remaining.`);
