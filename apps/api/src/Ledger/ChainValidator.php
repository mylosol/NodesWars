<?php

declare(strict_types=1);

namespace NodesWars\Api\Ledger;

/**
 * Validates a sequence of blocks for one player in one match.
 *
 * Chain rules (frozen, HANDOFF):
 *   - hash chain per player: block N's prevHash must equal block N-1's hash
 *   - seqNo strictly increments by 1 from a genesis block
 *   - the genesis block's prevHash is all zeros
 *   - Lamport timestamps are non decreasing
 *   - every block's signature verifies against its public key
 *   - every block's recomputed hash matches the chained value
 *
 * This is the server side gate. A block failing any rule is rejected as a
 * whole; the chain is append only and nothing is ever edited.
 */
final class ChainValidator
{
    public const GENESIS_PREV_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Validates a block against the previous block in the same player chain.
     *
     * @return list<string> empty when valid, otherwise every rule that failed
     */
    public static function validate(LedgerBlock $block, ?LedgerBlock $previous): array
    {
        $errors = [];

        if ($previous === null) {
            if ($block->seqNo !== 0) {
                $errors[] = 'first block for a player must have seqNo 0';
            }
            if ($block->prevHash !== self::GENESIS_PREV_HASH) {
                $errors[] = 'genesis block must have an all zero prevHash';
            }
        } else {
            if ($block->prevHash !== $previous->computeHash()) {
                $errors[] = 'prevHash does not match the previous block hash';
            }
            if ($block->seqNo !== $previous->seqNo + 1) {
                $errors[] = sprintf('seqNo must increment by 1, got %d after %d', $block->seqNo, $previous->seqNo);
            }
            if ($block->lamportTs < $previous->lamportTs) {
                $errors[] = 'Lamport timestamp went backwards';
            }
        }

        if (!Ed25519::verify($block->canonicalPreimage(), $block->signature, $block->publicKey)) {
            $errors[] = 'signature does not verify';
        }

        return $errors;
    }

    /**
     * Validates a whole chain of blocks for one player, in order.
     *
     * @param list<LedgerBlock> $blocks
     *
     * @return list<string> empty when valid, otherwise every rule that failed
     */
    public static function validateChain(array $blocks): array
    {
        $errors = [];
        $previous = null;

        foreach ($blocks as $block) {
            $errors = [...$errors, ...self::validate($block, $previous)];
            $previous = $block;
        }

        return $errors;
    }
}
