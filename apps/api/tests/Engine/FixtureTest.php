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
use NodesWars\Api\Engine\Trajectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs test-fixtures/engine-cases.json against the PHP engine.
 *
 * The same file drives the TypeScript engine. If a case disagrees between the
 * two, the engines have diverged and the game is no longer deterministic
 * across client and server. That is the whole point of this file.
 *
 * Argument encoding, matching the TypeScript runner, applied recursively:
 *   string  a Fixed int64
 *   int     a plain int
 *   object  a struct; every value decoded by these same rules
 *   array   a list; every element decoded by these same rules
 *
 * The exceptions are arguments in RAW_STRING_ARGS and struct fields in
 * RAW_STRING_FIELDS, whose strings are literal text.
 *
 * `expected` is the result with every leaf rendered as a string, in the shape
 * the function returns. The actual result is normalised the same way, so
 * scalars, nested structs and lists of structs all compare without special
 * cases.
 */
final class FixtureTest extends TestCase
{
    /**
     * Argument positions holding literal text rather than a Fixed int64. Per
     * argument, not per op, because blast.damageAt takes a weapon id *and* a
     * Fixed distance. The TypeScript runner keeps an identical table.
     *
     * @var array<string, list<int>>
     */
    private const RAW_STRING_ARGS = [
        'fixedPoint.fromString' => [0],
        'blast.radiusFor' => [0],
        'blast.damageFor' => [0],
        'blast.damageAt' => [0],
        'loot.applyReward' => [2],
        'loot.effectiveMultiplier' => [1],
    ];

    /**
     * Struct fields holding literal text. Same idea one level down:
     * blast.resolve carries its weapon id inside the input object.
     *
     * @var list<string>
     */
    private const RAW_STRING_FIELDS = ['weaponId'];

    /**
     * @return array<string, array{array{op: string, args: array<int, mixed>}, mixed}>
     */
    public static function caseProvider(): array
    {
        $path = \dirname(__DIR__, 4).'/test-fixtures/engine-cases.json';
        $raw = file_get_contents($path);
        if (false === $raw) {
            throw new \RuntimeException("could not read {$path}");
        }

        /** @var array{cases: list<array{id: string, op: string, args: array<int, mixed>, expected: mixed}>} $decoded */
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
     */
    #[DataProvider('caseProvider')]
    public function testMatchesFixture(array $case, mixed $expected): void
    {
        self::assertEquals($expected, self::normalise(self::invoke($case['op'], $case['args'])));
    }

    /** Bare ops belong to fixedPoint. */
    private static function qualify(string $op): string
    {
        return str_contains($op, '.') ? $op : 'fixedPoint.'.$op;
    }

    /** Decodes one argument value, recursing through structs and lists. */
    private static function decode(mixed $value, bool $raw): mixed
    {
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::decode(
                    $item,
                    \is_string($key) && \in_array($key, self::RAW_STRING_FIELDS, true),
                );
            }

            return $out;
        }

        if ($raw) {
            return $value;
        }

