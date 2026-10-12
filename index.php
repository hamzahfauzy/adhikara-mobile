<?php

declare(strict_types=1);

require_once __DIR__ . '/core/Env.php';

Env::load(__DIR__ . '/.env');

date_default_timezone_set(
    Env::get('APP_TIMEZONE', 'Asia/Jakarta')
);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Request.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Router.php';
require_once __DIR__ . '/core/AttendancePhoto.php';
require_once __DIR__ . '/middleware/EmployeeAuth.php';

require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/HomeController.php';
require_once __DIR__ . '/controllers/AttendanceController.php';
require_once __DIR__ . '/controllers/RequestController.php';

// Ganti dengan origin aplikasi mobile/web yang benar jika diperlukan.
// Untuk aplikasi native, biasanya tidak ada header Origin.
$allowedOrigins = array_filter(array_map(
    'trim',
    explode(',', Env::get('CORS_ALLOWED_ORIGINS', ''))
));

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    require __DIR__ . '/routes/api.php';

    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    // URL publik menggunakan prefix /api/v1.
    $prefix = '/api/v1';

    if ($uriPath === $prefix) {
        $routePath = '/';
    } elseif (str_starts_with($uriPath, $prefix . '/')) {
        $routePath = substr($uriPath, strlen($prefix));
    } else {
        Response::json(404, 404, 'Endpoint tidak ditemukan');
    }

    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $routePath);

} catch (Throwable $e) {
    error_log($e->getMessage());

    Response::json(500, 500, 'Terjadi kesalahan pada server, '. $e->getMessage());
}
