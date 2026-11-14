<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | ERP Module Registry
    |--------------------------------------------------------------------------
    |
    | Every domain module that can be toggled per-tenant or per-company is
    | registered here. The 'enabled' flag is the platform default; tenant-level
    | overrides live in tenant_settings.
    |
    */

    'modules' => [
        'catalog'          => ['enabled' => true,  'label' => 'Product Catalog & Pricing'],
        'inventory'        => ['enabled' => true,  'label' => 'Inventory & Warehouse'],
        'sales'            => ['enabled' => true,  'label' => 'POS & Retail Sales'],
        'purchasing'       => ['enabled' => true,  'label' => 'Procurement & Suppliers'],
        'finance'          => ['enabled' => true,  'label' => 'Finance & Accounting'],
        'order_management' => ['enabled' => true,  'label' => 'Sales Orders & Quotations'],
        'delivery'         => ['enabled' => false, 'label' => 'Delivery & Dispatch'],
        'production'       => ['enabled' => false, 'label' => 'Production & Recipes'],
        'people'           => ['enabled' => false, 'label' => 'HRIS & Workforce'],
        'payroll'          => ['enabled' => false, 'label' => 'Payroll'],
        'crm'              => ['enabled' => false, 'label' => 'CRM & Loyalty'],
        'rewards'          => ['enabled' => false, 'label' => 'Loyalty & Rewards'],
        'workflow'         => ['enabled' => false, 'label' => 'Tasks & Approvals'],
        'reporting'        => ['enabled' => true,  'label' => 'BI & Dashboards'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Industry Packs
    |--------------------------------------------------------------------------
    |
    | Each industry pack activates specific features and configurations on top
    | of the core modules. Multiple packs may co-exist per tenant.
    |
    */

    'industry_packs' => [
        'supermarket'   => ['label' => 'Supermarket / Grocery',    'modules' => ['catalog', 'inventory', 'sales', 'purchasing', 'finance']],
        'pharmacy'      => ['label' => 'Pharmacy',                 'modules' => ['catalog', 'inventory', 'sales', 'purchasing', 'finance']],
        'bakery'        => ['label' => 'Bakery',                   'modules' => ['catalog', 'inventory', 'sales', 'production', 'finance']],
        'restaurant'    => ['label' => 'Restaurant / QSR',         'modules' => ['catalog', 'inventory', 'sales', 'production', 'delivery', 'finance']],
        'fashion'       => ['label' => 'Fashion / Footwear',       'modules' => ['catalog', 'inventory', 'sales', 'purchasing', 'finance']],
        'optical'       => ['label' => 'Optical',                  'modules' => ['catalog', 'inventory', 'sales', 'purchasing', 'finance']],
        'wholesale'     => ['label' => 'Wholesale / Distribution', 'modules' => ['catalog', 'inventory', 'sales', 'purchasing', 'order_management', 'delivery', 'finance']],
        'manufacturing' => ['label' => 'Manufacturing',            'modules' => ['catalog', 'inventory', 'purchasing', 'production', 'finance']],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Defaults
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'currency'       => env('ERP_DEFAULT_CURRENCY', 'PKR'),
        'timezone'       => env('ERP_DEFAULT_TIMEZONE', 'Asia/Karachi'),
        'locale'         => env('ERP_DEFAULT_LOCALE', 'en'),
        'fiscal_start'   => env('ERP_FISCAL_YEAR_START', '07-01'), // July 1 for Pakistan
        'decimal_places' => (int) env('ERP_DECIMAL_PLACES', 2),
        'qty_decimals'   => (int) env('ERP_QTY_DECIMAL_PLACES', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline POS
    |--------------------------------------------------------------------------
    */

    'offline' => [
        'max_age_hours'        => (int) env('ERP_OFFLINE_MAX_AGE_HOURS', 72),
        'max_credit_amount'    => env('ERP_OFFLINE_MAX_CREDIT', '50000'),
        'max_refund_amount'    => env('ERP_OFFLINE_MAX_REFUND', '10000'),
        'sync_batch_size'      => (int) env('ERP_OFFLINE_SYNC_BATCH', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inventory Valuation
    |--------------------------------------------------------------------------
    */

    'valuation_method' => env('ERP_VALUATION_METHOD', 'weighted_average'),

    'allow_negative_stock' => (bool) env('ERP_ALLOW_NEGATIVE_STOCK', false),

];
