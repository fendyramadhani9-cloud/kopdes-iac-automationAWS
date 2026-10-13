<?php
/**
 * Application Global Configuration
 */

// Pastikan file env telah dimuat
if (file_exists(__DIR__ . '/../.env')) {
    require_once __DIR__ . '/../includes/env.php';
    load_env(__DIR__ . '/../.env');
}

return [
    'name' => env('APP_NAME', 'KopDes Merah Putih'),
    'tagline' => 'Platform Digital Koperasi Desa & Kelurahan',
    'humor_tagline' => 'Karena perekonomian desa juga butuh load balancing.',
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),

    // Konfigurasi Load Balancer & High Availability Node
    'node' => [
        'name' => env('SERVER_NODE', 'WEB-01'),
        'hostname' => gethostname() ?: 'unknown-host',
        'haproxy_ip' => env('HAPROXY_IP', '192.168.X.10'),
        'haproxy_status' => env('HAPROXY_STATUS', 'ONLINE'),
        'alb_dns' => env('ALB_DNS_NAME', null),
    ],

    // Konfigurasi Amazon SES
    'ses' => [
        'enabled' => filter_var(env('SES_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'region' => env('AWS_REGION', env('SES_REGION', 'ap-southeast-1')),
        'sender' => env('SES_SENDER_EMAIL', 'noreply@kopdes.id'),
        'recipient' => env('SES_RECIPIENT_EMAIL', 'admin@kopdes.id'),
    ],

    // Versi Aplikasi
    'version' => '3.0.0-aws',
];
