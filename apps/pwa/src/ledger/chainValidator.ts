import { Ed25519 } from './ed25519.js';
import { LedgerBlock } from './ledgerBlock.js';

/**
 * Validates a sequence of blocks for one player in one match.
 *
 * Mirrors apps/api/src/Ledger/ChainValidator.php. Chain rules (frozen):
 *   - hash chain per player: block N's prevHash must equal block N-1's hash
 *   - seqNo strictly increments by 1 from a genesis block
 *   - the genesis block's prevHash is all zeros
 *   - Lamport timestamps are non decreasing
 *   - every block's signature verifies against its public key
 *
 * This is the client side gate, mirroring the server side gate. A block
 * failing any rule is rejected as a whole; the chain is append only and
 * nothing is ever edited.
 */
export class ChainValidator {
  static readonly GENESIS_PREV_HASH = '0'.repeat(64);

  /**
   * Validates a block against the previous block in the same player chain.
   *
   * @returns empty when valid, otherwise every rule that failed
   */
  static validate(block: LedgerBlock, previous: LedgerBlock | null): string[] {
    const errors: string[] = [];

    if (previous === null) {
      if (block.seqNo !== 0) {
        errors.push('first block for a player must have seqNo 0');
      }
      if (block.prevHash !== ChainValidator.GENESIS_PREV_HASH) {
        errors.push('genesis block must have an all zero prevHash');
      }
    } else {
      if (block.prevHash !== previous.computeHash()) {
        errors.push('prevHash does not match the previous block hash');
      }
      if (block.seqNo !== previous.seqNo + 1) {
        errors.push(`seqNo must increment by 1, got ${block.seqNo} after ${previous.seqNo}`);
      }
      if (block.lamportTs < previous.lamportTs) {
        errors.push('Lamport timestamp went backwards');
      }
    }

    if (!Ed25519.verify(block.canonicalPreimage(), block.signature, block.publicKey)) {
      errors.push('signature does not verify');
    }

    return errors;
  }

  /**
   * Validates a whole chain of blocks for one player, in order.
   *
   * @returns empty when valid, otherwise every rule that failed
   */
  static validateChain(blocks: LedgerBlock[]): string[] {
    const errors: string[] = [];
    let previous: LedgerBlock | null = null;

    for (const block of blocks) {
      errors.push(...ChainValidator.validate(block, previous));
      previous = block;
    }

    return errors;
  }
}
