<?php

namespace Inspire\Frontend\twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class ColorTwigExtension extends AbstractExtension
{
    public function getName(): string
    {
        return 'Inspire Frontend Color Twig Extension';
    }

    public function getFilters(): array
    {
        return [
            // Conversion
            new TwigFilter('toHex', [$this, 'toHex']),
            new TwigFilter('toHexa', [$this, 'toHexa']),
            new TwigFilter('toHsl', [$this, 'toHsl']),
            new TwigFilter('toHsla', [$this, 'toHsla']),
            new TwigFilter('toHsv', [$this, 'toHsv']),
            new TwigFilter('toRgb', [$this, 'toRgb']),
            new TwigFilter('toRgba', [$this, 'toRgba']),
            new TwigFilter('rawColor', [$this, 'rawColor']),

            // Manipulation
            new TwigFilter('saturate', [$this, 'saturate']),
            new TwigFilter('desaturate', [$this, 'desaturate']),
            new TwigFilter('grayscale', [$this, 'grayscale']),
            new TwigFilter('lighten', [$this, 'lighten']),
            new TwigFilter('darken', [$this, 'darken']),
            new TwigFilter('brighten', [$this, 'brighten']),
            new TwigFilter('mix', [$this, 'mix']),
            new TwigFilter('tint', [$this, 'tint']),
            new TwigFilter('shade', [$this, 'shade']),
            new TwigFilter('fade', [$this, 'fade']),
            new TwigFilter('fadeIn', [$this, 'fadeIn']),
            new TwigFilter('fadeOut', [$this, 'fadeOut']),
            new TwigFilter('complementary', [$this, 'complementary']),
            new TwigFilter('isLight', [$this, 'isLight']),
            new TwigFilter('isDark', [$this, 'isDark']),
        ];
    }

    public function toHex(string $value, bool $asArray = false)
    {
        return $this->convert('hex', $value, $asArray);
    }

    public function toHexa(string $value, bool $asArray = false)
    {
        return $this->convert('hexa', $value, $asArray);
    }

    public function toHsl(string $value, bool $asArray = false)
    {
        return $this->convert('hsl', $value, $asArray);
    }

    public function toHsla(string $value, bool $asArray = false)
    {
        return $this->convert('hsla', $value, $asArray);
    }

    public function toHsv(string $value, bool $asArray = false)
    {
        return $this->convert('hsv', $value, $asArray);
    }

    public function toRgb(string $value, bool $asArray = false)
    {
        return $this->convert('rgb', $value, $asArray);
    }

    public function toRgba(string $value, bool $asArray = false)
    {
        return $this->convert('rgba', $value, $asArray);
    }

    public function rawColor(mixed $value)
    {
        if (!is_array($value)) {
            return str_replace(['#', 'hsl(', 'hsla(', 'hsv(', 'rgb(', 'rgba(', ')'], '', $value);
        }
    }

    public function saturate(string $value, int $amount = 0)
    {
        return $this->adjustHsl($value, saturation: $amount);
    }

    public function desaturate(string $value, int $amount = 0)
    {
        return $this->adjustHsl($value, saturation: -$amount);
    }

    public function grayscale(string $value)
    {
        return $this->desaturate($value, 100);
    }

    public function lighten(string $value, int $amount = 10)
    {
        return $this->adjustHsl($value, lightness: $amount);
    }

    public function darken(string $value, int $amount = 10)
    {
        return $this->adjustHsl($value, lightness: -$amount);
    }

    public function brighten(string $value, int $amount = 10)
    {
        $parsed = $this->parseColor($value);
        if (!$parsed) {
            return $value;
        }
        $parsed['r'] = $this->clamp255($parsed['r'] - round(255 * (-$amount / 100)));
        $parsed['g'] = $this->clamp255($parsed['g'] - round(255 * (-$amount / 100)));
        $parsed['b'] = $this->clamp255($parsed['b'] - round(255 * (-$amount / 100)));
        return $this->formatByModel($parsed, $parsed['model']);
    }

