<?php

return [
    'name'          => $_ENV['APP_NAME'] ?? 'Menakan Transport HR Portal',
    'env'           => $_ENV['APP_ENV'] ?? 'production',
    'debug'         => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'url'           => rtrim($_ENV['APP_URL'] ?? 'http://localhost', '/'),
    'base_path'     => rtrim($_ENV['APP_BASE_PATH'] ?? '', '/'),
    'timezone'      => $_ENV['APP_TIMEZONE'] ?? 'Europe/London',
    'session_idle'  => (int) ($_ENV['SESSION_IDLE_MINUTES'] ?? 30),
    'session_name'  => $_ENV['SESSION_COOKIE'] ?? 'MTHRMSSID',
    'storage_path'  => dirname(__DIR__) . '/storage',
    'upload_limits' => [
        'documents' => 10 * 1024 * 1024,
        'payslips'  => 10 * 1024 * 1024,
        'imports'   => 5 * 1024 * 1024,
    ],
    // Company webmail (cPanel / Roundcube). Staff sign in on that page; the HRMS never handles email passwords.
    'webmail_url'   => $_ENV['WEBMAIL_URL'] ?? 'https://ns3-777.333servers.com:2096/',
    'mail' => [
        'driver' => $_ENV['MAIL_DRIVER'] ?? 'log',
        'from'   => $_ENV['MAIL_FROM'] ?? 'hr@menakantransport.com',
        'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'Menakan Transport HR',
        'smtp_host' => $_ENV['SMTP_HOST'] ?? '',
        'smtp_port' => (int) ($_ENV['SMTP_PORT'] ?? 587),
        'smtp_username' => $_ENV['SMTP_USERNAME'] ?? '',
        'smtp_password' => $_ENV['SMTP_PASSWORD'] ?? '',
        'smtp_encryption' => $_ENV['SMTP_ENCRYPTION'] ?? 'tls',
    ],
];
