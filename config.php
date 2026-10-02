<?php
function env(string $key, $default = null)
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

define('APP_ENV', env('APP_ENV', 'development'));
define('DB_HOST', env('DB_HOST', 'db'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'hostel_leave'));
define('DB_USER', env('DB_USER', 'hostel_app'));
define('DB_PASS', env('DB_PASS', 'HostelApp!2026'));
define('QR_HMAC_SECRET', env('QR_HMAC_SECRET', 'change-me')); 
define('HOSTEL_EMAIL', env('HOSTEL_EMAIL', 'hostel@college.edu'));
