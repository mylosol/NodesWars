<?php

declare(strict_types=1);

namespace NodesWars\Api\Tests\Ledger;

use NodesWars\Api\Ledger\Ed25519;
use PHPUnit\Framework\TestCase;

final class Ed25519Test extends TestCase
{
    public function test_keypair_returns_32_byte_public_and_64_byte_secret(): void
    {
        $kp = Ed25519::keypair();

        $this->assertSame(32, strlen($kp['publicKey']));
        $this->assertSame(64, strlen($kp['secretKey']));
    }

    public function test_signature_verifies_for_the_signing_key(): void
    {
        $kp = Ed25519::keypair();
        $sig = Ed25519::sign('attack at dawn', $kp['secretKey']);

        $this->assertSame(64, strlen($sig));
        $this->assertTrue(Ed25519::verify('attack at dawn', $sig, $kp['publicKey']));
    }

    public function test_signature_fails_for_a_different_public_key(): void
    {
        $kp = Ed25519::keypair();
        $other = Ed25519::keypair();
        $sig = Ed25519::sign('attack at dawn', $kp['secretKey']);

        $this->assertFalse(Ed25519::verify('attack at dawn', $sig, $other['publicKey']));
    }

    public function test_signature_fails_when_message_is_tampered(): void
    {
        $kp = Ed25519::keypair();
        $sig = Ed25519::sign('attack at dawn', $kp['secretKey']);

        $this->assertFalse(Ed25519::verify('attack at dusk', $sig, $kp['publicKey']));
    }

    public function test_signature_fails_when_signature_is_tampered(): void
    {
        $kp = Ed25519::keypair();
        $sig = Ed25519::sign('attack at dawn', $kp['secretKey']);
        // Flip one byte in the middle of the 64 byte signature.
        $tampered = $sig;
        $tampered[32] = $tampered[32] === "\x00" ? "\x01" : "\x00";

        $this->assertFalse(Ed25519::verify('attack at dawn', $tampered, $kp['publicKey']));
    }

    public function test_verify_rejects_garbage_key_instead_of_throwing(): void
    {
        $kp = Ed25519::keypair();
        $sig = Ed25519::sign('attack at dawn', $kp['secretKey']);

        $this->assertFalse(Ed25519::verify('attack at dawn', $sig, 'way too short'));
    }

    public function test_sign_and_verify_roundtrip_is_deterministic_for_distinct_keys(): void
    {
        // Two keypairs must not accidentally verify each other.
        $kp1 = Ed25519::keypair();
        $kp2 = Ed25519::keypair();
        $sig1 = Ed25519::sign('payload', $kp1['secretKey']);

        $this->assertFalse(Ed25519::verify('payload', $sig1, $kp2['publicKey']));
        $this->assertTrue(Ed25519::verify('payload', $sig1, $kp1['publicKey']));
    }
}
