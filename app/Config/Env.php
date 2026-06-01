<?php
namespace App\Config;

/**
 * Simple .env loader without external dependencies.
 */
class Env
{
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        if (!is_file($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\"'");
            self::$vars[$key] = $value;
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
        }
        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $val = self::$vars[$key] ?? $_ENV[$key] ?? null;
        if ($val === null || $val === '') {
            return $default;
        }
        return $val;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $val = self::get($key);
        return ($val !== null && $val !== '') ? (int) $val : $default;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $val = self::get($key);
        if ($val === null || $val === '') {
            return $default;
        }
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }
}
