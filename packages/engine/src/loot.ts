// Deterministic loot rolls. Stub only.

export interface LootInput {
  readonly seed: string;
  readonly tableId: string;
  readonly playerLevel: number;
}

export interface LootRoll {
  readonly itemId: string;
  readonly quantity: number;
  readonly rarity: 'common' | 'uncommon' | 'rare' | 'epic' | 'legendary';
}

export function roll(_input: LootInput): readonly LootRoll[] {
  throw new Error('not implemented');
}
