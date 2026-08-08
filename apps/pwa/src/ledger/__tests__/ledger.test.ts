import { describe, expect, it } from 'vitest';
import { ChainValidator } from '../chainValidator.js';
import { Ed25519 } from '../ed25519.js';
import { ForkResolver } from '../forkResolver.js';
import { LedgerBlock } from '../ledgerBlock.js';

const GENESIS_PREV_HASH = ChainValidator.GENESIS_PREV_HASH;

/** UTF-8 encodes a string — the browser safe stand in for PHP byte strings. */
function utf8(s: string): Uint8Array {
  return new TextEncoder().encode(s);
}

interface Keypair {
  publicKey: Uint8Array;
  secretKey: Uint8Array;
}

/** Signs a genesis style block (zero prevHash) with a real key. */
function makeBlock(kp: Keypair, seqNo = 0, lamportTs = 1, payload = 'strike'): LedgerBlock {
  const unsigned = LedgerBlock.create(
    'm1',
    'p1',
    seqNo,
    GENESIS_PREV_HASH,
    utf8(payload),
    new Uint8Array(64),
    kp.publicKey,
    lamportTs,
  );
  const signature = Ed25519.sign(unsigned.canonicalPreimage(), kp.secretKey);

  return LedgerBlock.create(
    'm1',
    'p1',
    seqNo,
    GENESIS_PREV_HASH,
    utf8(payload),
    signature,
    kp.publicKey,
    lamportTs,
  );
}

/**
 * Builds a signed block. When previous is given, chains onto it; the
 * prevHash, seqNo and lamportTs are derived so the block is valid by
 * construction. Pass explicit values to make a block invalid.
 */
function makeChainBlock(
  kp: Keypair,
  previous: LedgerBlock | null = null,
  overrides: { seqNo?: number; lamportTs?: number; prevHash?: string; payload?: string } = {},
): LedgerBlock {
  const seqNo = overrides.seqNo ?? (previous === null ? 0 : previous.seqNo + 1);
  const lamportTs = overrides.lamportTs ?? (previous === null ? 1 : previous.lamportTs + 1);
  const prevHash =
    overrides.prevHash ?? (previous === null ? GENESIS_PREV_HASH : previous.computeHash());
  const payload = utf8(overrides.payload ?? 'strike');

  const unsigned = LedgerBlock.create(
    'm1',
    'p1',
    seqNo,
    prevHash,
    payload,
    new Uint8Array(64),
    kp.publicKey,
    lamportTs,
  );
  const signature = Ed25519.sign(unsigned.canonicalPreimage(), kp.secretKey);

  return LedgerBlock.create(
    'm1',
    'p1',
    seqNo,
    prevHash,
    payload,
    signature,
    kp.publicKey,
    lamportTs,
  );
}

describe('Ed25519', () => {
  it('keypair returns 32 byte public and 32 byte secret keys', () => {
    const kp = Ed25519.keypair();

    expect(kp.publicKey).toHaveLength(32);
    expect(kp.secretKey).toHaveLength(32);
  });

  it('signature verifies for the signing key', () => {
    const kp = Ed25519.keypair();
    const signature = Ed25519.sign(utf8('attack at dawn'), kp.secretKey);

    expect(signature).toHaveLength(64);
    expect(Ed25519.verify(utf8('attack at dawn'), signature, kp.publicKey)).toBe(true);
  });

  it('signature fails for a different public key', () => {
    const kp = Ed25519.keypair();
    const other = Ed25519.keypair();
    const signature = Ed25519.sign(utf8('attack at dawn'), kp.secretKey);

    expect(Ed25519.verify(utf8('attack at dawn'), signature, other.publicKey)).toBe(false);
  });

  it('signature fails when message is tampered', () => {
    const kp = Ed25519.keypair();
    const signature = Ed25519.sign(utf8('attack at dawn'), kp.secretKey);

    expect(Ed25519.verify(utf8('attack at dusk'), signature, kp.publicKey)).toBe(false);
  });

  it('signature fails when signature is tampered', () => {
    const kp = Ed25519.keypair();
    const signature = Ed25519.sign(utf8('attack at dawn'), kp.secretKey);
    // Flip one byte in the middle of the 64 byte signature.
    const tampered = new Uint8Array(signature);
    tampered[32] = tampered[32] === 0 ? 1 : 0;

    expect(Ed25519.verify(utf8('attack at dawn'), tampered, kp.publicKey)).toBe(false);
  });

  it('verify rejects garbage key instead of throwing', () => {
    const kp = Ed25519.keypair();
    const signature = Ed25519.sign(utf8('attack at dawn'), kp.secretKey);

    expect(Ed25519.verify(utf8('attack at dawn'), signature, utf8('way too short'))).toBe(false);
  });

  it('sign and verify roundtrip is deterministic for distinct keys', () => {
    const kp1 = Ed25519.keypair();
    const kp2 = Ed25519.keypair();
    const signature = Ed25519.sign(utf8('payload'), kp1.secretKey);

    expect(Ed25519.verify(utf8('payload'), signature, kp2.publicKey)).toBe(false);
    expect(Ed25519.verify(utf8('payload'), signature, kp1.publicKey)).toBe(true);
  });
});

