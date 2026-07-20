<?php

declare(strict_types=1);

namespace NodesWars\Api\Tests\Engine;

use NodesWars\Api\Engine\FixedPoint;
use PHPUnit\Framework\TestCase;

/**
 * Covers behaviour that is specific to the PHP engine and therefore cannot be
 * expressed as a shared golden fixture: the overflow guards, and the error
 * cases. Numeric agreement with the TypeScript engine lives in FixtureTest.
 */
final class FixedPointTest extends TestCase
{
    public function testRoundTripsWholeNumbers(): void
    {
        self::assertSame(65536, FixedPoint::fromInt(1));
        self::assertSame(-196608, FixedPoint::fromInt(-3));
        self::assertSame(0, FixedPoint::fromInt(0));
    }

    public function testTruncatesTowardZeroOnNegatives(): void
    {
        // The parity contract. A floor division would give -21846 here.
        self::assertSame(-21845, FixedPoint::div(FixedPoint::fromInt(-1), FixedPoint::fromInt(3)));
        self::assertSame(21845, FixedPoint::div(FixedPoint::fromInt(1), FixedPoint::fromInt(3)));
    }

    public function testDivideByZeroThrows(): void
    {
        $this->expectException(\DivisionByZeroError::class);
        FixedPoint::div(FixedPoint::fromInt(1), 0);
    }

    public function testMultiplyOverflowThrowsRatherThanSilentlyBecomingFloat(): void
    {
        // PHP promotes an overflowing int to float, which would diverge from
        // the TypeScript engine's 64-bit wrap. Failing loudly is the lesser
        // evil, and callers are contractually inside the safe range anyway.
        $this->expectException(\RangeException::class);
        FixedPoint::mul(\PHP_INT_MAX, FixedPoint::fromInt(2));
    }

    public function testFromIntOverflowThrows(): void
    {
        $this->expectException(\RangeException::class);
        FixedPoint::fromInt(\PHP_INT_MAX);
    }

    public function testSqrtOfNegativeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FixedPoint::sqrt(-1);
    }

    public function testFromStringRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FixedPoint::fromString('not a number');
    }

    public function testFromStringRejectsOverlongFraction(): void
    {
        // 10^19 exceeds PHP_INT_MAX; refusing beats drifting from TypeScript.
        $this->expectException(\InvalidArgumentException::class);
        FixedPoint::fromString('1.'.str_repeat('9', 19));
    }

    public function testFromPartsValidatesArguments(): void
    {
        self::assertSame(-98304, FixedPoint::fromParts(-1, 1, 2)); // -1.5

        $this->expectException(\InvalidArgumentException::class);
        FixedPoint::fromParts(0, 1, 0);
    }

    public function testSineQuadrantSymmetry(): void
    {
        $deg = static fn (int $d): int => $d * FixedPoint::SCALE;

        self::assertSame(0, FixedPoint::sinDeg($deg(0)));
        self::assertSame(FixedPoint::SCALE, FixedPoint::sinDeg($deg(90)));
        self::assertSame(0, FixedPoint::cosDeg($deg(90)));
        self::assertSame(FixedPoint::SCALE, FixedPoint::cosDeg($deg(0)));

        // sin(180 - x) == sin(x), and sin(-x) == -sin(x).
        self::assertSame(FixedPoint::sinDeg($deg(30)), FixedPoint::sinDeg($deg(150)));
        self::assertSame(-FixedPoint::sinDeg($deg(30)), FixedPoint::sinDeg($deg(-30)));
    }
}
