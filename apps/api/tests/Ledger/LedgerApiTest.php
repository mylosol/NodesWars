<?php

declare(strict_types=1);

namespace NodesWars\Api\Tests\Ledger;

use NodesWars\Api\App;
use NodesWars\Api\Ledger\ChainValidator;
use NodesWars\Api\Ledger\Ed25519;
use NodesWars\Api\Ledger\LedgerBlock;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * End to end tests of the ledger HTTP API against a real Postgres.
 *
 * Skips unless NODESWARS_PG_DSN is set — same pattern as LedgerRepositoryTest.
 * Example:
 *   NODESWARS_PG_DSN="pgsql:host=127.0.0.1;port=54329;dbname=nodeswars_test" \
 *   NODESWARS_PG_USER=postgres NODESWARS_PG_PASS=test \
 *   ./vendor/bin/phpunit --filter LedgerApiTest
 */
final class LedgerApiTest extends TestCase
{
    private PDO $pdo;
    private string $matchId;
    private string $playerId;

    /** @var array{publicKey: string, secretKey: string} */
    private array $registeredKp;

    protected function setUp(): void
    {
        $dsn = getenv('NODESWARS_PG_DSN');
        if ($dsn === false) {
            self::markTestSkipped('NODESWARS_PG_DSN not set; skipping Postgres integration test');
        }

        $this->pdo = new PDO($dsn, getenv('NODESWARS_PG_USER') ?: 'postgres', getenv('NODESWARS_PG_PASS') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // The app under test reads DATABASE_URL / DATABASE_USER /
        // DATABASE_PASSWORD. Bridge the test env vars into those so the
        // container can build its PDO against the same database.
        putenv("DATABASE_URL=$dsn");
        putenv('DATABASE_USER='.(getenv('NODESWARS_PG_USER') ?: 'postgres'));
        putenv('DATABASE_PASSWORD='.(getenv('NODESWARS_PG_PASS') ?: ''));

        $this->registeredKp = $this->keypair();
        $this->playerId = $this->insertPlayer($this->registeredKp);
        $this->matchId = $this->insertMatch();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('TRUNCATE ledger_blocks, match_players, matches, players RESTART IDENTITY CASCADE');
        }
    }

    /**
     * @param array{publicKey: string, secretKey: string} $kp keypair to
     *                                                        REGISTER for the
     *                                                        player — the
     *                                                        server verifies
     *                                                        against this key
     */
    private function insertPlayer(array $kp): string
    {
        $email = 'api'.bin2hex(random_bytes(6)).'@test.local';
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
        $matchId = (string) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare('INSERT INTO match_players (match_id, player_id) VALUES (:m, :p)');
        $stmt->execute(['m' => $matchId, 'p' => $this->playerId]);

        return $matchId;
    }

    private function keypair(): array
    {
        return Ed25519::keypair();
    }

    /**
     * Builds a signed block body as the API expects it (base64 fields).
     *
     * @return array<string, string|int>
     */
    private function blockBody(array $kp, int $seqNo, ?int $lamportTs = null, string $payload = 'strike', ?string $prevHash = null): array
    {
        $lamportTs ??= $seqNo + 1;
        $prevHash ??= ChainValidator::GENESIS_PREV_HASH;

        $unsigned = LedgerBlock::create(
            matchId: $this->matchId,
            playerId: $this->playerId,
            seqNo: $seqNo,
            prevHash: $prevHash,
            payload: $payload,
            signature: str_repeat("\x00", 64),
            publicKey: $kp['publicKey'],
            lamportTs: $lamportTs,
        );
        $sig = Ed25519::sign($unsigned->canonicalPreimage(), $kp['secretKey']);

        return [
            'playerId' => $this->playerId,
            'seqNo' => $seqNo,
            'prevHash' => $prevHash,
            'payload' => base64_encode($payload),
            'signature' => base64_encode($sig),
            'publicKey' => base64_encode($kp['publicKey']),
            'lamportTs' => $lamportTs,
        ];
    }

    /**
     * @param array<string, string|int> $body
     */
    private function post(string $path, array $body): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($body);
        $request->getBody()->write(json_encode($body));

