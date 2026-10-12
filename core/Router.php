<?php

declare(strict_types=1);

final class Router
{
    private array $routes = [];

    public function get(
        string $path,
        callable|array $handler,
        bool $auth = true
    ): void {
        $this->add('GET', $path, $handler, $auth);
    }

    public function post(
        string $path,
        callable|array $handler,
        bool $auth = true
    ): void {
        $this->add('POST', $path, $handler, $auth);
    }

    private function add(
        string $method,
        string $path,
        callable|array $handler,
        bool $auth
    ): void {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler,
            'auth' => $auth,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);

        // Hilangkan query string jika path berasal dari URL lengkap.
        $path = parse_url($path, PHP_URL_PATH) ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->match($route['path'], $path);

            if ($params === null) {
                continue;
            }

            if ($route['auth']) {
                EmployeeAuth::handle();
            }

            // Controller menerima parameter berdasarkan nama.
            ($route['handler'])(...$params);

            return;
        }

        Response::json(404, 404, 'Endpoint tidak ditemukan');
    }

    private function match(string $routePath, string $requestPath): ?array
    {
        // Ubah /users/{id} menjadi pola regex.
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn(array $matches): string =>
                '(?P<' . $matches[1] . '>[^/]+)',
            $routePath
        );

        $pattern = '#^' . $pattern . '/?$#';

        if (!preg_match($pattern, $requestPath, $matches)) {
            return null;
        }

        $params = [];

        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = rawurldecode($value);
            }
        }

        return $params;
    }
}