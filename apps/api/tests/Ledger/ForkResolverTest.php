<?php

declare(strict_types=1);

namespace NodesWars\Api\Tests\Ledger;

use NodesWars\Api\Ledger\ChainValidator;
use NodesWars\Api\Ledger\Ed25519;
use NodesWars\Api\Ledger\ForkResolver;
use NodesWars\Api\Ledger\LedgerBlock;
use PHPUnit\Framework\TestCase;

final class ForkResolverTest extends TestCase
{
    /**
     * Two blocks with the same seqNo (0) but different payloads and
     * Lamport timestamps, signed by the same player. This is the classic
     * equivocation: the player sent two different blocks for the same slot.
     *
     * @return array{0: LedgerBlock, 1: LedgerBlock}
     */
    private function equivocatingPair(int $tsA, int $tsB): array
    {
        $kp = Ed25519::keypair();

        $make = static function (int $ts, string $payload) use ($kp): LedgerBlock {
            $unsigned = LedgerBlock::create(
                matchId: 'm1',
                playerId: 'p1',
                seqNo: 0,
                prevHash: ChainValidator::GENESIS_PREV_HASH,
                payload: $payload,
                signature: str_repeat("\x00", 64),
                publicKey: $kp['publicKey'],
                lamportTs: $ts,
            );
            $sig = Ed25519::sign($unsigned->canonicalPreimage(), $kp['secretKey']);

            return LedgerBlock::create(
                matchId: 'm1',
                playerId: 'p1',
                seqNo: 0,
                prevHash: ChainValidator::GENESIS_PREV_HASH,
                payload: $payload,
                signature: $sig,
                publicKey: $kp['publicKey'],
                lamportTs: $ts,
            );
        };

        return [$make($tsA, 'strike'), $make($tsB, 'fortify')];
    }

    public function test_single_candidate_is_kept_without_equivocation(): void
    {
        [$a] = $this->equivocatingPair(1, 2);
        $result = ForkResolver::resolve([$a]);

        $this->assertFalse($result['equivocated']);
        $this->assertSame($a, $result['keep']);
        $this->assertSame([], $result['discarded']);
    }

    public function test_two_distinct_blocks_same_seq_no_are_both_discarded(): void
    {
        [$a, $b] = $this->equivocatingPair(1, 2);
        $result = ForkResolver::resolve([$a, $b]);

        $this->assertTrue($result['equivocated']);
        $this->assertNull($result['keep']);
        $this->assertCount(2, $result['discarded']);
    }

    public function test_duplicate_delivery_of_the_same_block_is_kept_once(): void
    {
        [$a] = $this->equivocatingPair(1, 1);
        $result = ForkResolver::resolve([$a, $a]);

        $this->assertFalse($result['equivocated']);
        $this->assertSame($a, $result['keep']);
        $this->assertCount(1, $result['discarded']);
    }

    public function test_compare_orders_by_lamport_timestamp(): void
    {
        [$a, $b] = $this->equivocatingPair(1, 2);

        $this->assertLessThan(0, ForkResolver::compare($a, $b));
        $this->assertGreaterThan(0, ForkResolver::compare($b, $a));
    }

    public function test_compare_breaks_ties_with_hash(): void
    {
        // Same lamportTs, different payloads -> different hashes, so the
        // comparison must be non zero and consistent both ways.
        [$a, $b] = $this->equivocatingPair(5, 5);

        $forward = ForkResolver::compare($a, $b);
        $backward = ForkResolver::compare($b, $a);

        $this->assertNotSame(0, $forward);
        $this->assertSame(-$forward, $backward);
    }

    public function test_compare_is_zero_for_identical_blocks(): void
    {
        [$a] = $this->equivocatingPair(5, 5);
        $this->assertSame(0, ForkResolver::compare($a, $a));
    }

    public function test_empty_candidates_resolve_to_nothing(): void
    {
        $result = ForkResolver::resolve([]);
        $this->assertNull($result['keep']);
        $this->assertFalse($result['equivocated']);
    }
}
