<?php

declare(strict_types=1);

namespace NodesWars\Api\Ledger;

use PDO;

/**
 * Postgres repository for ledger blocks.
 *
 * Maps LedgerBlock to the schema in db/migrations/0004_ledger_blocks.sql.
 * The DB enforces the hard invariants (unique match/player/seqNo, and the
 * prev_hash check constraint if enabled), while this class translates
 * conflicts into the fork-resolution semantics of ForkResolver:
 *
 *   - a duplicate INSERT (same block replayed) is a no-op that returns the
 *     existing row
 *   - a conflicting INSERT (different block, same seqNo) surfaces the
 *     existing row so the caller can run ForkResolver and slash
 *
 * All writes are transactional. Nothing here mutates history; the table is
 * append only.
 */
final class LedgerRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Inserts a block. Returns the stored block (the one actually in the DB).
     *
     * @throws LedgerConflictException when a DIFFERENT block already exists
     *                                 at the same (match, player, seqNo)
     */
    public function insert(LedgerBlock $block, ?\DateTimeInterface $clientTs = null): LedgerBlock
    {
        try {
            $stmt = $this->pdo->prepare(
                <<<'SQL'
                INSERT INTO ledger_blocks
                    (match_id, player_id, seq_no, prev_hash, payload, sig, lamport_ts, client_ts, hash)
                VALUES
                    (:match_id, :player_id, :seq_no, :prev_hash, :payload, :sig, :lamport_ts, :client_ts, :hash)
                SQL,
            );
            // Binary columns (prev_hash, payload, sig, hash) must be bound
            // as PARAM_LOB, else PDO pgsql sends them as UTF8 text and
            // Postgres rejects non printable bytes.
            $stmt->bindValue('match_id', $block->matchId);
            $stmt->bindValue('player_id', $block->playerId);
            $stmt->bindValue('seq_no', $block->seqNo, PDO::PARAM_INT);
            $stmt->bindValue('prev_hash', hex2bin($block->prevHash) ?: '', PDO::PARAM_LOB);
            $stmt->bindValue('payload', $block->payload, PDO::PARAM_LOB);
            $stmt->bindValue('sig', $block->signature, PDO::PARAM_LOB);
            $stmt->bindValue('lamport_ts', $block->lamportTs, PDO::PARAM_INT);
            $stmt->bindValue('client_ts', ($clientTs ?? new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'));
            $stmt->bindValue('hash', hex2bin($block->computeHash()) ?: '', PDO::PARAM_LOB);
            $stmt->execute();
        } catch (\PDOException $e) {
            // 23505 = unique_violation. Load the existing row and let the
            // caller decide: same block = duplicate delivery, different
            // block = equivocation.
            if ($e->getCode() === '23505') {
                $existing = $this->find($block->matchId, $block->playerId, $block->seqNo);
                if ($existing !== null) {
                    throw new LedgerConflictException($block, $existing, $e);
                }
            }

            throw $e;
        }

        return $block;
    }

    /**
     * Fetches one block by its unique key.
     */
    public function find(string $matchId, string $playerId, int $seqNo): ?LedgerBlock
    {
        $stmt = $this->pdo->prepare(
            <<<'SQL'
            SELECT match_id, player_id, seq_no, prev_hash, payload, sig, lamport_ts, hash
            FROM ledger_blocks
            WHERE match_id = :match_id AND player_id = :player_id AND seq_no = :seq_no
            SQL,
        );
        $stmt->execute(['match_id' => $matchId, 'player_id' => $playerId, 'seq_no' => $seqNo]);

        return $this->rowToBlock($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * Fetches all blocks for a player in a match, in seqNo order.
     *
     * @return list<LedgerBlock>
     */
    public function chainFor(string $matchId, string $playerId): array
    {
        $stmt = $this->pdo->prepare(
            <<<'SQL'
            SELECT match_id, player_id, seq_no, prev_hash, payload, sig, lamport_ts, hash
            FROM ledger_blocks
            WHERE match_id = :match_id AND player_id = :player_id
            ORDER BY seq_no ASC
            SQL,
        );
        $stmt->execute(['match_id' => $matchId, 'player_id' => $playerId]);

        $blocks = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $blocks[] = $this->rowToBlock($row);
        }

        return $blocks;
    }

    /**
     * Deletes a player's blocks from seqNo (inclusive) onward. Used after
     * equivocation slashing to roll back the player's downstream state.
     */
    public function deleteFrom(string $matchId, string $playerId, int $seqNo): int
    {
        $stmt = $this->pdo->prepare(
            <<<'SQL'
            DELETE FROM ledger_blocks
            WHERE match_id = :match_id AND player_id = :player_id AND seq_no >= :seq_no
            SQL,
        );
        $stmt->execute(['match_id' => $matchId, 'player_id' => $playerId, 'seq_no' => $seqNo]);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function rowToBlock(array|false $row): ?LedgerBlock
    {
        if ($row === false) {
            return null;
        }

        // PDO pgsql returns bytea columns as PHP stream resources, not
        // strings. Unwrap them before use.
        $bin = static fn (mixed $v): string => is_resource($v) ? (string) stream_get_contents($v) : (string) $v;

        return LedgerBlock::create(
            matchId: (string) $row['match_id'],
            playerId: (string) $row['player_id'],
            seqNo: (int) $row['seq_no'],
            prevHash: bin2hex($bin($row['prev_hash'])),
            payload: $bin($row['payload']),
            signature: $bin($row['sig']),
            publicKey: '', // not stored; verified at insert time by the caller
            lamportTs: (int) $row['lamport_ts'],
        );
    }
}
