<?php

namespace App\Services\Media;

use GdImage;
use RuntimeException;

/**
 * Pulls a small palette out of raw image bytes with GD: the image is
 * downscaled, pixels are bucketed (4 bits per channel) and the busiest
 * buckets are averaged back into colours.
 */
class PaletteExtractor
{
    private const SAMPLE_SIZE = 48;

    /**
     * @return array{dominant: string, vibrant: string, muted: string, dark: string, light: string, colors: list<string>}
     */
    public function extract(string $bytes, int $maxPixels = 24_000_000): array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new RuntimeException('Not an image.');
        }
        if ($maxPixels < $info[0] * $info[1]) {
            throw new RuntimeException('Image too large.');
        }

        $source = @imagecreatefromstring($bytes);
        if (! $source instanceof GdImage) {
            throw new RuntimeException('Unreadable image.');
        }

        $sample = imagecreatetruecolor(self::SAMPLE_SIZE, self::SAMPLE_SIZE);
        imagecopyresampled($sample, $source, 0, 0, 0, 0, self::SAMPLE_SIZE, self::SAMPLE_SIZE, imagesx($source), imagesy($source));
        unset($source);

        /** @var array<int, array{n: int, r: int, g: int, b: int}> $buckets */
        $buckets = [];
        for ($y = 0; $y < self::SAMPLE_SIZE; $y++) {
            for ($x = 0; $x < self::SAMPLE_SIZE; $x++) {
                $rgba = imagecolorat($sample, $x, $y);
                if ((($rgba >> 24) & 0x7F) > 100) {
                    continue;
                }
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                $key = (($r >> 4) << 8) | (($g >> 4) << 4) | ($b >> 4);
                $bucket = $buckets[$key] ?? ['n' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
                $bucket['n']++;
                $bucket['r'] += $r;
                $bucket['g'] += $g;
                $bucket['b'] += $b;
                $buckets[$key] = $bucket;
            }
        }
        unset($sample);

        if ($buckets === []) {
            throw new RuntimeException('Image has no opaque pixels.');
        }

        usort($buckets, fn ($a, $b) => $b['n'] <=> $a['n']);
        $total = array_sum(array_column($buckets, 'n'));

        /** @var list<array{rgb: array{int, int, int}, share: float, s: float, l: float}> $swatches */
        $swatches = [];
        foreach (array_slice($buckets, 0, 24) as $bucket) {
            $rgb = [
                (int) round($bucket['r'] / $bucket['n']),
                (int) round($bucket['g'] / $bucket['n']),
                (int) round($bucket['b'] / $bucket['n']),
            ];
            [$s, $l] = $this->saturationLightness($rgb);
            $swatches[] = ['rgb' => $rgb, 'share' => $bucket['n'] / $total, 's' => $s, 'l' => $l];
        }

        $dominant = $swatches[0];
        $vibrant = $this->best($swatches, fn ($c) => $c['l'] > 0.25 && $c['l'] < 0.8, fn ($c) => $c['s'] * 2 + sqrt($c['share'])) ?? $dominant;
        $muted = $this->best($swatches, fn ($c) => $c['s'] < 0.45 && $c['l'] > 0.2 && $c['l'] < 0.75, fn ($c) => $c['share']) ?? $dominant;
        $dark = $this->best($swatches, fn ($c) => $c['l'] < 0.3, fn ($c) => $c['share'] + (0.3 - $c['l'])) ?? $dominant;
        $light = $this->best($swatches, fn ($c) => $c['l'] > 0.7, fn ($c) => $c['share'] + $c['l']) ?? $dominant;

        return [
            'dominant' => $this->hex($dominant['rgb']),
            'vibrant' => $this->hex($vibrant['rgb']),
            'muted' => $this->hex($muted['rgb']),
            'dark' => $this->hex($dark['rgb']),
            'light' => $this->hex($light['rgb']),
            'colors' => array_values(array_unique(array_map(
                fn ($c) => $this->hex($c['rgb']),
                array_slice($swatches, 0, 6),
            ))),
        ];
    }

    /**
     * @template T of array
     *
     * @param  list<T>  $swatches
     * @param  callable(T): bool  $filter
     * @param  callable(T): float  $score
     * @return T|null
     */
    private function best(array $swatches, callable $filter, callable $score): ?array
    {
        $best = null;
        $bestScore = -INF;
        foreach ($swatches as $swatch) {
            if (! $filter($swatch)) {
                continue;
            }
            $value = $score($swatch);
            if ($value > $bestScore) {
                $best = $swatch;
                $bestScore = $value;
            }
        }

        return $best;
    }

    /**
     * @param  array{int, int, int}  $rgb
     * @return array{float, float}
     */
    private function saturationLightness(array $rgb): array
    {
        $r = $rgb[0] / 255.0;
        $g = $rgb[1] / 255.0;
        $b = $rgb[2] / 255.0;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        $denominator = 1 - abs(2 * $l - 1);
        $s = $d <= 0.0 || $denominator <= 0.0 ? 0.0 : min(1.0, $d / $denominator);

        return [round($s, 4), round($l, 4)];
    }

    /**
     * @param  array{int, int, int}  $rgb
     */
    private function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }
}
