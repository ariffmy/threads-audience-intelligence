<?php

declare(strict_types=1);

// Development-only router. Run with -t public; never serve the repository root.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$assets = [
    '/assets/css/app.css', '/assets/js/app.js',
    '/assets/vendor/bootstrap-5.3.8.min.css', '/assets/favicon.svg',
];
if (in_array($path, $assets, true) && in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    return false;
}
require dirname(__DIR__) . '/public/index.php';
