
<?php

class Env
{
    private static array $data = [];

    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(
                'File konfigurasi .env tidak ditemukan.'
            );
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // Abaikan komentar
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $position = strpos($line, '=');

            if ($position === false) {
                continue;
            }

            $key = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));

            if ($key === '') {
                continue;
            }

            // Hapus tanda kutip pembungkus
            if (
                strlen($value) >= 2 &&
                (
                    ($value[0] === '"' && substr($value, -1) === '"') ||
                    ($value[0] === "'" && substr($value, -1) === "'")
                )
            ) {
                $value = substr($value, 1, -1);
            }

            self::$data[$key] = $value;

            // Jangan menimpa environment variable dari server
            if (getenv($key) === false) {
                putenv("$key=$value");
            }
        }
    }

    public static function get(
        string $key,
        mixed $default = null
    ): mixed {
        $value = self::$data[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return $value;
    }

    public static function bool(
        string $key,
        bool $default = false
    ): bool {
        return filter_var(
            self::get($key, $default),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    public static function int(
        string $key,
        int $default = 0
    ): int {
        return (int) self::get($key, $default);
    }
}
