<?php

return [

    'business_name' => env('BUSINESS_NAME', 'Takoyaki Stall'),

    // Prefix used for order numbers, e.g. TK-261016-00042
    'order_prefix' => env('ORDER_PREFIX', 'TK'),

    // When false, an order that needs more stock than is on hand is rejected.
    // Set ALLOW_NEGATIVE_STOCK=true if your stock counts are not always up to date
    // and you never want the cashier to be blocked.
    'allow_negative_stock' => (bool) env('ALLOW_NEGATIVE_STOCK', false),

    'payment_methods' => ['cash', 'gcash', 'maya', 'other'],

    'expense_categories' => [
        'Rent',
        'Utilities',
        'Gas / LPG',
        'Salaries',
        'Packaging',
        'Marketing',
        'Equipment',
        'Transportation',
        'Other',
    ],
];
