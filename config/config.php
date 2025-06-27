<?php
return [
    /* ---------- USERS & ROLES ---------- */
    'users' => [
        'admin' => [
            /* password = F0A8eMGR4dDV */
            'pass_hash' => '$2y$12$eAH791ij3Q1vwSCwrwJ5a.tgSo4CFx5HsfSgigjxSn1Us9oZOZHsa',
            'role' => 'admin',
            'totp' => null          // add Base32 secret later if you enable TOTP
        ],

        'maint' => [
            /* password = ti6rSne6Mcd1 */
            'pass_hash' => '$2y$12$v/wo3oiTeoQm2BeQKioXDe1Yy94Dls9xok.KQXZLyID4jQ2ez0EYm',
            'role' => 'maintainer',
            'totp' => null
        ],

        'viewer' => [
            /* password = 6ZNb3aOW4KXP */
            'pass_hash' => '$2y$12$IBBWlSiH0Sl0P5PvFwpEuOFlgXob/K8AOQLZfnVRr1mh7fFbEPL..',
            'role' => 'viewer',
            'totp' => null
        ],
    ],
    'roles' => [
        'admin' => ['*'],
        'maintainer' => ['view', 'backup', 'upload', 'restore'],
        'viewer' => ['view', 'download', 'logs'],
    ],

    /* ---------- DATABASES ---------- */
    'databases' => [
        'primary' => [
            'host' => 'localhost',
            'port' => 3306,
            'name' => 'prod_db',
            'user' => 'prod',
            'pass' => 'prod_pw'
        ],
    ],
    'default' => 'primary',

    /* ---------- BACKUP ---------- */
    'backup' => [
        'dir' => __DIR__ . '/../backups',
        'compress' => true,
        'prefix' => 'dump_',
    ],
    'rotation' => ['keep' => 30],

    /* ---------- SECURITY ---------- */
    'security' => [
        'encryption' => [
            'enabled' => true,
            'key' => 'REPLACE_WITH_32_BYTE_BASE64_KEY=========='
        ],
        'totp' => ['enabled' => false],
    ],

    /* ---------- STORAGE ---------- */
    'storage' => [
        's3' => [
            'enabled' => true,
            'bucket' => 'my-db-backups',
            'region' => 'us-east-1',
            'endpoint' => null,
            'access_key' => 'AKIAXXXX',
            'secret_key' => 'xxxxxxxx',
            'prefix' => 'db/',
        ],
        'rclone' => ['enabled' => false, 'remote' => 'gdrive:db', 'binary' => null],
        'ftp' => [
            'enabled' => false,
            'scheme' => 'sftp',
            'host' => 'backup.example.com',
            'port' => 22,
            'user' => 'ftp',
            'pass' => 'ftp_pw',
            'path' => '/remote/db'
        ],
    ],

    /* ---------- SCHEDULER ---------- */
    'schedule' => [
        'enabled' => true,
        'jobs' => [['db' => 'primary', 'cron' => '0 2 * * *']],
        'digest' => ['enabled' => true, 'time' => '06:00']
    ],

    /* ---------- RESTORE ---------- */
    'restore' => ['enabled' => true, 'mysql_path' => null],

    /* ---------- NOTIFICATIONS ---------- */
    'notifications' => [
        'success_drivers' => ['ses', 'slack'],
        'failure_drivers' => ['ses', 'slack', 'sns', 'twilio'],
        'drivers' => ['ses'],
        'on_success' => true,
        'on_failure' => true,
        'debug' => false,
        'to_email' => 'alerts@example.com',
        'to_phone' => '+15551234567',

        'smtp' => [
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encrypt' => 'tls',
            'username' => 'alerts@example.com',
            'password' => 'SMTP_APP_PW',
            'from' => ['address' => 'alerts@example.com', 'name' => 'DB-Backup'],
        ],
        'ses' => [
            'region' => 'us-east-1',
            'access_key' => 'AKIAXXXX',
            'secret_key' => 'xxxxxxxx',
            'from' => ['address' => 'alerts@example.com', 'name' => 'DB-Backup'],
        ],
        'sns' => [
            'region' => 'us-east-1',
            'access_key' => 'AKIAXXXX',
            'secret_key' => 'xxxxxxxx',
            'sender_id' => 'BackupBot'
        ],
        'slack' => ['webhook' => 'https://hooks.slack.com/services/T/B/C'],
        'twilio' => [
            'sid' => 'ACxxxx',
            'token' => 'xxxxxxxx',
            'from' => '+15558675309'
        ],
    ],

    /* ---------- PERFORMANCE ---------- */
    'performance' => [
        'skip_tables' => ['/^log_/', '/^cache_/', 'sessions'],
        'prefer_mysqldump' => true,
        'mysqldump_path' => null,
    ],

    /* ---------- WEBHOOK ---------- */
    'webhook' => [
        'enabled' => true,
        'url' => 'https://example.com/backup-hook',
        'secret' => 'shared-secret',
        'retries' => 3,
        'timeout' => 4,
        'events' => ['backup', 'restore', 'upload']
    ],

    'tuning' => ['chunk_size' => 1000, 'unbuffered' => true, 'memory_limit' => '1024M'],
];
