import { blake2b } from '@noble/hashes/blake2b';

/** Encodes a non negative integer as an 8 byte big endian sequence (pack('J')). */
function u64be(value: number): Uint8Array {
  const buffer = new ArrayBuffer(8);
  new DataView(buffer).setBigUint64(0, BigInt(value), false);

  return new Uint8Array(buffer);
}

/** Hex encodes a byte array, lowercase, no separators (sodium_bin2hex). */
export function bytesToHex(bytes: Uint8Array): string {
  let hex = '';
  for (const byte of bytes) {
    hex += byte.toString(16).padStart(2, '0');
  }

  return hex;
}

/**
 * Immutable signed block in the match ledger.
 *
 * Mirrors apps/api/src/Ledger/LedgerBlock.php and db/migrations/0004_ledger_blocks.sql.
 * A block is one player action (strike, fortify, bounty) chained to the
 * previous block from the SAME player via prevHash, carrying a Lamport
 * timestamp for cross player ordering and a detached Ed25519 signature over
 * the canonical preimage.
 *
 * Canonical preimage (what gets hashed and signed):
 *   prevHash || seqNo(u64 BE) || lamportTs(u64 BE) || payload
 *
 * This encoding is the parity contract with the PHP ledger. The TS port must
 * reproduce it byte for byte, or signatures and chain hashes will diverge
 * across client and server. All byte work uses TextEncoder/DataView —
 * never Buffer, never Node crypto — so this module runs in the browser.
 */
export class LedgerBlock {
  readonly matchId: string;
  readonly playerId: string;
  readonly seqNo: number;
  /** hex, 64 chars; zero string for genesis */
  readonly prevHash: string;
  /** CBOR action payload (encrypted), raw bytes */
  readonly payload: Uint8Array;
  /** 64 byte Ed25519 signature, binary */
  readonly signature: Uint8Array;
  /** 32 byte Ed25519 public key, binary */
  readonly publicKey: Uint8Array;
  readonly lamportTs: number;

  private constructor(
    matchId: string,
    playerId: string,
    seqNo: number,
    prevHash: string,
    payload: Uint8Array,
    signature: Uint8Array,
    publicKey: Uint8Array,
    lamportTs: number,
  ) {
    this.matchId = matchId;
    this.playerId = playerId;
    this.seqNo = seqNo;
    this.prevHash = prevHash;
    this.payload = payload;
    this.signature = signature;
    this.publicKey = publicKey;
    this.lamportTs = lamportTs;
  }

  static create(
    matchId: string,
    playerId: string,
    seqNo: number,
    prevHash: string,
    payload: Uint8Array,
    signature: Uint8Array,
    publicKey: Uint8Array,
    lamportTs: number,
  ): LedgerBlock {
    if (seqNo < 0) {
      throw new RangeError('seqNo must be non negative');
    }
    if (lamportTs < 0) {
      throw new RangeError('lamportTs must be non negative');
    }

    return new LedgerBlock(
      matchId,
      playerId,
      seqNo,
      prevHash,
      payload,
      signature,
      publicKey,
      lamportTs,
    );
  }

  /**
   * The exact bytes the client signs and the chain hashes. Never change this
   * layout without updating the PHP ledger and all golden fixtures, or every
   * historical block stops validating.
   */
  canonicalPreimage(): Uint8Array {
    const prevHashBytes = new TextEncoder().encode(this.prevHash);
    const preimage = new Uint8Array(prevHashBytes.length + 8 + 8 + this.payload.length);

    preimage.set(prevHashBytes, 0);
    preimage.set(u64be(this.seqNo), prevHashBytes.length);
    preimage.set(u64be(this.lamportTs), prevHashBytes.length + 8);
    preimage.set(this.payload, prevHashBytes.length + 16);

    return preimage;
  }

  /**
   * BLAKE2b of the canonical preimage, hex encoded. The chain hash is the
   * preimage hash, not the payload hash, so a replay with a different
   * lamportTs or seqNo produces a different hash and is caught by the chain.
   *
   * PARITY NOTE: PHP calls `sodium_crypto_generichash($preimage)` with one
   * argument, and libsodium's default output length is 32 bytes (BLAKE2b-256,
   * crypto_generichash_BYTES) — NOT the 64 byte BLAKE2b-512 the design doc
   * describes. The golden parity fixture below pins the actual PHP output, so
   * the TS port must use dkLen: 32 to be byte identical.
   */
  computeHash(): string {
    const digest = blake2b(this.canonicalPreimage(), { dkLen: 32 });

    return bytesToHex(digest);
  }

  isGenesis(): boolean {
    return this.seqNo === 0;
  }
}
