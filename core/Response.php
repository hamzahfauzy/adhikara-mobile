<?php

declare(strict_types=1);

final class Response
{
    public static function json(
        int $httpCode,
        int $status,
        string $message,
        array $data = []
    ): never {
        http_response_code($httpCode);

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        echo json_encode([
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }
}