    public function mix(string $value, string $value2, int $amount = 0)
    {
        $first = $this->parseColor($value);
        $second = $this->parseColor($value2);
        if (!$first || !$second) {
            return $value;
        }
        $weight = $this->clamp01($amount / 100);
        $first['r'] = $first['r'] * (1 - $weight) + $second['r'] * $weight;
        $first['g'] = $first['g'] * (1 - $weight) + $second['g'] * $weight;
        $first['b'] = $first['b'] * (1 - $weight) + $second['b'] * $weight;
        return $this->formatByModel($first, $first['model']);
    }

    public function tint(string $value, int $amount = 0)
    {
        return $this->mix($value, 'rgb(255,255,255)', $amount);
    }

    public function shade(string $value, int $amount = 0)
    {
        return $this->mix($value, 'rgb(0,0,0)', $amount);
    }

    public function fade(string $value, int $amount = 0)
    {
        $parsed = $this->parseColor($value);
        if (!$parsed) {
            return $value;
        }
        $parsed['a'] = $this->clamp01($amount / 100);
        return $this->formatByModel($parsed, $this->alphaModel($parsed['model']));
    }

    public function fadeIn(string $value, int $amount = 0)
    {
        $parsed = $this->parseColor($value);
        if (!$parsed) {
            return $value;
        }
        $parsed['a'] = $this->clamp01($parsed['a'] + ($amount / 100));
        return $this->formatByModel($parsed, $this->alphaModel($parsed['model']));
    }

    public function fadeOut(string $value, int $amount = 0)
    {
        return $this->fadeIn($value, -$amount);
    }

    public function complementary(string $value)
    {
        $parsed = $this->parseColor($value);
        if (!$parsed) {
            return $value;
        }
        [$h, $s, $l] = $this->rgbToHsl($parsed['r'], $parsed['g'], $parsed['b']);
        $h = fmod($h + 180, 360);
        [$r, $g, $b] = $this->hslToRgb($h, $s, $l);
        $parsed['r'] = $r;
        $parsed['g'] = $g;
        $parsed['b'] = $b;
        return $this->formatByModel($parsed, $parsed['model']);
    }

    public function isLight(string $value)
    {
        $parsed = $this->parseColor($value);
        if (!$parsed) {
            return false;
        }
        $darkness = 1 - (0.299 * $parsed['r'] + 0.587 * $parsed['g'] + 0.114 * $parsed['b']) / 255;
        return $darkness < 0.5;
    }

    public function isDark(string $value)
    {
        return !$this->isLight($value);
    }

    private function convert(string $target, string $value, bool $asArray = false)
    {
        $parsed = $this->parseColor($value);
        if (!$parsed) {
            return $value;
        }

        $result = match ($target) {
            'hex' => $this->formatHex($parsed['r'], $parsed['g'], $parsed['b']),
            'hexa' => $this->formatHexa($parsed['r'], $parsed['g'], $parsed['b'], $parsed['a']),
            'rgb' => $this->formatRgb($parsed['r'], $parsed['g'], $parsed['b']),
            'rgba' => $this->formatRgba($parsed['r'], $parsed['g'], $parsed['b'], $parsed['a']),
            'hsl' => (function () use ($parsed) {
                [$h, $s, $l] = $this->rgbToHsl($parsed['r'], $parsed['g'], $parsed['b']);
                return $this->formatHsl($h, $s, $l);
            })(),
            'hsla' => (function () use ($parsed) {
                [$h, $s, $l] = $this->rgbToHsl($parsed['r'], $parsed['g'], $parsed['b']);
                return $this->formatHsla($h, $s, $l, $parsed['a']);
            })(),
            'hsv' => (function () use ($parsed) {
                [$h, $s, $v] = $this->rgbToHsv($parsed['r'], $parsed['g'], $parsed['b']);
                return $this->formatHsv($h, $s, $v);
            })(),
            default => $value,
        };

        if (!$asArray) {
            return $result;
        }

        $values = $this->toArrayByTarget($target, $parsed);
        return $values;
    }

