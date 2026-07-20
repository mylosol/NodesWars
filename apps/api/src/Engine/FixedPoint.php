<?php

declare(strict_types=1);

namespace NodesWars\Api\Engine;

/**
 * Fixed-point arithmetic. Port of packages/engine/src/fixedPoint.ts.
 *
 * A Fixed is a signed 64-bit integer equal to round(real * 2^16). Both engines
 * must produce byte-identical results, which rests on two things:
 *
 * 1. Every division truncates toward zero. PHP's intdiv() and TypeScript's
 *    BigInt `/` both do. Never use `>>` for signed division here, since it
 *    floors on negatives and would diverge.
 * 2. Every intermediate stays inside the signed 64-bit range. The TypeScript
 *    side computes in arbitrary precision and wraps at the end; PHP would
 *    silently turn an overflowing int into a float, which is worse than
 *    failing. So the multiplies here guard and throw instead.
 *
 * The safe multiply range is roughly +/-46340 in real units, which is why the
 * scale is 2^16 rather than the originally specified Q32.32. Physics runs in
 * local metres and stays well inside it.
 *
 * @see docs/superpowers/specs/2026-07-15-engine-fixedpoint-design.md
 */
final class FixedPoint
{
    public const SCALE = 65536; // 1 << 16

    private const STEP = self::SCALE / 16; // fixed units per 1/16 degree = 4096
    private const TABLE_MAX = 1440;

    public const DEG90 = 90 * self::SCALE;
    public const DEG180 = 180 * self::SCALE;
    public const DEG360 = 360 * self::SCALE;

    /**
     * Multiplies two plain integers, refusing to overflow into a float.
     */
    private static function mulExact(int $a, int $b): int
    {
        if ($a !== 0 && $b !== 0) {
            $limit = intdiv(\PHP_INT_MAX, \abs($a));
            if (\abs($b) > $limit) {
                throw new \RangeException("fixedPoint: multiply {$a} * {$b} overflows 64 bits");
            }
        }

        return $a * $b;
    }

    public static function fromInt(int $n): int
    {
        return self::mulExact($n, self::SCALE);
    }

    /** DISPLAY ONLY. Never feed the result back into engine math. */
    public static function toFloat(int $x): float
    {
        return $x / self::SCALE;
    }

    public static function neg(int $x): int
    {
        return -$x;
    }

    public static function abs(int $x): int
    {
        return $x < 0 ? -$x : $x;
    }

    public static function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public static function sub(int $a, int $b): int
    {
        return $a - $b;
    }

    public static function cmp(int $a, int $b): int
    {
        return $a <=> $b;
    }

    public static function mul(int $a, int $b): int
    {
        return intdiv(self::mulExact($a, $b), self::SCALE);
    }

    public static function div(int $a, int $b): int
    {
        if (0 === $b) {
            throw new \DivisionByZeroError('fixedPoint.div by zero');
        }

        return intdiv(self::mulExact($a, self::SCALE), $b);
    }

    /**
     * Exact value with magnitude |int| + num/den; a negative int makes the
     * whole value negative.
     */
    public static function fromParts(int $int, int $num, int $den): int
    {
        if ($den <= 0) {
            throw new \InvalidArgumentException('fromParts den must be positive');
        }
        if ($num < 0) {
            throw new \InvalidArgumentException('fromParts num must be non-negative');
        }

        $whole = self::mulExact($int, self::SCALE);
        $frac = intdiv(self::mulExact($num, self::SCALE), $den);

        return $int < 0 ? $whole - $frac : $whole + $frac;
    }

    /** Exact decimal parse, e.g. "9.81", "-0.5", "3". No floats. */
    public static function fromString(string $s): int
    {
        if (1 !== preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($s), $m)) {
            throw new \InvalidArgumentException("fromString cannot parse {$s}");
        }

        $fracDigits = $m[3] ?? '';
        // 10^19 already exceeds PHP_INT_MAX, so refuse before the denominator
        // silently becomes a float and drifts from the TypeScript engine.
        if (\strlen($fracDigits) > 18) {
            throw new \InvalidArgumentException('fromString: too many fractional digits');
        }

        $negative = '-' === $m[1];
        $mag = self::mulExact((int) $m[2], self::SCALE);

        if ('' !== $fracDigits) {
            $den = 10 ** \strlen($fracDigits);
            $mag += intdiv(self::mulExact((int) $fracDigits, self::SCALE), $den);
        }

        return $negative ? -$mag : $mag;
    }

    /** Floor integer square root, Newton's method, integer-only. */
    public static function isqrt(int $n): int
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('isqrt of negative');
        }
        if ($n < 2) {
            return $n;
        }

        $x0 = $n >> 1;
        $x1 = ($x0 + intdiv($n, $x0)) >> 1;
        while ($x1 < $x0) {
            $x0 = $x1;
            $x1 = ($x0 + intdiv($n, $x0)) >> 1;
        }

        return $x0;
    }

    /** sqrt(x) for x >= 0. */
    public static function sqrt(int $x): int
    {
        if ($x < 0) {
            throw new \InvalidArgumentException('fixedPoint.sqrt of negative');
        }

        return self::isqrt(self::mulExact($x, self::SCALE));
    }

    /** Sine for a fixed angle in [0, 90*SCALE], table lookup + interpolation. */
    private static function sineFirstQuadrant(int $a): int
    {
        $i = intdiv($a, self::STEP); // a >= 0, so this floors
        if ($i >= self::TABLE_MAX) {
            return SineTable::VALUES[self::TABLE_MAX];
        }

        $lo = SineTable::VALUES[$i];
        $hi = SineTable::VALUES[$i + 1];
        $frac = $a - $i * self::STEP;

        return $lo + intdiv(($hi - $lo) * $frac, self::STEP);
    }

    public static function sinDeg(int $angle): int
    {
        $a = $angle % self::DEG360; // truncates toward zero, as BigInt % does
        if ($a < 0) {
            $a += self::DEG360;
        }

        if ($a < self::DEG90) {
            return self::sineFirstQuadrant($a);
        }
        if ($a < self::DEG180) {
            return self::sineFirstQuadrant(self::DEG180 - $a);
        }
        if ($a < 3 * self::DEG90) {
            return -self::sineFirstQuadrant($a - self::DEG180);
        }

        return -self::sineFirstQuadrant(self::DEG360 - $a);
    }

    public static function cosDeg(int $angle): int
    {
        return self::sinDeg(self::DEG90 - $angle);
    }
}
