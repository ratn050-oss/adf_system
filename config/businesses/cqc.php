<?php
return [
    'business_id' => 'cqc',
    'name' => 'CQC Enjiniring',
    'business_type' => 'other',
    'database' => 'adf_cqc',
    
    // Logo (optional, jika kosong akan pakai icon)
    'logo' => '', // Contoh: 'cqc.png' di uploads/logos/
    
    'enabled_modules' => [
        'cashbook',
        'auth',
        'settings',
        'reports',
        'divisions',
        'procurement',
        'sales',
        'bills',
        'payroll'
    ],
    
    'theme' => [
        'color_primary' => '#059669',
        'color_secondary' => '#065f46',
        'icon' => '🏢'
    ],
    
    'cashbook_columns' => [],
    
    'dashboard_widgets' => [
        'show_daily_sales' => true,
        'show_orders' => true,
        'show_revenue' => true
    ]
];
