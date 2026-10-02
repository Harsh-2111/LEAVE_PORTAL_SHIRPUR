<?php
function env(string $key, $default = null)
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

define('APP_ENV', env('APP_ENV', getenv('VERCEL') ? 'production' : 'development'));
define('DB_DRIVER', strtolower(env('DB_DRIVER', 'mysql')));
define('DB_HOST', env('DB_HOST', 'db'));
define('DB_PORT', env('DB_PORT', DB_DRIVER === 'pgsql' ? '6543' : '3306'));
define('DB_NAME', env('DB_NAME', DB_DRIVER === 'pgsql' ? 'postgres' : 'hostel_leave'));
define('DB_USER', env('DB_USER', DB_DRIVER === 'pgsql' ? '' : 'hostel_app'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_SSLMODE', env('DB_SSLMODE', 'require'));
define('QR_HMAC_SECRET', env('QR_HMAC_SECRET', ''));
define('HOSTEL_EMAIL', env('HOSTEL_EMAIL', 'hostel@college.edu'));
