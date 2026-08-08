import { LedgerBlock } from './ledgerBlock.js';

export interface ForkResolution {
  keep: LedgerBlock | null;
  equivocated: boolean;
  discarded: LedgerBlock[];
}

/**
 * Resolves conflicting blocks at the same seqNo (frozen, HANDOFF):
 *
 *   - Lamport timestamp with hash tiebreak. The block with the higher
 *     lamportTs wins; if equal, the lexicographically larger chain hash wins.
 *   - Equivocation slashing: a player who submits TWO blocks at the same
 *     seqNo gets BOTH discarded and their downstream state rolled back.
 *
 * The resolver is pure: it takes the candidate blocks and returns which to
 * keep (if any). The caller is responsible for rolling back downstream state
 * for the slashed player. Mirrors apps/api/src/Ledger/ForkResolver.php.
 */
export class ForkResolver {
  /**
   * Resolves which block survives a conflict at one seqNo.
   *
   * @param candidates two or more blocks, same player, same match, same seqNo
   */
  static resolve(candidates: LedgerBlock[]): ForkResolution {
    if (candidates.length < 2) {
      return { keep: candidates[0] ?? null, equivocated: false, discarded: [] };
    }

    // Group deliveries by hash. Two DISTINCT hashes at the same seqNo from
    // one player = equivocation: both are discarded. One hash delivered
    // twice = duplicate delivery: keep one, discard the copies.
    const byHash = new Map<string, LedgerBlock[]>();
    for (const block of candidates) {
      const hash = block.computeHash();
      const deliveries = byHash.get(hash);
      if (deliveries === undefined) {
        byHash.set(hash, [block]);
      } else {
        deliveries.push(block);
      }
    }

    if (byHash.size > 1) {
      return {
        keep: null,
        equivocated: true,
        discarded: [...byHash.values()].flat(),
      };
    }

    const deliveries = [...byHash.values()][0] ?? [];

    return {
      keep: deliveries[0] ?? null,
      equivocated: false,
      discarded: deliveries.slice(1),
    };
  }

  /**
   * Lamport ordering for two blocks, regardless of seqNo. Returns -1, 0, 1.
   * Used to order blocks into a single timeline when merging histories.
   * Ties break on lexicographic comparison of the chain hash hex strings.
   */
  static compare(a: LedgerBlock, b: LedgerBlock): number {
    if (a.lamportTs !== b.lamportTs) {
      return a.lamportTs < b.lamportTs ? -1 : 1;
    }

    const hashA = a.computeHash();
    const hashB = b.computeHash();
    if (hashA < hashB) {
      return -1;
    }
    if (hashA > hashB) {
      return 1;
    }

    return 0;
  }
}
