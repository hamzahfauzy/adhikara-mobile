<?php

declare(strict_types=1);

$router = new Router();

$router->post('/login', [AuthController::class, 'login'], false);

$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/profile', [AuthController::class, 'me']);

$router->get('/home', [HomeController::class, 'index']);

$router->get('/attendances', [AttendanceController::class, 'index']);
$router->post('/attendances', [AttendanceController::class, 'store']);

$router->get('/requests', [RequestController::class, 'index']);
$router->get('/requests/statistic', [RequestController::class, 'statistic']);
$router->get('/requests/{id}', [RequestController::class, 'show']);
$router->post('/requests', [RequestController::class, 'store']);
