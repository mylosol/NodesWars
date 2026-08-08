<?php

declare(strict_types=1);

namespace NodesWars\Api\Tests\Ledger;

use NodesWars\Api\Ledger\ChainValidator;
use NodesWars\Api\Ledger\Ed25519;
use NodesWars\Api\Ledger\LedgerBlock;
use PHPUnit\Framework\TestCase;

final class LedgerBlockTest extends TestCase
{
    private function make(int $seqNo = 0, int $lamportTs = 1, string $payload = 'strike'): LedgerBlock
    {
        $kp = Ed25519::keypair();
        $unsigned = LedgerBlock::create(
            matchId: 'm1',
            playerId: 'p1',
            seqNo: $seqNo,
            prevHash: ChainValidator::GENESIS_PREV_HASH,
            payload: $payload,
            signature: str_repeat("\x00", 64),
            publicKey: $kp['publicKey'],
            lamportTs: $lamportTs,
        );

        return LedgerBlock::create(
            matchId: 'm1',
            playerId: 'p1',
            seqNo: $seqNo,
            prevHash: ChainValidator::GENESIS_PREV_HASH,
            payload: $payload,
            signature: Ed25519::sign($unsigned->canonicalPreimage(), $kp['secretKey']),
            publicKey: $kp['publicKey'],
            lamportTs: $lamportTs,
        );
    }

    public function test_hash_is_64_hex_chars(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->make()->computeHash());
    }

    public function test_hash_changes_when_payload_changes(): void
    {
        $this->assertNotSame(
            $this->make(payload: 'strike')->computeHash(),
            $this->make(payload: 'fortify')->computeHash(),
        );
    }

    public function test_hash_changes_when_lamport_ts_changes(): void
    {
        $this->assertNotSame(
            $this->make(lamportTs: 1)->computeHash(),
            $this->make(lamportTs: 2)->computeHash(),
        );
    }

    public function test_hash_changes_when_seq_no_changes(): void
    {
        $this->assertNotSame(
            $this->make(seqNo: 0)->computeHash(),
            $this->make(seqNo: 1)->computeHash(),
        );
    }

    public function test_hash_is_deterministic(): void
    {
        $a = $this->make();
        $b = $this->make();

        $this->assertSame($a->computeHash(), $b->computeHash());
    }

    public function test_genesis_flag(): void
    {
        $this->assertTrue($this->make()->isGenesis());
        $this->assertFalse($this->make(seqNo: 1)->isGenesis());
    }

    public function test_canonical_preimage_contains_prev_hash_seq_lamport_payload(): void
    {
        $block = $this->make(seqNo: 3, lamportTs: 42, payload: 'strike');
        $preimage = $block->canonicalPreimage();

        // prevHash (64 hex chars) + u64 seqNo + u64 lamportTs + payload
        $this->assertSame(64 + 8 + 8 + strlen('strike'), strlen($preimage));
        $this->assertStringStartsWith(ChainValidator::GENESIS_PREV_HASH, $preimage);
        // seqNo 3 -> \x00 x7 then \x03; lamportTs 42 -> \x00 x7 then \x2a.
        $this->assertStringContainsString("\x00\x00\x00\x00\x00\x00\x00\x03", $preimage);
        $this->assertStringContainsString("\x00\x00\x00\x00\x00\x00\x00\x2a", $preimage);
        $this->assertStringEndsWith('strike', $preimage);
    }

    public function test_rejects_negative_seq_no(): void
    {
        $kp = Ed25519::keypair();
        $this->expectException(\InvalidArgumentException::class);
        LedgerBlock::create(
            matchId: 'm1',
            playerId: 'p1',
            seqNo: -1,
            prevHash: ChainValidator::GENESIS_PREV_HASH,
            payload: 'strike',
            signature: str_repeat("\x00", 64),
            publicKey: $kp['publicKey'],
            lamportTs: 1,
        );
    }

    public function test_rejects_negative_lamport_ts(): void
    {
        $kp = Ed25519::keypair();
        $this->expectException(\InvalidArgumentException::class);
        LedgerBlock::create(
            matchId: 'm1',
            playerId: 'p1',
            seqNo: 0,
            prevHash: ChainValidator::GENESIS_PREV_HASH,
            payload: 'strike',
            signature: str_repeat("\x00", 64),
            publicKey: $kp['publicKey'],
            lamportTs: -1,
        );
    }
}
