<?php
declare(strict_types=1);

function app_env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function app_database_config(): array
{
    return [
        'host' => app_env('DB_HOST', '127.0.0.1'),
        'port' => app_env('DB_PORT', '3306'),
        'name' => app_env('DB_NAME', 'lower_west_akim'),
        'user' => app_env('DB_USER', ''),
        'password' => app_env('DB_PASSWORD', ''),
    ];
}
