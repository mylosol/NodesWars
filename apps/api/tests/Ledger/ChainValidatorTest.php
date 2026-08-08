<?php

declare(strict_types=1);

namespace NodesWars\Api\Tests\Ledger;

use NodesWars\Api\Ledger\ChainValidator;
use NodesWars\Api\Ledger\Ed25519;
use NodesWars\Api\Ledger\LedgerBlock;
use PHPUnit\Framework\TestCase;

final class ChainValidatorTest extends TestCase
{
    /**
     * Builds a signed block. When $previous is given, chains onto it; the
     * prevHash, seqNo and lamportTs are derived so the block is valid by
     * construction. Pass explicit values to make a block invalid.
     */
    private function block(
        array $kp,
        ?LedgerBlock $previous = null,
        ?int $seqNo = null,
        ?int $lamportTs = null,
        ?string $prevHash = null,
        ?string $payload = null,
    ): LedgerBlock {
        $seqNo ??= $previous === null ? 0 : $previous->seqNo + 1;
        $lamportTs ??= $previous === null ? 1 : $previous->lamportTs + 1;
        $prevHash ??= $previous === null ? ChainValidator::GENESIS_PREV_HASH : $previous->computeHash();
        $payload ??= 'strike';

        $unsigned = LedgerBlock::create(
            matchId: 'm1',
            playerId: 'p1',
            seqNo: $seqNo,
            prevHash: $prevHash,
            payload: $payload,
            signature: str_repeat("\x00", 64),
            publicKey: $kp['publicKey'],
            lamportTs: $lamportTs,
        );
        $signature = Ed25519::sign($unsigned->canonicalPreimage(), $kp['secretKey']);

        return LedgerBlock::create(
            matchId: 'm1',
            playerId: 'p1',
            seqNo: $seqNo,
            prevHash: $prevHash,
            payload: $payload,
            signature: $signature,
            publicKey: $kp['publicKey'],
            lamportTs: $lamportTs,
        );
    }

    private function keypair(): array
    {
        return Ed25519::keypair();
    }

    public function test_genesis_block_validates_without_previous(): void
    {
        $kp = $this->keypair();
        $genesis = $this->block($kp);

        $this->assertSame([], ChainValidator::validate($genesis, null));
    }

    public function test_second_block_chains_onto_genesis(): void
    {
        $kp = $this->keypair();
        $genesis = $this->block($kp);
        $second = $this->block($kp, $genesis);

        $this->assertSame([], ChainValidator::validate($second, $genesis));
    }

    public function test_whole_chain_validates(): void
    {
        $kp = $this->keypair();
        $b0 = $this->block($kp);
        $b1 = $this->block($kp, $b0);
        $b2 = $this->block($kp, $b1);

        $this->assertSame([], ChainValidator::validateChain([$b0, $b1, $b2]));
    }

    public function test_first_block_must_have_seqNo_zero(): void
    {
        $kp = $this->keypair();
        $bad = $this->block($kp, seqNo: 1);

        $errors = ChainValidator::validate($bad, null);
        $this->assertContains('first block for a player must have seqNo 0', $errors);
    }

    public function test_genesis_must_have_zero_prev_hash(): void
    {
        $kp = $this->keypair();
        $bad = $this->block($kp, prevHash: 'abc123');

        $errors = ChainValidator::validate($bad, null);
        $this->assertContains('genesis block must have an all zero prevHash', $errors);
    }

    public function test_prev_hash_must_match_previous_block(): void
    {
        $kp = $this->keypair();
        $genesis = $this->block($kp);
        $second = $this->block($kp, $genesis, prevHash: str_repeat('f', 64));

        $errors = ChainValidator::validate($second, $genesis);
        $this->assertContains('prevHash does not match the previous block hash', $errors);
    }

    public function test_seq_no_must_increment_by_one(): void
    {
        $kp = $this->keypair();
        $genesis = $this->block($kp);
        // Skip seqNo 1 -> 2.
        $bad = $this->block($kp, $genesis, seqNo: 2);

        $errors = ChainValidator::validate($bad, $genesis);
        $this->assertContains('seqNo must increment by 1, got 2 after 0', $errors);
    }

    public function test_lamport_timestamp_must_not_go_backwards(): void
    {
        $kp = $this->keypair();
        $genesis = $this->block($kp, lamportTs: 10);
        $bad = $this->block($kp, $genesis, lamportTs: 5);

        $errors = ChainValidator::validate($bad, $genesis);
        $this->assertContains('Lamport timestamp went backwards', $errors);
    }

    public function test_tampered_payload_fails_signature(): void
    {
        $kp = $this->keypair();
        $genesis = $this->block($kp);
        // Valid signature over the ORIGINAL payload, then swap the payload.
        $signed = $this->block($kp, $genesis, payload: 'strike');
        $tampered = LedgerBlock::create(
            matchId: $signed->matchId,
            playerId: $signed->playerId,
            seqNo: $signed->seqNo,
            prevHash: $signed->prevHash,
            payload: 'fortify',
            signature: $signed->signature,
            publicKey: $signed->publicKey,
            lamportTs: $signed->lamportTs,
        );

        $errors = ChainValidator::validate($tampered, $genesis);
        $this->assertContains('signature does not verify', $errors);
    }

    public function test_signature_from_another_player_is_rejected(): void
    {
        $kp = $this->keypair();
        $other = $this->keypair();
        $genesis = $this->block($kp);
        $signed = $this->block($kp, $genesis);

        $forged = LedgerBlock::create(
            matchId: $signed->matchId,
            playerId: $signed->playerId,
            seqNo: $signed->seqNo,
            prevHash: $signed->prevHash,
            payload: $signed->payload,
            signature: $signed->signature,
            publicKey: $other['publicKey'],
            lamportTs: $signed->lamportTs,
        );

        $errors = ChainValidator::validate($forged, $genesis);
        $this->assertContains('signature does not verify', $errors);
    }

    public function test_validate_collects_multiple_errors(): void
    {
        $kp = $this->keypair();
        // Wrong prevHash AND wrong seqNo AND backwards lamportTs.
        $genesis = $this->block($kp, lamportTs: 100);
        $bad = $this->block($kp, $genesis, seqNo: 7, lamportTs: 3, prevHash: 'nope');

        $errors = ChainValidator::validate($bad, $genesis);
        $this->assertContains('prevHash does not match the previous block hash', $errors);
        $this->assertContains('seqNo must increment by 1, got 7 after 0', $errors);
        $this->assertContains('Lamport timestamp went backwards', $errors);
    }

    public function test_empty_chain_validates(): void
    {
        $this->assertSame([], ChainValidator::validateChain([]));
    }

    public function test_chain_with_a_bad_middle_block_reports_the_error(): void
    {
        $kp = $this->keypair();
        $b0 = $this->block($kp);
        $b1 = $this->block($kp, $b0);
        // Break the link between b1 and b2.
        $b2 = $this->block($kp, $b1, prevHash: str_repeat('a', 64));

        $errors = ChainValidator::validateChain([$b0, $b1, $b2]);
        $this->assertContains('prevHash does not match the previous block hash', $errors);
    }
}
