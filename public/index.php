<?php

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

// Confiar en el túnel de Cloudflare y la red Docker para que reconozca HTTPS y evite contenido mixto
Request::setTrustedProxies(
    ['127.0.0.1', 'REMOTE_ADDR', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
    Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO
);

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