        return \is_string($value) ? (int) $value : $value;
    }

    /** Renders a result so every leaf is a string, preserving shape. */
    private static function normalise(mixed $value): mixed
    {
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::normalise($item);
            }

            return $out;
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_int($value) || \is_string($value)) {
            return (string) $value;
        }

        throw new \InvalidArgumentException('cannot normalise '.get_debug_type($value));
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function arg(array $args, int $index, string $op): mixed
    {
        $raw = self::RAW_STRING_ARGS[self::qualify($op)] ?? [];

        return self::decode($args[$index] ?? null, \in_array($index, $raw, true));
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function intArg(array $args, int $index, string $op): int
    {
        $value = self::arg($args, $index, $op);
        if (!\is_int($value)) {
            throw new \InvalidArgumentException("argument {$index} of {$op} is not an int");
        }

        return $value;
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function stringArg(array $args, int $index, string $op): string
    {
        $value = self::arg($args, $index, $op);
        if (!\is_string($value)) {
            throw new \InvalidArgumentException("argument {$index} of {$op} is not a string");
        }

        return $value;
    }

    /**
     * @param array<int, mixed> $args
     *
     * @return array<string, mixed>
     */
    private static function structArg(array $args, int $index, string $op): array
    {
        $value = self::arg($args, $index, $op);
        if (!\is_array($value)) {
            throw new \InvalidArgumentException("argument {$index} of {$op} is not a struct");
        }

        return $value;
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function invoke(string $op, array $args): mixed
    {
        return match (self::qualify($op)) {
            'fixedPoint.fromInt' => FixedPoint::fromInt(self::intArg($args, 0, $op)),
            'fixedPoint.add' => FixedPoint::add(self::intArg($args, 0, $op), self::intArg($args, 1, $op)),
            'fixedPoint.sub' => FixedPoint::sub(self::intArg($args, 0, $op), self::intArg($args, 1, $op)),
            'fixedPoint.mul' => FixedPoint::mul(self::intArg($args, 0, $op), self::intArg($args, 1, $op)),
            'fixedPoint.div' => FixedPoint::div(self::intArg($args, 0, $op), self::intArg($args, 1, $op)),
            'fixedPoint.fromString' => FixedPoint::fromString(self::stringArg($args, 0, $op)),
            'fixedPoint.fromParts' => FixedPoint::fromParts(
                self::intArg($args, 0, $op),
                self::intArg($args, 1, $op),
                self::intArg($args, 2, $op),
            ),
            'fixedPoint.sqrt' => FixedPoint::sqrt(self::intArg($args, 0, $op)),
            'fixedPoint.sinDeg' => FixedPoint::sinDeg(self::intArg($args, 0, $op)),
            'fixedPoint.cosDeg' => FixedPoint::cosDeg(self::intArg($args, 0, $op)),

            'loot.multiplier' => Loot::multiplier(self::intArg($args, 0, $op)),
            'loot.applyReward' => \count($args) > 2
                ? Loot::applyReward(
                    self::intArg($args, 0, $op),
                    self::intArg($args, 1, $op),
                    self::stringArg($args, 2, $op),
                )
                : Loot::applyReward(self::intArg($args, 0, $op), self::intArg($args, 1, $op)),
            'loot.effectiveMultiplier' => Loot::effectiveMultiplier(
                self::intArg($args, 0, $op),
                self::stringArg($args, 1, $op),
            ),

            'scoring.split' => Scoring::split(self::intArg($args, 0, $op)),

            'movePool.regen' => MovePool::regen(
                self::intArg($args, 0, $op),
                self::intArg($args, 1, $op),
                self::intArg($args, 2, $op),
            ),

            'levelCurve.xpForLevel' => LevelCurve::xpForLevel(self::intArg($args, 0, $op)),
            'levelCurve.xpToNext' => LevelCurve::xpToNext(self::intArg($args, 0, $op)),
            'levelCurve.levelForXp' => LevelCurve::levelForXp(self::intArg($args, 0, $op)),

            'blast.radiusFor' => Blast::radiusFor(self::stringArg($args, 0, $op)),
            'blast.damageFor' => Blast::damageFor(self::stringArg($args, 0, $op)),
            'blast.damageAt' => Blast::damageAt(
                self::stringArg($args, 0, $op),
                self::intArg($args, 1, $op),
            ),
            'blast.distance' => Blast::distance(
                self::vec(self::structArg($args, 0, $op)),
                self::vec(self::structArg($args, 1, $op)),
            ),
            // The TypeScript signature takes one input object; PHP keeps
            // positional parameters, so this destructures rather than the
            // fixture carrying two shapes.
            'blast.resolve' => self::resolveBlast(self::structArg($args, 0, $op)),

            'fortify.decayFactor' => Fortify::decayFactor(self::intArg($args, 0, $op)),
            'fortify.remainingShield' => Fortify::remainingShield(
                self::intArg($args, 0, $op),
                self::intArg($args, 1, $op),
            ),
            'fortify.applyDamage' => Fortify::applyDamage(
                self::intArg($args, 0, $op),
                self::intArg($args, 1, $op),
                self::intArg($args, 2, $op),
            ),

            'trajectory.compute' => self::computeTrajectory(self::structArg($args, 0, $op)),

            default => throw new \InvalidArgumentException("unknown op {$op}"),
        };
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{x: int, y: int}
     */
    private static function vec(array $input): array
    {
        if (!\is_int($input['x'] ?? null) || !\is_int($input['y'] ?? null)) {
            throw new \InvalidArgumentException('expected a Vec2 with int x and y');
        }

        return ['x' => $input['x'], 'y' => $input['y']];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<array{targetIndex: int, distanceM: int, withinRadius: bool, damage: int}>
     */
    private static function resolveBlast(array $input): array
    {
        $center = $input['center'] ?? null;
        $weaponId = $input['weaponId'] ?? null;
        $targets = $input['targets'] ?? null;

        if (!\is_array($center) || !\is_string($weaponId) || !\is_array($targets)) {
            throw new \InvalidArgumentException('blast.resolve expects center, weaponId, targets');
        }

        $points = [];
        foreach ($targets as $target) {
            if (!\is_array($target)) {
                throw new \InvalidArgumentException('blast.resolve targets must be Vec2 structs');
            }
            $points[] = self::vec($target);
        }

        return Blast::resolve(self::vec($center), $weaponId, $points);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{impact: array{x: int, y: int}, flightTimeS: int, apogeeM: int}
     */
    private static function computeTrajectory(array $input): array
    {
        $velocity = $input['velocity'] ?? null;
        $angleDeg = $input['angleDeg'] ?? null;
        $directionDeg = $input['directionDeg'] ?? null;

        if (!\is_int($velocity) || !\is_int($angleDeg) || !\is_int($directionDeg)) {
            throw new \InvalidArgumentException(
                'trajectory.compute expects velocity, angleDeg, directionDeg',
            );
        }

        return Trajectory::compute($velocity, $angleDeg, $directionDeg);
    }

    public function testRawStringTablesMatchTheTypeScriptRunner(): void
    {
        // Guards the encoding contract. If these drift, a fixture argument gets
        // parsed as a Fixed in one engine and as text in the other, and the
        // parity suite starts comparing unrelated values.
        self::assertSame(
            [
                'fixedPoint.fromString' => [0],
                'blast.radiusFor' => [0],
                'blast.damageFor' => [0],
                'blast.damageAt' => [0],
                'loot.applyReward' => [2],
                'loot.effectiveMultiplier' => [1],
            ],
            self::RAW_STRING_ARGS,
        );
        self::assertSame(['weaponId'], self::RAW_STRING_FIELDS);
    }
}