describe('LedgerBlock', () => {
  it('hash is 64 hex chars', () => {
    expect(makeBlock(Ed25519.keypair()).computeHash()).toMatch(/^[0-9a-f]{64}$/);
  });

  it('hash changes when payload changes', () => {
    const kp = Ed25519.keypair();
    expect(makeBlock(kp, 0, 1, 'strike').computeHash()).not.toBe(
      makeBlock(kp, 0, 1, 'fortify').computeHash(),
    );
  });

  it('hash changes when lamportTs changes', () => {
    const kp = Ed25519.keypair();
    expect(makeBlock(kp, 0, 1).computeHash()).not.toBe(makeBlock(kp, 0, 2).computeHash());
  });

  it('hash changes when seqNo changes', () => {
    const kp = Ed25519.keypair();
    expect(makeBlock(kp, 0).computeHash()).not.toBe(makeBlock(kp, 1).computeHash());
  });

  it('hash is deterministic', () => {
    const kp = Ed25519.keypair();
    expect(makeBlock(kp).computeHash()).toBe(makeBlock(kp).computeHash());
  });

  it('genesis flag is seqNo === 0', () => {
    const kp = Ed25519.keypair();
    expect(makeBlock(kp, 0).isGenesis()).toBe(true);
    expect(makeBlock(kp, 1).isGenesis()).toBe(false);
  });

  it('canonical preimage contains prevHash, u64 seqNo, u64 lamportTs, payload', () => {
    const kp = Ed25519.keypair();
    const block = makeBlock(kp, 3, 42);
    const preimage = block.canonicalPreimage();

    // prevHash (64 hex chars) + u64 seqNo + u64 lamportTs + payload.
    expect(preimage).toHaveLength(64 + 8 + 8 + utf8('strike').length);
    // prevHash is the hex TEXT of the previous hash, not raw bytes.
    expect(new TextDecoder().decode(preimage.subarray(0, 64))).toBe(GENESIS_PREV_HASH);
    // seqNo 3 -> 0x00 x7 then 0x03; lamportTs 42 -> 0x00 x7 then 0x2a.
    expect([...preimage.subarray(64, 72)]).toEqual([0, 0, 0, 0, 0, 0, 0, 3]);
    expect([...preimage.subarray(72, 80)]).toEqual([0, 0, 0, 0, 0, 0, 0, 42]);
    expect(new TextDecoder().decode(preimage.subarray(80))).toBe('strike');
  });

  it('rejects negative seqNo', () => {
    const kp = Ed25519.keypair();
    expect(() =>
      LedgerBlock.create(
        'm1',
        'p1',
        -1,
        GENESIS_PREV_HASH,
        utf8('strike'),
        new Uint8Array(64),
        kp.publicKey,
        1,
      ),
    ).toThrow('seqNo must be non negative');
  });

  it('rejects negative lamportTs', () => {
    const kp = Ed25519.keypair();
    expect(() =>
      LedgerBlock.create(
        'm1',
        'p1',
        0,
        GENESIS_PREV_HASH,
        utf8('strike'),
        new Uint8Array(64),
        kp.publicKey,
        -1,
      ),
    ).toThrow('lamportTs must be non negative');
  });

  it('u64 fields are big endian for large values', () => {
    const kp = Ed25519.keypair();
    const block = makeBlock(kp, 0x0102030405, 0x060708090a);
    const preimage = block.canonicalPreimage();

    expect([...preimage.subarray(64, 72)]).toEqual([0, 0, 0, 1, 2, 3, 4, 5]);
    expect([...preimage.subarray(72, 80)]).toEqual([0, 0, 0, 6, 7, 8, 9, 10]);
  });
});

