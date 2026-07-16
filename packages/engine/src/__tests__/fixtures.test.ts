import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import * as fp from '../fixedPoint.js';

type Case = { id: string; op: string; args: (string | number)[]; expected: string };

const path = fileURLToPath(
  new URL('../../../../test-fixtures/engine-cases.json', import.meta.url),
);
const { cases } = JSON.parse(readFileSync(path, 'utf8')) as { cases: Case[] };

// These ops take raw JS args (numbers/strings); the rest take Fixed int64 strings.
const rawOps = new Set(['fromInt', 'fromString', 'fromParts']);

function run(c: Case): bigint {
  const fn = (fp as Record<string, (...a: never[]) => bigint>)[c.op];
  if (typeof fn !== 'function') throw new Error(`unknown op ${c.op}`);
  const args = rawOps.has(c.op) ? c.args : c.args.map((a) => BigInt(a as string));
  return fn(...(args as never[]));
}

describe('engine golden fixtures', () => {
  it('has cases', () => {
    expect(cases.length).toBeGreaterThan(0);
  });
  for (const c of cases) {
    it(`${c.id} (${c.op})`, () => {
      expect(run(c).toString()).toBe(c.expected);
    });
  }
});
