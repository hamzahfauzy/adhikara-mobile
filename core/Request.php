<?php

declare(strict_types=1);

final class Request
{
    private static ?array $jsonBody = null;

    public static function input(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (stripos($contentType, 'application/json') !== false) {
            if (self::$jsonBody === null) {
                $raw = file_get_contents('php://input');
                $decoded = json_decode($raw ?: '{}', true);

                self::$jsonBody = is_array($decoded) ? $decoded : [];
            }

            return self::$jsonBody;
        }

        return $_POST;
    }

    public static function query(): array
    {
        return $_GET;
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? null;

        if ($name === 'Content-Type') {
            $value = $_SERVER['CONTENT_TYPE'] ?? $value;
        }

        return is_string($value) ? $value : null;
    }

    public static function bearerToken(): ?string
    {
        $authorization = self::header('Authorization');

        if (!$authorization ||
            !preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches)) {
            return null;
        }

        return $matches[1];
    }

    public static function uploadedFile(string $key): ?array
    {
        if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
            return null;
        }

        return $_FILES[$key];
    }
}