    private function toArrayByTarget(string $target, array $parsed): array
    {
        return match ($target) {
            'hex' => ['r' => $parsed['r'], 'g' => $parsed['g'], 'b' => $parsed['b']],
            'hexa' => ['r' => $parsed['r'], 'g' => $parsed['g'], 'b' => $parsed['b'], 'a' => $parsed['a']],
            'rgb' => ['r' => $parsed['r'], 'g' => $parsed['g'], 'b' => $parsed['b']],
            'rgba' => ['r' => $parsed['r'], 'g' => $parsed['g'], 'b' => $parsed['b'], 'a' => $parsed['a']],
            'hsl' => (function () use ($parsed) {
                [$h, $s, $l] = $this->rgbToHsl($parsed['r'], $parsed['g'], $parsed['b']);
                return ['h' => $h, 's' => $s, 'l' => $l];
            })(),
            'hsla' => (function () use ($parsed) {
                [$h, $s, $l] = $this->rgbToHsl($parsed['r'], $parsed['g'], $parsed['b']);
                return ['h' => $h, 's' => $s, 'l' => $l, 'a' => $parsed['a']];
            })(),
            'hsv' => (function () use ($parsed) {
                [$h, $s, $v] = $this->rgbToHsv($parsed['r'], $parsed['g'], $parsed['b']);
                return ['h' => $h, 's' => $s, 'v' => $v];
            })(),
            default => ['r' => $parsed['r'], 'g' => $parsed['g'], 'b' => $parsed['b'], 'a' => $parsed['a']],
        };
    }

    private function adjustHsl(string $value, int $saturation = 0, int $lightness = 0)
    {
        $parsed = $this->parseColor($value);
        if (!$parsed) {
            return $value;
        }
        [$h, $s, $l] = $this->rgbToHsl($parsed['r'], $parsed['g'], $parsed['b']);
        $s = $this->clamp01(($s + $saturation) / 100) * 100;
        $l = $this->clamp01(($l + $lightness) / 100) * 100;
        [$r, $g, $b] = $this->hslToRgb($h, $s, $l);
        $parsed['r'] = $r;
        $parsed['g'] = $g;
        $parsed['b'] = $b;
        return $this->formatByModel($parsed, $parsed['model']);
    }

    private function formatByModel(array $parsed, string $model): string
    {
        return match ($model) {
            'hex' => $this->formatHex($parsed['r'], $parsed['g'], $parsed['b']),
            'hexa' => $this->formatHexa($parsed['r'], $parsed['g'], $parsed['b'], $parsed['a']),
            'rgb' => $this->formatRgb($parsed['r'], $parsed['g'], $parsed['b']),
            'rgba' => $this->formatRgba($parsed['r'], $parsed['g'], $parsed['b'], $parsed['a']),
            'hsl' => (function () use ($parsed) {
                [$h, $s, $l] = $this->rgbToHsl($parsed['r'], $parsed['g'], $parsed['b']);
                return $this->formatHsl($h, $s, $l);
            })(),
            'hsla' => (function () use ($parsed) {
                [$h, $s, $l] = $this->rgbToHsl($parsed['r'], $parsed['g'], $parsed['b']);
                return $this->formatHsla($h, $s, $l, $parsed['a']);
            })(),
            'hsv' => (function () use ($parsed) {
                [$h, $s, $v] = $this->rgbToHsv($parsed['r'], $parsed['g'], $parsed['b']);
                return $this->formatHsv($h, $s, $v);
            })(),
            'hsva' => (function () use ($parsed) {
                [$h, $s, $v] = $this->rgbToHsv($parsed['r'], $parsed['g'], $parsed['b']);
                return $this->formatHsv($h, $s, $v);
            })(),
            default => $this->formatRgba($parsed['r'], $parsed['g'], $parsed['b'], $parsed['a']),
        };
    }

    private function alphaModel(string $model): string
    {
        return match ($model) {
            'hsl', 'hsla' => 'hsla',
            default => 'rgba',
        };
    }

