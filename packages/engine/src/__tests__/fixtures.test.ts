import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import * as blast from '../blast.js';
import * as fixedPoint from '../fixedPoint.js';
import * as fortify from '../fortify.js';
import * as levelCurve from '../levelCurve.js';
import * as loot from '../loot.js';
import * as movePool from '../movePool.js';
import * as scoring from '../scoring.js';

// A case's `op` is "module.fn" (or bare, meaning fixedPoint). Args encode their
// type by JSON type: a string is a Fixed int64, a number is a plain int. The one
// exception is fromString, whose argument is a decimal string. `expected` is a
// string for a scalar Fixed/number result, or an object for a struct result.
// This encoding is language-agnostic so the PHP port runs the same JSON.
type Expected = string | Record<string, string>;
type Case = { id: string; op: string; args: (string | number)[]; expected: Expected };

const path = fileURLToPath(new URL('../../../../test-fixtures/engine-cases.json', import.meta.url));
const { cases } = JSON.parse(readFileSync(path, 'utf8')) as { cases: Case[] };

const modules: Record<string, Record<string, (...a: never[]) => unknown>> = {
  fixedPoint: fixedPoint as never,
  loot: loot as never,
  scoring: scoring as never,
  movePool: movePool as never,
  levelCurve: levelCurve as never,
  blast: blast as never,
  fortify: fortify as never,
};

// Argument positions holding literal text rather than a Fixed int64. This is
// per argument, not per op, because blast.damageAt takes a weapon id *and* a
// Fixed distance. The PHP runner keeps an identical table.
const RAW_STRING_ARGS: Record<string, readonly number[]> = {
  'fixedPoint.fromString': [0],
  'blast.radiusFor': [0],
  'blast.damageFor': [0],
  'blast.damageAt': [0],
  'loot.applyReward': [2],
  'loot.effectiveMultiplier': [1],
};

/** Bare ops belong to fixedPoint. */
function qualify(op: string): string {
  return op.includes('.') ? op : `fixedPoint.${op}`;
}

function resolve(op: string): (...a: never[]) => unknown {
  const [modName, fnName] = qualify(op).split('.');
  const fn = modules[modName as string]?.[fnName as string];
  if (typeof fn !== 'function') throw new Error(`unknown op ${op}`);
  return fn;
}

function coerceArg(op: string, a: string | number, index: number): unknown {
  if (RAW_STRING_ARGS[qualify(op)]?.includes(index)) return a;
  return typeof a === 'string' ? BigInt(a) : a;
}

function run(c: Case): unknown {
  const fn = resolve(c.op);
  const args = c.args.map((a, i) => coerceArg(c.op, a, i));
  return fn(...(args as never[]));
}

describe('engine golden fixtures', () => {
  it('has cases', () => {
    expect(cases.length).toBeGreaterThan(0);
  });
  for (const c of cases) {
    it(`${c.id} (${c.op})`, () => {
      const result = run(c);
      if (typeof c.expected === 'string') {
        expect(String(result)).toBe(c.expected);
      } else {
        const obj = result as Record<string, unknown>;
        for (const [field, value] of Object.entries(c.expected)) {
          expect(String(obj[field])).toBe(value);
        }
      }
    });
  }
});
