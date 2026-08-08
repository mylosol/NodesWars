<?php

declare(strict_types=1);

namespace NodesWars\Api\Ledger;

/**
 * Immutable signed block in the match ledger.
 *
 * Mirrors db/migrations/0004_ledger_blocks.sql. A block is one player action
 * (strike, fortify, bounty) chained to the previous block from the SAME
 * player via prevHash, carrying a Lamport timestamp for cross player ordering
 * and a detached Ed25519 signature over the canonical preimage.
 *
 * Canonical preimage (what gets hashed and signed):
 *   prevHash || seqNo(u64 BE) || lamportTs(u64 BE) || payload
 *
 * This encoding is the parity contract with the TypeScript ledger. The TS
 * port must reproduce it byte for byte, or signatures and chain hashes will
 * diverge across client and server.
 */
final class LedgerBlock
{
    /**
     * @param non-empty-string $matchId
     * @param non-empty-string $playerId
     * @param non-empty-string $prevHash hex, 64 chars; zero string for genesis
     * @param non-empty-string $payload   CBOR action payload (encrypted)
     * @param non-empty-string $signature 64 byte Ed25519 signature, binary
     * @param non-empty-string $publicKey 32 byte Ed25519 public key, binary
     */
    private function __construct(
        public readonly string $matchId,
        public readonly string $playerId,
        public readonly int $seqNo,
        public readonly string $prevHash,
        public readonly string $payload,
        public readonly string $signature,
        public readonly string $publicKey,
        public readonly int $lamportTs,
    ) {
    }

    /**
     * @param non-empty-string $matchId
     * @param non-empty-string $playerId
     * @param non-empty-string $prevHash hex, 64 chars; zero string for genesis
     * @param non-empty-string $payload
     * @param non-empty-string $signature 64 byte Ed25519 signature, binary
     * @param string            $publicKey 32 byte Ed25519 public key, binary.
     *                                     May be empty when a block is read
     *                                     back from storage (the key lives on
     *                                     the players table, not the block).
     */
    public static function create(
        string $matchId,
        string $playerId,
        int $seqNo,
        string $prevHash,
        string $payload,
        string $signature,
        string $publicKey,
        int $lamportTs,
    ): self {
        if ($seqNo < 0) {
            throw new \InvalidArgumentException('seqNo must be non negative');
        }
        if ($lamportTs < 0) {
            throw new \InvalidArgumentException('lamportTs must be non negative');
        }

        return new self($matchId, $playerId, $seqNo, $prevHash, $payload, $signature, $publicKey, $lamportTs);
    }

    /**
     * The exact bytes the client signs and the chain hashes. Never change
     * this layout without updating the TypeScript ledger and all golden
     * fixtures, or every historical block stops validating.
     */
    public function canonicalPreimage(): string
    {
        return $this->prevHash
            . self::u64($this->seqNo)
            . self::u64($this->lamportTs)
            . $this->payload;
    }

    /**
     * BLAKE2b-512 of the canonical preimage, hex encoded. The chain hash is
     * the preimage hash, not the payload hash, so a replay with a different
     * lamportTs or seqNo produces a different hash and is caught by the chain.
     */
    public function computeHash(): string
    {
        return sodium_bin2hex(sodium_crypto_generichash($this->canonicalPreimage()));
    }

    public function isGenesis(): bool
    {
        return $this->seqNo === 0;
    }

    private static function u64(int $value): string
    {
        return pack('J', $value);
    }
}