    private function parseColor(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '#')) {
            $hex = strtolower(ltrim($value, '#'));
            if (strlen($hex) === 3 || strlen($hex) === 4) {
                $hex = implode('', array_map(fn($c) => $c . $c, str_split($hex)));
            }
            if (strlen($hex) === 6) {
                return [
                    'r' => hexdec(substr($hex, 0, 2)),
                    'g' => hexdec(substr($hex, 2, 2)),
                    'b' => hexdec(substr($hex, 4, 2)),
                    'a' => 1.0,
                    'model' => 'hex',
                ];
            }
            if (strlen($hex) === 8) {
                return [
                    'r' => hexdec(substr($hex, 0, 2)),
                    'g' => hexdec(substr($hex, 2, 2)),
                    'b' => hexdec(substr($hex, 4, 2)),
                    'a' => round(hexdec(substr($hex, 6, 2)) / 255, 2),
                    'model' => 'hexa',
                ];
            }
            return null;
        }

        if (preg_match('/^(rgba?|hsla?|hsva?|hsv)\((.+)\)$/i', $value, $matches)) {
            $fn = strtolower($matches[1]);
            $content = trim($matches[2]);
            $content = str_replace('/', ',', $content);
            $parts = str_contains($content, ',') ? array_map('trim', explode(',', $content)) : preg_split('/\s+/', $content);

            if (in_array($fn, ['rgb', 'rgba'], true)) {
                $r = $this->parseRgbChannel($parts[0] ?? '0');
                $g = $this->parseRgbChannel($parts[1] ?? '0');
                $b = $this->parseRgbChannel($parts[2] ?? '0');
                $a = $this->parseAlpha($parts[3] ?? '1');
                return ['r' => $r, 'g' => $g, 'b' => $b, 'a' => $a, 'model' => $fn];
            }

            if (in_array($fn, ['hsl', 'hsla'], true)) {
                $h = $this->parseHue($parts[0] ?? '0');
                $s = $this->parsePercent($parts[1] ?? '0');
                $l = $this->parsePercent($parts[2] ?? '0');
                $a = $this->parseAlpha($parts[3] ?? '1');
                [$r, $g, $b] = $this->hslToRgb($h, $s, $l);
                return ['r' => $r, 'g' => $g, 'b' => $b, 'a' => $a, 'model' => $fn];
            }

            if (in_array($fn, ['hsv', 'hsva'], true)) {
                $h = $this->parseHue($parts[0] ?? '0');
                $s = $this->parsePercent($parts[1] ?? '0');
                $v = $this->parsePercent($parts[2] ?? '0');
                $a = $this->parseAlpha($parts[3] ?? '1');
                [$r, $g, $b] = $this->hsvToRgb($h, $s, $v);
                return ['r' => $r, 'g' => $g, 'b' => $b, 'a' => $a, 'model' => $fn];
            }
        }

        return null;
    }

    private function parseRgbChannel(string $value): int
    {
        $value = trim($value);
        if (str_ends_with($value, '%')) {
            $percent = (float) rtrim($value, '%');
            return $this->clamp255(round(255 * ($percent / 100)));
        }
        return $this->clamp255((int) round((float) $value));
    }

    private function parsePercent(string $value): float
    {
        $value = trim($value);
        return (float) rtrim($value, '%');
    }

    private function parseAlpha(string $value): float
    {
        $value = trim($value);
        if (str_ends_with($value, '%')) {
            return $this->clamp01(((float) rtrim($value, '%')) / 100);
        }
        return $this->clamp01((float) $value);
    }

    private function parseHue(string $value): float
    {
        $h = fmod((float) $value, 360.0);
        return $h < 0 ? 360 + $h : $h;
    }

    private function formatHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    private function formatHexa(int $r, int $g, int $b, float $a): string
    {
        $alpha = $this->clamp01($a);
        return sprintf('#%02x%02x%02x%02x', $r, $g, $b, (int) round($alpha * 255));
    }

    private function formatRgb(int $r, int $g, int $b): string
    {
        return 'rgb(' . implode(',', [$r, $g, $b]) . ')';
    }

    private function formatRgba(int $r, int $g, int $b, float $a): string
    {
        return 'rgba(' . implode(',', [$r, $g, $b, $this->formatAlpha($a)]) . ')';
    }

    private function formatHsl(float $h, float $s, float $l): string
    {
        return 'hsl(' . $this->formatHue($h) . ',' . $this->formatPercent($s, 1) . '%,' . $this->formatPercent($l, 1) . '%)';
    }

    private function formatHsla(float $h, float $s, float $l, float $a): string
    {
        return 'hsla(' . $this->formatHue($h) . ',' . $this->formatPercent($s, 1) . '%,' . $this->formatPercent($l, 1) . '%,' . $this->formatAlpha($a) . ')';
    }

    private function formatHsv(float $h, float $s, float $v): string
    {
        return 'hsv(' . $this->formatHue($h, 0) . ',' . round($s) . '%,' . round($v) . '%)';
    }

    private function formatHue(float $h, int $precision = 1): string
    {
        $h = round($h, $precision);
        return $this->trimFloat($h);
    }

    private function formatPercent(float $value, int $precision = 1): string
    {
        $value = round($value, $precision);
        return $this->trimFloat($value);
    }

    private function formatAlpha(float $a): string
    {
        $a = round($this->clamp01($a), 2);
        return $this->trimFloat($a);
    }

    private function trimFloat(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
        return $text === '' ? '0' : $text;
    }

    private function rgbToHsl(int $r, int $g, int $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $h = $s = 0;
        $l = ($max + $min) / 2;

        if ($max !== $min) {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
            switch ($max) {
                case $r:
                    $h = ($g - $b) / $d + ($g < $b ? 6 : 0);
                    break;
                case $g:
                    $h = ($b - $r) / $d + 2;
                    break;
                case $b:
                    $h = ($r - $g) / $d + 4;
                    break;
            }
            $h /= 6;
        }

        return [round($h * 360, 1), round($s * 100, 1), round($l * 100, 1)];
    }

    private function hslToRgb(float $h, float $s, float $l): array
    {
        $h = ($h % 360) / 360;
        $s /= 100;
        $l /= 100;

        if ($s == 0) {
            $r = $g = $b = $l;
        } else {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            $r = $this->hueToRgb($p, $q, $h + 1 / 3);
            $g = $this->hueToRgb($p, $q, $h);
            $b = $this->hueToRgb($p, $q, $h - 1 / 3);
        }

        return [
            $this->clamp255(round($r * 255)),
            $this->clamp255(round($g * 255)),
            $this->clamp255(round($b * 255)),
        ];
    }

    private function hueToRgb(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t++;
        }
        if ($t > 1) {
            $t--;
        }
        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }
        if ($t < 1 / 2) {
            return $q;
        }
        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }
        return $p;
    }

    private function rgbToHsv(int $r, int $g, int $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $v = $max;
        $d = $max - $min;
        $s = $max == 0 ? 0 : $d / $max;
        if ($max === $min) {
            $h = 0;
        } else {
            switch ($max) {
                case $r:
                    $h = ($g - $b) / $d + ($g < $b ? 6 : 0);
                    break;
                case $g:
                    $h = ($b - $r) / $d + 2;
                    break;
                case $b:
                    $h = ($r - $g) / $d + 4;
                    break;
            }
            $h /= 6;
        }

        return [round($h * 360), round($s * 100), round($v * 100)];
    }

    private function hsvToRgb(float $h, float $s, float $v): array
    {
        $h = ($h % 360) / 60;
        $s /= 100;
        $v /= 100;

        $i = floor($h);
        $f = $h - $i;
        $p = $v * (1 - $s);
        $q = $v * (1 - $s * $f);
        $t = $v * (1 - $s * (1 - $f));

        switch ((int) $i % 6) {
            case 0:
                [$r, $g, $b] = [$v, $t, $p];
                break;
            case 1:
                [$r, $g, $b] = [$q, $v, $p];
                break;
            case 2:
                [$r, $g, $b] = [$p, $v, $t];
                break;
            case 3:
                [$r, $g, $b] = [$p, $q, $v];
                break;
            case 4:
                [$r, $g, $b] = [$t, $p, $v];
                break;
            default:
                [$r, $g, $b] = [$v, $p, $q];
                break;
        }

        return [
            $this->clamp255(round($r * 255)),
            $this->clamp255(round($g * 255)),
            $this->clamp255(round($b * 255)),
        ];
    }

    private function clamp01(float $value): float
    {
        return min(1, max(0, $value));
    }

    private function clamp255(float $value): int
    {
        return (int) min(255, max(0, round($value)));
    }
}
