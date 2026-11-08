<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | "web" is the shop staff guard (owners, managers, cashiers). The platform
    | super admin and shop customers each have their own isolated guard so a
    | session for one can never be used to access another area.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        // Shop staff: /app
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        // Platform owner: /admin
        'superadmin' => [
            'driver' => 'session',
            'provider' => 'super_admins',
        ],

        // Shop customers: /portal/{shop}
        'customer' => [
            'driver' => 'session',
            'provider' => 'customers',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],

        'super_admins' => [
            'driver' => 'eloquent',
            'model' => App\Models\SuperAdmin::class,
        ],

        // Customer queries are tenant scoped, so a customer session only
        // resolves inside the shop portal it was created for.
        'customers' => [
            'driver' => 'eloquent',
            'model' => App\Models\Customer::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
