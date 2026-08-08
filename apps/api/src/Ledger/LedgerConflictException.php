<?php

declare(strict_types=1);

namespace NodesWars\Api\Ledger;

use RuntimeException;
use Throwable;

/**
 * Thrown when a block INSERT hits an existing row at the same
 * (match, player, seqNo). Carries both blocks so the caller can run
 * ForkResolver: identical hashes = duplicate delivery (keep existing),
 * different hashes = equivocation (slash both, roll back the player).
 */
final class LedgerConflictException extends RuntimeException
{
    public function __construct(
        public readonly LedgerBlock $submitted,
        public readonly LedgerBlock $existing,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Block conflict at match %s player %s seqNo %d',
                $submitted->matchId,
                $submitted->playerId,
                $submitted->seqNo,
            ),
            0,
            $previous,
        );
    }

    public function isDuplicate(): bool
    {
        return $this->submitted->computeHash() === $this->existing->computeHash();
    }
}