describe('cross-language hash parity (PHP sodium_crypto_generichash)', () => {
  // Golden fixtures produced by the PHP implementation on commit f1b002d:
  //   cd apps/api && php -r 'require "vendor/autoload.php";
  //     $b = NodesWars\Api\Ledger\LedgerBlock::create("m1", "p1", SEQ, str_repeat("0", 64),
  //       "strike", str_repeat("\x00", 64), str_repeat("\x00", 32), TS); echo $b->computeHash();'
  // PHP computes sodium_crypto_generichash($preimage) which defaults to a 32
  // byte BLAKE2b digest — hence dkLen: 32 in ledgerBlock.computeHash().
  it('matches the PHP hash for the fixed parity block (seqNo 0, lamportTs 1, payload strike)', () => {
    const block = LedgerBlock.create(
      'm1',
      'p1',
      0,
      '0'.repeat(64),
      utf8('strike'),
      new Uint8Array(64),
      new Uint8Array(32),
      1,
    );

    expect(block.canonicalPreimage()).toHaveLength(86);
    expect(block.computeHash()).toBe(
      '351319c2fa4fc1c789f6639030ed551a8c149341308c078dcb22d94d25c63590',
    );
  });

  it('matches the PHP hash for a second fixture (seqNo 3, lamportTs 42)', () => {
    const block = LedgerBlock.create(
      'm1',
      'p1',
      3,
      '0'.repeat(64),
      utf8('strike'),
      new Uint8Array(64),
      new Uint8Array(32),
      42,
    );

    // PHP preimage hex: 30x64 || 0000000000000003 || 000000000000002a || 737472696b65
    expect(block.computeHash()).toBe(
      '952f7d1cd2516a435a01aa88668c450b428582df7d8ce0e4e7e4085ccd564666',
    );
  });
});