        return App::bootstrap()->handle($request);
    }

    private function get(string $path): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);

        return App::bootstrap()->handle($request);
    }

    private function delete(string $path): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('DELETE', $path);

        return App::bootstrap()->handle($request);
    }

    public function test_healthz_still_works_without_database(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/healthz');
        $response = App::bootstrap()->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"ok":true}', (string) $response->getBody());
    }

    public function test_valid_genesis_block_is_accepted(): void
    {
        $kp = $this->registeredKp;
        $response = $this->post("/matches/{$this->matchId}/ledger/blocks", $this->blockBody($kp, 0));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('accepted', $data['status']);
        $this->assertSame(0, $data['block']['seqNo']);
        $this->assertSame(64, strlen($data['block']['hash']));
    }

    public function test_same_block_reposted_is_idempotent(): void
    {
        $kp = $this->registeredKp;
        $body = $this->blockBody($kp, 0);
        $this->assertSame(201, $this->post("/matches/{$this->matchId}/ledger/blocks", $body)->getStatusCode());

        $response = $this->post("/matches/{$this->matchId}/ledger/blocks", $body);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('duplicate', $data['status']);
    }

    public function test_equivocation_returns_409_with_both_hashes(): void
    {
        $kp = $this->registeredKp;
        $first = $this->blockBody($kp, 0, payload: 'strike');
        $this->assertSame(201, $this->post("/matches/{$this->matchId}/ledger/blocks", $first)->getStatusCode());

        $second = $this->blockBody($kp, 0, payload: 'fortify');
        $response = $this->post("/matches/{$this->matchId}/ledger/blocks", $second);

        $this->assertSame(409, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertTrue($data['equivocated']);
        $this->assertSame(64, strlen($data['submittedHash']));
        $this->assertSame(64, strlen($data['existingHash']));
    }

    public function test_bad_signature_is_rejected_with_reason(): void
    {
        $kp = $this->registeredKp;
        $body = $this->blockBody($kp, 0);
        // Corrupt the signature in the base64 payload.
        $body['signature'] = base64_encode(str_repeat("\x00", 64));

        $response = $this->post("/matches/{$this->matchId}/ledger/blocks", $body);

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertContains('signature does not verify', $data['reasons']);
    }

    public function test_list_returns_blocks_and_respects_since(): void
    {
        $kp = $this->registeredKp;
        $b0 = $this->blockBody($kp, 0);
        $this->assertSame(201, $this->post("/matches/{$this->matchId}/ledger/blocks", $b0)->getStatusCode());

        $b1 = $this->blockBody($kp, 1, lamportTs: 2, prevHash: LedgerBlock::create(
            matchId: $this->matchId,
            playerId: $this->playerId,
            seqNo: 0,
            prevHash: ChainValidator::GENESIS_PREV_HASH,
            payload: 'strike',
            signature: base64_decode($b0['signature']),
            publicKey: base64_decode($b0['publicKey']),
            lamportTs: 1,
        )->computeHash());
        $this->assertSame(201, $this->post("/matches/{$this->matchId}/ledger/blocks", $b1)->getStatusCode());

        $response = $this->get("/matches/{$this->matchId}/ledger/blocks");
        $data = json_decode((string) $response->getBody(), true);
        $this->assertCount(2, $data['blocks']);

        $response = $this->get("/matches/{$this->matchId}/ledger/blocks?since=1");
        $data = json_decode((string) $response->getBody(), true);
        $this->assertCount(1, $data['blocks']);
        $this->assertSame(1, $data['blocks'][0]['seqNo']);
    }

    public function test_rollback_deletes_from_seq(): void
    {
        $kp = $this->registeredKp;
        $b0 = $this->blockBody($kp, 0);
        $this->assertSame(201, $this->post("/matches/{$this->matchId}/ledger/blocks", $b0)->getStatusCode());

        // A properly chained second block: prevHash = hash of b0. Build b0's
        // hash by reconstructing the block from the stored response.
        $stored0 = json_decode((string) $this->get("/matches/{$this->matchId}/ledger/blocks")->getBody(), true)['blocks'][0];
        $b1 = $this->blockBody($kp, 1, lamportTs: 2, prevHash: $stored0['hash']);
        $this->assertSame(201, $this->post("/matches/{$this->matchId}/ledger/blocks", $b1)->getStatusCode());

        $response = $this->delete("/matches/{$this->matchId}/players/{$this->playerId}/ledger?from=1");
        $data = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $data['deleted']);

        // Only b0 remains after the rollback.
        $blocks = json_decode((string) $this->get("/matches/{$this->matchId}/ledger/blocks")->getBody(), true)['blocks'];
        $this->assertCount(1, $blocks);
        $this->assertSame(0, $blocks[0]['seqNo']);
    }
}
