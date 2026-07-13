import { describe, expect, it } from 'vitest';
import { ENGINE_VERSION } from '../index.js';

describe('engine smoke', () => {
  it('is wired up', () => {
    expect(true).toBe(true);
  });

  it('exports a version constant', () => {
    expect(typeof ENGINE_VERSION).toBe('string');
  });
});
