<?php

declare(strict_types=1);

namespace NodesWars\Api\Ledger;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HTTP surface for the match ledger.
 *
 *   POST /matches/{matchId}/ledger/blocks    submit a signed block
 *   GET  /matches/{matchId}/ledger/blocks    fetch blocks since a seqNo
 *   DELETE /matches/{matchId}/players/{playerId}/ledger  equivocation rollback
 *
 * Slim 4 + slim/psr7 has no withJson() helper (that was Slim 3), so every
 * response writes json_encode directly to the body and sets the header.
 */
final class LedgerController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public function submitBlock(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $repo = new LedgerRepository($this->pdo);
        $matchId = $args['matchId'];
        $body = json_decode((string) $request->getBody(), true);

        if (!is_array($body)) {
            return $this->json($response, 400, ['error' => 'invalid JSON body']);
        }

        $playerId = $this->string($body, 'playerId');
        $seqNo = $this->int($body, 'seqNo');
        $prevHash = $this->string($body, 'prevHash');
        $payload = $this->base64($body, 'payload');
        $signature = $this->base64($body, 'signature');
        $lamportTs = $this->int($body, 'lamportTs');

        if ($playerId === null || $seqNo === null || $prevHash === null || $payload === null
            || $signature === null || $lamportTs === null) {
            return $this->json($response, 422, ['error' => 'missing required fields (playerId, seqNo, prevHash, payload, signature, lamportTs)']);
        }

        // The server is the authority: signatures verify against the key the
        // player REGISTERED, not any key the client sends in the request.
        $repo = new LedgerRepository($this->pdo);
        $registeredKey = $repo->playerPublicKey($playerId);
        if ($registeredKey === null) {
            return $this->json($response, 404, ['error' => 'player not found']);
        }

        try {
            $block = LedgerBlock::create($matchId, $playerId, $seqNo, $prevHash, $payload, $signature, $registeredKey, $lamportTs);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, 422, ['error' => $e->getMessage()]);
        }

        // Idempotency first: if this (match, player, seqNo) slot is already
        // filled, the request is either a LoRa retransmission of the SAME
        // block (200 duplicate) or equivocation (409). Checking before chain
        // validation matters: a re-posted block's prevHash points at the
        // genesis hash, not at the stored block, so chain validation would
        // reject a perfectly fine retransmission.
        $existing = $repo->find($matchId, $playerId, $seqNo);
        if ($existing !== null) {
            if ($existing->computeHash() === $block->computeHash()) {
                return $this->json($response, 200, [
                    'status' => 'duplicate',
                    'block' => $this->blockToArray($existing),
                ]);
            }

            return $this->json($response, 409, [
                'error' => 'equivocation detected',
                'equivocated' => true,
                'submittedHash' => $block->computeHash(),
                'existingHash' => $existing->computeHash(),
            ]);
        }

        // The server is the authority: it re-validates the ENTIRE player
        // chain before accepting anything. A chain that already has errors
        // cannot be extended; the client must resync first. Stored blocks
        // carry no public key (it lives on the players table), so re-key
        // them with the registered key before validating.
        $chain = $repo->chainFor($matchId, $playerId);
        $chain = array_map(
            fn (LedgerBlock $b) => LedgerBlock::create(
                $b->matchId,
                $b->playerId,
                $b->seqNo,
                $b->prevHash,
                $b->payload,
                $b->signature,
                $registeredKey,
                $b->lamportTs,
            ),
            $chain,
        );
        $chainErrors = ChainValidator::validateChain($chain);
        if ($chainErrors !== []) {
            return $this->json($response, 409, ['error' => 'existing chain is invalid', 'reasons' => $chainErrors]);
        }

        $previous = $chain === [] ? null : $chain[count($chain) - 1];
        $errors = ChainValidator::validate($block, $previous);
        if ($errors !== []) {
            return $this->json($response, 422, ['error' => 'block rejected', 'reasons' => $errors]);
        }

        try {
            $stored = $repo->insert($block);
        } catch (LedgerConflictException $e) {
            if ($e->isDuplicate()) {
                // LoRa retransmission: the same block arriving twice is not
                // cheating. Return the stored block as an idempotent 200.
                return $this->json($response, 200, [
                    'status' => 'duplicate',
                    'block' => $this->blockToArray($e->existing),
                ]);
            }

            return $this->json($response, 409, [
                'error' => 'equivocation detected',
                'equivocated' => true,
                'submittedHash' => $block->computeHash(),
                'existingHash' => $e->existing->computeHash(),
            ]);
        }

        return $this->json($response, 201, [
            'status' => 'accepted',
            'block' => $this->blockToArray($stored),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function listBlocks(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $repo = new LedgerRepository($this->pdo);
        $matchId = $args['matchId'];
        $since = max(0, (int) ($request->getQueryParams()['since'] ?? 0));

        $playerIds = $this->playerIdsForMatch($matchId);
        if ($playerIds === null) {
            return $this->json($response, 404, ['error' => 'match not found']);
        }

        $blocks = $repo->allBlocksForMatch($matchId, $playerIds, $since);

        return $this->json($response, 200, [
            'blocks' => array_map(fn (LedgerBlock $b) => $this->blockToArray($b), $blocks),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function rollbackPlayer(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $repo = new LedgerRepository($this->pdo);
        $matchId = $args['matchId'];
        $playerId = $args['playerId'];
        $fromSeq = max(0, (int) ($request->getQueryParams()['from'] ?? 0));

        $deleted = $repo->deleteFrom($matchId, $playerId, $fromSeq);

        return $this->json($response, 200, ['deleted' => $deleted]);
    }

    /**
     * @return list<string>|null null when the match does not exist
     */
    private function playerIdsForMatch(string $matchId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM matches WHERE id = ?');
        $stmt->execute([$matchId]);
        if ($stmt->fetch(PDO::FETCH_ASSOC) === false) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT player_id FROM match_players WHERE match_id = ?');
        $stmt->execute([$matchId]);

        $ids = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $ids[] = (string) $row['player_id'];
        }

        return $ids;
    }

    /**
     * @return array<string, string|int>
     */
    private function blockToArray(LedgerBlock $block): array
    {
        return [
            'matchId' => $block->matchId,
            'playerId' => $block->playerId,
            'seqNo' => $block->seqNo,
            'prevHash' => $block->prevHash,
            'payload' => base64_encode($block->payload),
            'signature' => base64_encode($block->signature),
            'lamportTs' => $block->lamportTs,
            'hash' => $block->computeHash(),
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function string(array $body, string $key): ?string
    {
        $v = $body[$key] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function int(array $body, string $key): ?int
    {
        $v = $body[$key] ?? null;

        return is_int($v) ? $v : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function base64(array $body, string $key): ?string
    {
        $v = $body[$key] ?? null;
        if (!is_string($v) || $v === '') {
            return null;
        }
        $decoded = base64_decode($v, true);
        if ($decoded === false) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
