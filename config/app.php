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

    // Konfigurasi Notifikasi Amazon SNS (100% AWS Academy Learner Lab Ready)
    'sns' => [
        'topic_arn' => env('SNS_TOPIC_ARN', null),
        'region' => env('AWS_REGION', 'us-east-1'),
        'recipient' => env('SNS_RECIPIENT_EMAIL', env('SES_RECIPIENT_EMAIL', 'admin@kopdes.id')),
    ],

    // Versi Aplikasi
    'version' => '3.0.0-aws',
];