describe('ChainValidator', () => {
  it('genesis block validates without previous', () => {
    const kp = Ed25519.keypair();
    expect(ChainValidator.validate(makeChainBlock(kp), null)).toEqual([]);
  });

  it('second block chains onto genesis', () => {
    const kp = Ed25519.keypair();
    const genesis = makeChainBlock(kp);
    const second = makeChainBlock(kp, genesis);

    expect(ChainValidator.validate(second, genesis)).toEqual([]);
  });

  it('whole chain validates', () => {
    const kp = Ed25519.keypair();
    const b0 = makeChainBlock(kp);
    const b1 = makeChainBlock(kp, b0);
    const b2 = makeChainBlock(kp, b1);

    expect(ChainValidator.validateChain([b0, b1, b2])).toEqual([]);
  });

  it('first block must have seqNo zero', () => {
    const kp = Ed25519.keypair();
    const bad = makeChainBlock(kp, null, { seqNo: 1 });

    const errors = ChainValidator.validate(bad, null);
    expect(errors).toContain('first block for a player must have seqNo 0');
  });

  it('genesis must have zero prevHash', () => {
    const kp = Ed25519.keypair();
    const bad = makeChainBlock(kp, null, { prevHash: 'abc123' });

    const errors = ChainValidator.validate(bad, null);
    expect(errors).toContain('genesis block must have an all zero prevHash');
  });

  it('prevHash must match previous block', () => {
    const kp = Ed25519.keypair();
    const genesis = makeChainBlock(kp);
    const second = makeChainBlock(kp, genesis, { prevHash: 'f'.repeat(64) });

    const errors = ChainValidator.validate(second, genesis);
    expect(errors).toContain('prevHash does not match the previous block hash');
  });

  it('seqNo must increment by one', () => {
    const kp = Ed25519.keypair();
    const genesis = makeChainBlock(kp);
    // Skip seqNo 1 -> 2.
    const bad = makeChainBlock(kp, genesis, { seqNo: 2 });

    const errors = ChainValidator.validate(bad, genesis);
    expect(errors).toContain('seqNo must increment by 1, got 2 after 0');
  });

  it('lamport timestamp must not go backwards', () => {
    const kp = Ed25519.keypair();
    const genesis = makeChainBlock(kp, null, { lamportTs: 10 });
    const bad = makeChainBlock(kp, genesis, { lamportTs: 5 });

    const errors = ChainValidator.validate(bad, genesis);
    expect(errors).toContain('Lamport timestamp went backwards');
  });

  it('tampered payload fails signature', () => {
    const kp = Ed25519.keypair();
    const genesis = makeChainBlock(kp);
    // Valid signature over the ORIGINAL payload, then swap the payload.
    const signed = makeChainBlock(kp, genesis, { payload: 'strike' });
    const tampered = LedgerBlock.create(
      signed.matchId,
      signed.playerId,
      signed.seqNo,
      signed.prevHash,
      utf8('fortify'),
      signed.signature,
      signed.publicKey,
      signed.lamportTs,
    );

    const errors = ChainValidator.validate(tampered, genesis);
    expect(errors).toContain('signature does not verify');
  });

  it('signature from another player is rejected', () => {
    const kp = Ed25519.keypair();
    const other = Ed25519.keypair();
    const genesis = makeChainBlock(kp);
    const signed = makeChainBlock(kp, genesis);

    const forged = LedgerBlock.create(
      signed.matchId,
      signed.playerId,
      signed.seqNo,
      signed.prevHash,
      signed.payload,
      signed.signature,
      other.publicKey,
      signed.lamportTs,
    );

    const errors = ChainValidator.validate(forged, genesis);
    expect(errors).toContain('signature does not verify');
  });

  it('validate collects multiple errors', () => {
    const kp = Ed25519.keypair();
    // Wrong prevHash AND wrong seqNo AND backwards lamportTs.
    const genesis = makeChainBlock(kp, null, { lamportTs: 100 });
    const bad = makeChainBlock(kp, genesis, { seqNo: 7, lamportTs: 3, prevHash: 'nope' });

    const errors = ChainValidator.validate(bad, genesis);
    expect(errors).toContain('prevHash does not match the previous block hash');
    expect(errors).toContain('seqNo must increment by 1, got 7 after 0');
    expect(errors).toContain('Lamport timestamp went backwards');
  });

  it('empty chain validates', () => {
    expect(ChainValidator.validateChain([])).toEqual([]);
  });

  it('chain with a bad middle block reports the error', () => {
    const kp = Ed25519.keypair();
    const b0 = makeChainBlock(kp);
    const b1 = makeChainBlock(kp, b0);
    // Break the link between b1 and b2.
    const b2 = makeChainBlock(kp, b1, { prevHash: 'a'.repeat(64) });

    const errors = ChainValidator.validateChain([b0, b1, b2]);
    expect(errors).toContain('prevHash does not match the previous block hash');
  });

  it('chain walk reports every broken link', () => {
    const kp = Ed25519.keypair();
    const b0 = makeChainBlock(kp);
    const b1 = makeChainBlock(kp, b0);
    const b2 = makeChainBlock(kp, b1, { prevHash: 'a'.repeat(64) });
    const b3 = makeChainBlock(kp, b2, { prevHash: 'b'.repeat(64) });

    const errors = ChainValidator.validateChain([b0, b1, b2, b3]);
    expect(
      errors.filter((e) => e === 'prevHash does not match the previous block hash'),
    ).toHaveLength(2);
  });
});

