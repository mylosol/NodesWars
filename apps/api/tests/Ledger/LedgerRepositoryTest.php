<?php

declare(strict_types=1);

namespace NodesWars\Api\Tests\Ledger;

use NodesWars\Api\Ledger\ChainValidator;
use NodesWars\Api\Ledger\Ed25519;
use NodesWars\Api\Ledger\LedgerBlock;
use NodesWars\Api\Ledger\LedgerConflictException;
use NodesWars\Api\Ledger\LedgerRepository;
use PDO;

/**
 * Integration tests against a real Postgres.
 *
 * Set NODESWARS_PG_DSN (and optionally NODESWARS_PG_USER / NODESWARS_PG_PASS)
 * to run them. They are skipped when the variable is absent so the default
 * `composer test` run still passes on machines without Postgres.
 *
 * Example:
 *   NODESWARS_PG_DSN="pgsql:host=127.0.0.1;port=54329;dbname=nodeswars_test" \
 *   NODESWARS_PG_USER=postgres NODESWARS_PG_PASS=test \
 *   ./vendor/bin/phpunit --filter LedgerRepositoryTest
 */
final class LedgerRepositoryTest extends \PHPUnit\Framework\TestCase
{
    private PDO $pdo;
    private LedgerRepository $repo;
    private string $matchId;
    private string $playerId;

    protected function setUp(): void
    {
        $dsn = getenv('NODESWARS_PG_DSN');
        if ($dsn === false) {
            self::markTestSkipped('NODESWARS_PG_DSN not set; skipping Postgres integration test');
        }

        $user = getenv('NODESWARS_PG_USER') ?: 'postgres';
        $pass = getenv('NODESWARS_PG_PASS') ?: '';

        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $this->repo = new LedgerRepository($this->pdo);

        // A fresh player and match per test, so assertions never collide
        // with rows from a previous run.
        $this->playerId = $this->insertPlayer();
        $this->matchId = $this->insertMatch();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('TRUNCATE ledger_blocks, match_players, matches, players RESTART IDENTITY CASCADE');
        }
    }

    private function insertPlayer(): string
    {
        $kp = Ed25519::keypair();
        $email = 'p'.bin2hex(random_bytes(6)).'@test.local';
        $stmt = $this->pdo->prepare('INSERT INTO players (email, ed25519_pubkey) VALUES (:email, :key) RETURNING id');
        $stmt->bindValue('email', $email);
        $stmt->bindValue('key', $kp['publicKey'], PDO::PARAM_LOB);
        $stmt->execute();

        return (string) $stmt->fetchColumn();
    }

    private function insertMatch(): string
    {
        $stmt = $this->pdo->prepare('INSERT INTO matches (gm_player_id) VALUES (:gm) RETURNING id');
        $stmt->execute(['gm' => $this->playerId]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * Builds a signed genesis block for the test player.
     */
    private function block(array $kp, int $seqNo = 0, string $payload = 'strike', int $lamportTs = 1): LedgerBlock
    {
        $unsigned = LedgerBlock::create(
            matchId: $this->matchId,
            playerId: $this->playerId,
            seqNo: $seqNo,
            prevHash: ChainValidator::GENESIS_PREV_HASH,
            payload: $payload,
            signature: str_repeat("\x00", 64),
            publicKey: $kp['publicKey'],
            lamportTs: $lamportTs,
        );
        $sig = Ed25519::sign($unsigned->canonicalPreimage(), $kp['secretKey']);

        return LedgerBlock::create(
            matchId: $this->matchId,
            playerId: $this->playerId,
            seqNo: $seqNo,
            prevHash: ChainValidator::GENESIS_PREV_HASH,
            payload: $payload,
            signature: $sig,
            publicKey: $kp['publicKey'],
            lamportTs: $lamportTs,
        );
    }

    public function test_insert_then_find_roundtrip(): void
    {
        $kp = Ed25519::keypair();
        $block = $this->block($kp);
        $this->repo->insert($block);

        $found = $this->repo->find($this->matchId, $this->playerId, 0);

        $this->assertNotNull($found);
        $this->assertSame($block->payload, $found->payload);
        $this->assertSame($block->seqNo, $found->seqNo);
        $this->assertSame($block->computeHash(), $found->computeHash());
    }

    public function test_inserting_same_block_twice_throws_duplicate_conflict(): void
    {
        $kp = Ed25519::keypair();
        $block = $this->block($kp);
        $this->repo->insert($block);

        $this->expectException(LedgerConflictException::class);
        try {
            $this->repo->insert($block);
        } catch (LedgerConflictException $e) {
            $this->assertTrue($e->isDuplicate());
            throw $e;
        }
    }

    public function test_inserting_different_block_same_seq_no_throws_conflict(): void
    {
        $kp = Ed25519::keypair();
        $first = $this->block($kp, payload: 'strike');
        $this->repo->insert($first);

        // Same seqNo, different payload -> different hash -> equivocation.
        $second = $this->block($kp, payload: 'fortify');

        $this->expectException(LedgerConflictException::class);
        try {
            $this->repo->insert($second);
        } catch (LedgerConflictException $e) {
            $this->assertFalse($e->isDuplicate());
            $this->assertSame('strike', $e->existing->payload);
            throw $e;
        }
    }

    public function test_chain_returns_blocks_in_seq_order(): void
    {
        $kp = Ed25519::keypair();
        $b0 = $this->block($kp, seqNo: 0, lamportTs: 1);
        $this->repo->insert($b0);
        $this->repo->insert($this->block($kp, seqNo: 1, lamportTs: 2));
        $this->repo->insert($this->block($kp, seqNo: 2, lamportTs: 3));

        $chain = $this->repo->chainFor($this->matchId, $this->playerId);

        $this->assertCount(3, $chain);
        $this->assertSame([0, 1, 2], array_map(static fn (LedgerBlock $b) => $b->seqNo, $chain));
    }

    public function test_delete_from_slashes_downstream_blocks(): void
    {
        $kp = Ed25519::keypair();
        $this->repo->insert($this->block($kp, seqNo: 0, lamportTs: 1));
        $this->repo->insert($this->block($kp, seqNo: 1, lamportTs: 2));
        $this->repo->insert($this->block($kp, seqNo: 2, lamportTs: 3));

        $deleted = $this->repo->deleteFrom($this->matchId, $this->playerId, 1);

        $this->assertSame(2, $deleted);
        $chain = $this->repo->chainFor($this->matchId, $this->playerId);
        $this->assertCount(1, $chain);
        $this->assertSame(0, $chain[0]->seqNo);
    }

    public function test_empty_chain_returns_empty_list(): void
    {
        $this->assertSame([], $this->repo->chainFor($this->matchId, $this->playerId));
    }

    public function test_find_missing_block_returns_null(): void
    {
        $this->assertNull($this->repo->find($this->matchId, $this->playerId, 0));
    }
}
