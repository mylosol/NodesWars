<?php

declare(strict_types=1);

namespace NodesWars\Api\Ledger;

use RuntimeException;
use SodiumException;

/**
 * Thin deterministic wrapper around libsodium Ed25519.
 *
 * Frozen decision (HANDOFF): client signs with @noble/curves, the server
 * verifies with ext-sodium. Both implement Ed25519, so a signature produced
 * by either side verifies on the other. This class keeps the server side in
 * one place and throws meaningful errors instead of letting sodium's cryptic
 * failures bubble up.
 */
final class Ed25519
{
    /**
     * @return array{publicKey: string, secretKey: string}
     */
    public static function keypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'publicKey' => sodium_crypto_sign_publickey($pair),
            'secretKey' => sodium_crypto_sign_secretkey($pair),
        ];
    }

    /**
     * Signs a message with an Ed25519 secret key.
     *
     * Sodium's secret key is the 64 byte seed+public concatenation, which is
     * exactly what @noble/curves accepts for its Ed25519 signer, so keys can
     * move between client and server without conversion.
     */
    public static function sign(string $message, string $secretKey): string
    {
        try {
            return sodium_crypto_sign_detached($message, $secretKey);
        } catch (SodiumException $e) {
            throw new RuntimeException('Ed25519 signing failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Verifies a detached Ed25519 signature. Returns false on any failure
     * (bad key, bad signature) rather than throwing, so callers can treat an
     * invalid block as a normal rejection instead of an exceptional one.
     */
    public static function verify(string $message, string $signature, string $publicKey): bool
    {
        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (SodiumException) {
            return false;
        }
    }
}
