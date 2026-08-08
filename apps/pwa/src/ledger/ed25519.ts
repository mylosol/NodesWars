import { ed25519 } from '@noble/curves/ed25519';

/**
 * Thin deterministic wrapper around @noble/curves Ed25519.
 *
 * Mirrors apps/api/src/Ledger/Ed25519.php. The client signs with
 * @noble/curves and the server verifies with ext-sodium: both implement
 * Ed25519, so a signature produced by either side verifies on the other.
 *
 * Representation note: PHP stores sodium's 64 byte secret key (seed ||
 * public key). @noble/curves v1 (1.9.x) accepts only the 32 byte seed for
 * signing, so this wrapper deals in 32 byte seeds. Ed25519 signatures are
 * deterministic, so a given seed produces identical signatures and public
 * keys on both sides regardless of the secret key representation.
 */
export class Ed25519 {
  /** @returns a fresh keypair: 32 byte public key, 32 byte secret key (seed). */
  static keypair(): { publicKey: Uint8Array; secretKey: Uint8Array } {
    const secretKey = ed25519.utils.randomSecretKey();
    const publicKey = ed25519.getPublicKey(secretKey);

    return { publicKey, secretKey };
  }

  /** Derives the 32 byte public key for a 32 byte secret key (seed). */
  static getPublicKey(secretKey: Uint8Array): Uint8Array {
    return ed25519.getPublicKey(secretKey);
  }

  /** Signs a message with an Ed25519 secret key. @returns 64 byte signature. */
  static sign(message: Uint8Array, secretKey: Uint8Array): Uint8Array {
    return ed25519.sign(message, secretKey);
  }

  /**
   * Verifies a detached Ed25519 signature. Returns false on any failure
   * (bad key, bad signature, wrong lengths) rather than throwing, so callers
   * can treat an invalid block as a normal rejection.
   */
  static verify(message: Uint8Array, signature: Uint8Array, publicKey: Uint8Array): boolean {
    try {
      return ed25519.verify(signature, message, publicKey);
    } catch {
      return false;
    }
  }
}
