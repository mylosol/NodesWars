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
import * as trajectory from '../trajectory.js';

// A case's `op` is "module.fn" (or bare, meaning fixedPoint).
//
// Arguments encode their type by JSON type, recursively:
//   string  a Fixed int64
//   number  a plain int
//   object  a struct; every value is decoded by these same rules
//   array   a list; every element is decoded by these same rules
//
// The exception is arguments listed in RAW_STRING_ARGS, whose string is
// literal text (a decimal for fromString, a weapon id for blast).
//
// `expected` is the result with every leaf rendered as a string, in the same
// shape the function returns. Comparison normalises the actual result the same
// way and deep-equals, so scalars, structs, nested structs and lists of structs
// all work without special cases.
//
// The encoding is language-agnostic: the PHP engine runs the same JSON.
type Json = string | number | boolean | Json[] | { [k: string]: Json };
type Case = { id: string; op: string; args: Json[]; expected: Json };

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
  trajectory: trajectory as never,
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

// Struct fields holding literal text. Same idea as RAW_STRING_ARGS, one level
// down: blast.resolve takes its weapon id inside the input object.
const RAW_STRING_FIELDS = new Set(['weaponId']);

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

/** Decodes one argument value, recursing through structs and lists. */
function decode(value: Json, raw: boolean): unknown {
  if (Array.isArray(value)) return value.map((v) => decode(v, raw));
  if (value !== null && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value).map(([k, v]) => [k, decode(v, RAW_STRING_FIELDS.has(k))]),
    );
  }
  if (raw) return value;
  return typeof value === 'string' ? BigInt(value) : value;
}

/** Renders a result so every leaf is a string, preserving shape. */
function normalise(value: unknown): Json {
  if (Array.isArray(value)) return value.map(normalise);
  if (typeof value === 'bigint' || typeof value === 'number') return String(value);
  if (typeof value === 'boolean') return value ? 'true' : 'false';
  if (typeof value === 'string') return value;
  if (value !== null && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value as Record<string, unknown>).map(([k, v]) => [k, normalise(v)]),
    );
  }
  throw new Error(`cannot normalise ${String(value)}`);
}

function run(c: Case): unknown {
  const fn = resolve(c.op);
  const raw = RAW_STRING_ARGS[qualify(c.op)] ?? [];
  const args = c.args.map((a, i) => decode(a, raw.includes(i)));
  return fn(...(args as never[]));
}

describe('engine golden fixtures', () => {
  it('has cases', () => {
    expect(cases.length).toBeGreaterThan(0);
  });

  for (const c of cases) {
    it(`${c.id} (${c.op})`, () => {
      expect(normalise(run(c))).toEqual(c.expected);
    });
  }
});
