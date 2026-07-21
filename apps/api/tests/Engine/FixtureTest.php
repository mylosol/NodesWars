<?php

declare(strict_types=1);

namespace NodesWars\Api\Tests\Engine;

use NodesWars\Api\Engine\Blast;
use NodesWars\Api\Engine\FixedPoint;
use NodesWars\Api\Engine\Fortify;
use NodesWars\Api\Engine\LevelCurve;
use NodesWars\Api\Engine\Loot;
use NodesWars\Api\Engine\MovePool;
use NodesWars\Api\Engine\Scoring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs test-fixtures/engine-cases.json against the PHP engine.
 *
 * The same file drives the TypeScript engine. If a case disagrees between the
 * two, the engines have diverged and the game is no longer deterministic
 * across client and server. That is the whole point of this file.
 *
 * Argument encoding, matching the TypeScript runner: a JSON string is a Fixed
 * int64, a JSON number is a plain int. The exceptions are ops that genuinely
 * take literal text, listed in RAW_STRING_OPS.
 */
final class FixtureTest extends TestCase
{
    private const RAW_STRING_OPS = [
        'fromString',
        'fixedPoint.fromString',
        'blast.radiusFor',
    ];

    /**
     * @return array<string, array{array<int, mixed>, string|array<string, string>}>
     */
    public static function caseProvider(): array
    {
        $path = \dirname(__DIR__, 4).'/test-fixtures/engine-cases.json';
        $raw = file_get_contents($path);
        if (false === $raw) {
            throw new \RuntimeException("could not read {$path}");
        }

        /** @var array{cases: list<array{id: string, op: string, args: array<int, mixed>, expected: string|array<string, string>}>} $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        $provided = [];
        foreach ($decoded['cases'] as $case) {
            $key = $case['id'].' ('.$case['op'].')';
            $provided[$key] = [
                ['op' => $case['op'], 'args' => $case['args']],
                $case['expected'],
            ];
        }

        if ([] === $provided) {
            throw new \RuntimeException('fixture file has no cases');
        }

        return $provided;
    }

    /**
     * @param array{op: string, args: array<int, mixed>} $case
     * @param string|array<string, string>               $expected
     */
    #[DataProvider('caseProvider')]
    public function testMatchesFixture(array $case, string|array $expected): void
    {
        $result = self::invoke($case['op'], $case['args']);

        if (\is_string($expected)) {
            self::assertSame($expected, self::stringify($result));

            return;
        }

        self::assertIsArray($result, "expected a struct result for {$case['op']}");
        foreach ($expected as $field => $value) {
            self::assertArrayHasKey($field, $result);
            self::assertSame($value, self::stringify($result[$field]), "field {$field}");
        }
    }

    private static function stringify(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value; // @phpstan-ignore cast.string
    }

    /**
     * A Fixed int64 or plain int argument. Both arrive as int after this.
     *
     * @param array<int, mixed> $args
     */
    private static function intArg(array $args, int $index): int
    {
        $value = $args[$index] ?? null;
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value)) {
            return (int) $value;
        }

        throw new \InvalidArgumentException("argument {$index} is not an int or Fixed string");
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function stringArg(array $args, int $index): string
    {
        $value = $args[$index] ?? null;
        if (!\is_string($value)) {
            throw new \InvalidArgumentException("argument {$index} is not a string");
        }

        return $value;
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function invoke(string $op, array $args): mixed
    {
        // Bare ops belong to fixedPoint.
        $qualified = str_contains($op, '.') ? $op : 'fixedPoint.'.$op;

        return match ($qualified) {
            'fixedPoint.fromInt' => FixedPoint::fromInt(self::intArg($args, 0)),
            'fixedPoint.add' => FixedPoint::add(self::intArg($args, 0), self::intArg($args, 1)),
            'fixedPoint.sub' => FixedPoint::sub(self::intArg($args, 0), self::intArg($args, 1)),
            'fixedPoint.mul' => FixedPoint::mul(self::intArg($args, 0), self::intArg($args, 1)),
            'fixedPoint.div' => FixedPoint::div(self::intArg($args, 0), self::intArg($args, 1)),
            'fixedPoint.fromString' => FixedPoint::fromString(self::stringArg($args, 0)),
            'fixedPoint.fromParts' => FixedPoint::fromParts(
                self::intArg($args, 0),
                self::intArg($args, 1),
                self::intArg($args, 2),
            ),
            'fixedPoint.sqrt' => FixedPoint::sqrt(self::intArg($args, 0)),
            'fixedPoint.sinDeg' => FixedPoint::sinDeg(self::intArg($args, 0)),
            'fixedPoint.cosDeg' => FixedPoint::cosDeg(self::intArg($args, 0)),

            'loot.multiplier' => Loot::multiplier(self::intArg($args, 0)),
            'loot.applyReward' => Loot::applyReward(self::intArg($args, 0), self::intArg($args, 1)),

            'scoring.split' => Scoring::split(self::intArg($args, 0)),

            'movePool.regen' => MovePool::regen(
                self::intArg($args, 0),
                self::intArg($args, 1),
                self::intArg($args, 2),
            ),

            'levelCurve.xpForLevel' => LevelCurve::xpForLevel(self::intArg($args, 0)),
            'levelCurve.xpToNext' => LevelCurve::xpToNext(self::intArg($args, 0)),
            'levelCurve.levelForXp' => LevelCurve::levelForXp(self::intArg($args, 0)),

            'blast.radiusFor' => Blast::radiusFor(self::stringArg($args, 0)),

            'fortify.decayFactor' => Fortify::decayFactor(self::intArg($args, 0)),
            'fortify.remainingShield' => Fortify::remainingShield(
                self::intArg($args, 0),
                self::intArg($args, 1),
            ),
            'fortify.applyDamage' => Fortify::applyDamage(
                self::intArg($args, 0),
                self::intArg($args, 1),
                self::intArg($args, 2),
            ),

            default => throw new \InvalidArgumentException("unknown op {$op}"),
        };
    }

    public function testRawStringOpsAreDocumented(): void
    {
        // Guards the encoding contract: these ops take literal text, and the
        // TypeScript runner keeps the same list.
        self::assertContains('blast.radiusFor', self::RAW_STRING_OPS);
        self::assertContains('fromString', self::RAW_STRING_OPS);
    }
}
