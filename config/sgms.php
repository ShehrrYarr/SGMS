<?php

/*
|--------------------------------------------------------------------------
| SGMS product settings
|--------------------------------------------------------------------------
*/

return [

    /*
     | Public demo. When enabled, the landing page offers one-click sign-in to
     | the demo shop's accounts, a few account-level changes are blocked for
     | those accounts, and the WHOLE DATABASE is rebuilt from the seeder every
     | night. Never enable it on an install that holds real shop data.
     */
    'demo' => [
        'enabled' => (bool) env('SGMS_DEMO', false),
        'reset_at' => env('SGMS_DEMO_RESET_AT', '03:00'),
        'shop' => 'urban-garments',
        'staff' => [
            'owner' => 'owner@urbangarments.com',
            'manager' => 'manager@urbangarments.com',
            'cashier' => 'cashier@urbangarments.com',
        ],
        'customer_phone' => '03001234567',
    ],

    /*
     | Platform super admin created by the seeder (and by the nightly demo
     | reset). Locally it defaults to admin@platform.com / password. In
     | production there is no default password: set SGMS_ADMIN_PASSWORD, or the
     | seeder gives the account a random one nobody knows.
     */
    'admin' => [
        'email' => env('SGMS_ADMIN_EMAIL', 'admin@platform.com'),
        'password' => env('SGMS_ADMIN_PASSWORD', env('APP_ENV', 'production') === 'production' ? null : 'password'),
    ],

    /*
     | Plans shown on the landing page (no prices; "Ask for a price" opens WhatsApp).
     | Keys match Tenant::PLANS.
     */
    'plans' => [
        'basic' => [
            'for' => 'For a single shop',
            'features' => [
                'One branch',
                'POS with barcode scanning and thermal receipts',
                'Lot purchases with colour and size breakdown',
                'Customer credit, vendor and cash ledgers',
                'Stock and barcode labels',
            ],
        ],
        'standard' => [
            'for' => 'For a shop with a few branches',
            'features' => [
                'Everything in Basic',
                'Up to 3 branches with stock transfers',
                'Online store with all five themes',
                'Profit and loss, sales and stock reports',
            ],
        ],
        'premium' => [
            'for' => 'For chains and wholesalers',
            'features' => [
                'Everything in Standard',
                'Unlimited branches and staff',
                'Help setting up your products and opening stock',
                'Priority support on WhatsApp',
            ],
        ],
    ],

    'contact' => [
        'whatsapp' => env('SGMS_WHATSAPP', '923120883979'),
        'whatsapp_message' => 'Hi! I need to know more about the Garment Management System',
    ],

];
