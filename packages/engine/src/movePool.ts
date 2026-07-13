// Available moves per player level. Stub only.

export interface Move {
  readonly id: string;
  readonly name: string;
  readonly minLevel: number;
}

export function movesForLevel(_level: number): readonly Move[] {
  throw new Error('not implemented');
}