describe('ForkResolver', () => {
  /** Two blocks with the same seqNo (0) but different payloads/timestamps. */
  function equivocatingPair(tsA: number, tsB: number): [LedgerBlock, LedgerBlock] {
    const kp = Ed25519.keypair();
    const make = (ts: number, payload: string): LedgerBlock => {
      const unsigned = LedgerBlock.create(
        'm1',
        'p1',
        0,
        GENESIS_PREV_HASH,
        utf8(payload),
        new Uint8Array(64),
        kp.publicKey,
        ts,
      );
      const signature = Ed25519.sign(unsigned.canonicalPreimage(), kp.secretKey);

      return LedgerBlock.create(
        'm1',
        'p1',
        0,
        GENESIS_PREV_HASH,
        utf8(payload),
        signature,
        kp.publicKey,
        ts,
      );
    };

    return [make(tsA, 'strike'), make(tsB, 'fortify')];
  }

  it('single candidate is kept without equivocation', () => {
    const [a] = equivocatingPair(1, 2);
    const result = ForkResolver.resolve([a]);

    expect(result.equivocated).toBe(false);
    expect(result.keep).toBe(a);
    expect(result.discarded).toEqual([]);
  });

  it('two distinct blocks same seqNo are both discarded', () => {
    const [a, b] = equivocatingPair(1, 2);
    const result = ForkResolver.resolve([a, b]);

    expect(result.equivocated).toBe(true);
    expect(result.keep).toBeNull();
    expect(result.discarded).toHaveLength(2);
    expect(result.discarded).toContain(a);
    expect(result.discarded).toContain(b);
  });

  it('duplicate delivery of the same block is kept once', () => {
    const [a] = equivocatingPair(1, 1);
    const result = ForkResolver.resolve([a, a]);

    expect(result.equivocated).toBe(false);
    expect(result.keep).toBe(a);
    expect(result.discarded).toHaveLength(1);
  });

  it('groups candidates by hash: three distinct hashes slash all', () => {
    const kp = Ed25519.keypair();
    const make = (payload: string): LedgerBlock => {
      const unsigned = LedgerBlock.create(
        'm1',
        'p1',
        0,
        GENESIS_PREV_HASH,
        utf8(payload),
        new Uint8Array(64),
        kp.publicKey,
        1,
      );
      const signature = Ed25519.sign(unsigned.canonicalPreimage(), kp.secretKey);

      return LedgerBlock.create(
        'm1',
        'p1',
        0,
        GENESIS_PREV_HASH,
        utf8(payload),
        signature,
        kp.publicKey,
        1,
      );
    };
    const result = ForkResolver.resolve([make('strike'), make('fortify'), make('bounty')]);

    expect(result.equivocated).toBe(true);
    expect(result.keep).toBeNull();
    expect(result.discarded).toHaveLength(3);
  });

  it('compare orders by lamport timestamp', () => {
    const [a, b] = equivocatingPair(1, 2);

    expect(ForkResolver.compare(a, b)).toBeLessThan(0);
    expect(ForkResolver.compare(b, a)).toBeGreaterThan(0);
  });

  it('compare breaks ties with hash', () => {
    // Same lamportTs, different payloads -> different hashes, so the
    // comparison must be non zero and consistent both ways.
    const [a, b] = equivocatingPair(5, 5);

    const forward = ForkResolver.compare(a, b);
    const backward = ForkResolver.compare(b, a);

    expect(forward).not.toBe(0);
    expect(backward).toBe(-forward);
  });

  it('compare is zero for identical blocks', () => {
    const [a] = equivocatingPair(5, 5);
    expect(ForkResolver.compare(a, a)).toBe(0);
  });

  it('empty candidates resolve to nothing', () => {
    const result = ForkResolver.resolve([]);

    expect(result.keep).toBeNull();
    expect(result.equivocated).toBe(false);
  });
});
