<?php

declare(strict_types=1);

namespace NodesWars\Api\Ledger;

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
 * for the slashed player.
 */
final class ForkResolver
{
    /**
     * Resolves which block survives a conflict at one seqNo.
     *
     * @param list<LedgerBlock> $candidates two or more blocks, same player,
     *                                      same match, same seqNo
     *
     * @return array{keep: ?LedgerBlock, equivocated: bool, discarded: list<LedgerBlock>}
     */
    public static function resolve(array $candidates): array
    {
        if (count($candidates) < 2) {
            return ['keep' => $candidates[0] ?? null, 'equivocated' => false, 'discarded' => []];
        }

        // Group deliveries by hash. Two DISTINCT hashes at the same seqNo
        // from one player = equivocation: both are discarded. One hash
        // delivered twice = duplicate delivery: keep one, discard the copies.
        $byHash = [];
        foreach ($candidates as $block) {
            $byHash[$block->computeHash()][] = $block;
        }

        if (count($byHash) > 1) {
            return [
                'keep' => null,
                'equivocated' => true,
                'discarded' => array_merge(...array_values($byHash)),
            ];
        }

        $deliveries = reset($byHash);

        return [
            'keep' => $deliveries[0],
            'equivocated' => false,
            'discarded' => array_slice($deliveries, 1),
        ];
    }

    /**
     * Lamport ordering for two blocks, regardless of seqNo. Returns -1, 0, 1.
     * Used to order blocks into a single timeline when merging histories.
     */
    public static function compare(LedgerBlock $a, LedgerBlock $b): int
    {
        if ($a->lamportTs !== $b->lamportTs) {
            return $a->lamportTs <=> $b->lamportTs;
        }

        return strcmp($a->computeHash(), $b->computeHash());
    }
}
