<?php

if (!function_exists('ems_load_env_file')) {
    function ems_load_env_file(string $path): void
    {
        static $loaded = [];

        $realPath = realpath($path) ?: $path;
        if (isset($loaded[$realPath]) || !is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $separatorPos = strpos($trimmed, '=');
            if ($separatorPos === false) {
                continue;
            }

            $name = trim(substr($trimmed, 0, $separatorPos));
            $value = trim(substr($trimmed, $separatorPos + 1));
            if ($name === '') {
                continue;
            }

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $value = str_replace(['\r', '\n'], ["\r", "\n"], $value);

            if (getenv($name) === false) {
                putenv($name . '=' . $value);
            }

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        $loaded[$realPath] = true;
    }
}

if (!function_exists('ems_env')) {
    function ems_env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        $normalized = strtolower(trim((string)$value));
        return match ($normalized) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

ems_load_env_file(__DIR__ . '/../.env');
