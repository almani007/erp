<?php

declare(strict_types=1);

/**
 * Starter business templates, loaded into the `business_templates` table by
 * the seeder. After that the platform operator edits them in the SaaS admin
 * screens — this file is only the initial content.
 *
 * features: which screens/fields are shown (see resources/js/lib/config.ts)
 * custom_fields: extra fields on product | customer | supplier
 * record_types: whole record kinds the business needs, with their own fields
 */

$f = static fn (array $on): array => array_fill_keys($on, true) + array_fill_keys(['wholesale', 'packs', 'variants', 'rates', 'expiry', 'serials'], false);

return [
    ['code' => 'general', 'name' => 'General / mixed', 'description' => 'Everything switched on.',
        'features' => $f(['wholesale', 'packs', 'variants', 'rates', 'expiry', 'serials'])],

    ['code' => 'grocery', 'name' => 'Grocery & supermarket', 'features' => $f(['wholesale', 'packs', 'expiry']),
        'custom_fields' => [['entity' => 'product', 'label' => 'Shelf / aisle', 'type' => 'text']]],

    ['code' => 'pharmacy', 'name' => 'Pharmacy & medical store', 'features' => $f(['packs', 'expiry']),
        'custom_fields' => [
            ['entity' => 'product', 'label' => 'Generic name', 'type' => 'text'],
            ['entity' => 'product', 'label' => 'Schedule', 'type' => 'select', 'options' => 'OTC, Prescription only, Controlled'],
        ],
        'record_types' => [[
            'name' => 'Prescription', 'prefix' => 'RX', 'link_customer' => true, 'statuses' => 'Received, Dispensed',
            'fields' => [
                ['label' => 'Doctor', 'type' => 'text', 'required' => true],
                ['label' => 'Prescription date', 'type' => 'date', 'required' => true],
                ['label' => 'Medicines', 'type' => 'longtext', 'required' => true],
                ['label' => 'Notes', 'type' => 'longtext'],
            ],
        ]]],

    ['code' => 'garments', 'name' => 'Garments, footwear & fabric', 'features' => $f(['wholesale', 'packs', 'variants']),
        'custom_fields' => [
            ['entity' => 'product', 'label' => 'Fabric', 'type' => 'select', 'options' => 'Cotton, Lawn, Linen, Silk, Wool, Polyester'],
            ['entity' => 'product', 'label' => 'Season', 'type' => 'select', 'options' => 'Summer, Winter, All season'],
        ],
        'record_types' => [[
            'name' => 'Tailoring order', 'prefix' => 'TLR', 'link_customer' => true, 'statuses' => 'Booked, Cutting, Stitching, Ready, Delivered',
            'fields' => [
                ['label' => 'Item', 'type' => 'text', 'required' => true],
                ['label' => 'Measurements', 'type' => 'longtext'],
                ['label' => 'Delivery date', 'type' => 'date', 'required' => true],
                ['label' => 'Charges', 'type' => 'money'],
                ['label' => 'Advance received', 'type' => 'money'],
            ],
        ]]],

    ['code' => 'jewellery', 'name' => 'Gold & jewellery', 'features' => $f(['rates', 'serials']),
        'custom_fields' => [
            ['entity' => 'product', 'label' => 'Design no', 'type' => 'text'],
            ['entity' => 'product', 'label' => 'Stone', 'type' => 'text'],
            ['entity' => 'product', 'label' => 'Stone weight (ct)', 'type' => 'number'],
            ['entity' => 'customer', 'label' => 'CNIC', 'type' => 'text'],
        ],
        'record_types' => [
            [
                'name' => 'Custom order', 'prefix' => 'ORD', 'link_customer' => true, 'statuses' => 'Booked, With karigar, Ready, Delivered',
                'fields' => [
                    ['label' => 'Item description', 'type' => 'longtext', 'required' => true],
                    ['label' => 'Purity', 'type' => 'select', 'options' => '24K, 22K, 21K, 18K'],
                    ['label' => 'Estimated weight (g)', 'type' => 'number'],
                    ['label' => 'Gold rate fixed', 'type' => 'money'],
                    ['label' => 'Advance received', 'type' => 'money'],
                    ['label' => 'Delivery date', 'type' => 'date'],
                ],
            ],
            [
                'name' => 'Old gold purchase', 'prefix' => 'OGP', 'link_customer' => true,
                'fields' => [
                    ['label' => 'Item description', 'type' => 'text', 'required' => true],
                    ['label' => 'Gross weight (g)', 'type' => 'number', 'required' => true],
                    ['label' => 'Purity tested', 'type' => 'text'],
                    ['label' => 'Net weight (g)', 'type' => 'number'],
                    ['label' => 'Rate applied', 'type' => 'money'],
                    ['label' => 'Amount paid', 'type' => 'money', 'required' => true],
                ],
            ],
        ]],

    ['code' => 'bartan', 'name' => 'Bartan, crockery & utensils', 'features' => $f(['wholesale', 'packs', 'rates']),
        'custom_fields' => [['entity' => 'product', 'label' => 'Material', 'type' => 'select', 'options' => 'Steel, Aluminium, Brass, Copper, Glass, Melamine, Plastic']]],

    ['code' => 'electronics', 'name' => 'Electronics & mobile phones', 'features' => $f(['wholesale', 'serials']),
        'record_types' => [[
            'name' => 'Repair job', 'prefix' => 'JOB', 'link_customer' => true, 'statuses' => 'Received, Diagnosing, Waiting for parts, Repaired, Returned',
            'fields' => [
                ['label' => 'Device', 'type' => 'text', 'required' => true],
                ['label' => 'Serial / IMEI', 'type' => 'text'],
                ['label' => 'Problem reported', 'type' => 'longtext', 'required' => true],
                ['label' => 'Estimate', 'type' => 'money'],
                ['label' => 'Technician', 'type' => 'text'],
                ['label' => 'Due date', 'type' => 'date'],
            ],
        ]]],

    ['code' => 'hardware', 'name' => 'Hardware, sanitary & building material', 'features' => $f(['wholesale', 'packs', 'rates'])],

    ['code' => 'cosmetics', 'name' => 'Cosmetics & general store', 'features' => $f(['wholesale', 'variants', 'expiry'])],

    ['code' => 'wholesale', 'name' => 'Wholesale & distribution', 'features' => $f(['wholesale', 'packs', 'expiry']),
        'custom_fields' => [
            ['entity' => 'customer', 'label' => 'Route / area', 'type' => 'text'],
            ['entity' => 'customer', 'label' => 'Salesman', 'type' => 'text'],
        ]],

    ['code' => 'restaurant', 'name' => 'Restaurant, café & bakery', 'description' => 'Sells through the POS; reservations and kitchen notes as records. No table plan or kitchen display.',
        'features' => $f(['expiry']),
        'record_types' => [
            [
                'name' => 'Table reservation', 'prefix' => 'RES', 'link_customer' => true, 'statuses' => 'Booked, Seated, Completed, No show, Cancelled',
                'fields' => [
                    ['label' => 'Guest name', 'type' => 'text', 'required' => true],
                    ['label' => 'Phone', 'type' => 'text'],
                    ['label' => 'Date and time', 'type' => 'datetime', 'required' => true],
                    ['label' => 'Guests', 'type' => 'number', 'required' => true],
                    ['label' => 'Table', 'type' => 'text'],
                    ['label' => 'Special requests', 'type' => 'longtext'],
                ],
            ],
            [
                'name' => 'Catering order', 'prefix' => 'CAT', 'link_customer' => true, 'statuses' => 'Quoted, Confirmed, Prepared, Delivered',
                'fields' => [
                    ['label' => 'Event date', 'type' => 'datetime', 'required' => true],
                    ['label' => 'Menu', 'type' => 'longtext', 'required' => true],
                    ['label' => 'Persons', 'type' => 'number'],
                    ['label' => 'Amount', 'type' => 'money'],
                    ['label' => 'Advance received', 'type' => 'money'],
                ],
            ],
        ]],

    ['code' => 'services', 'name' => 'Services (salon, clinic, workshop, consultancy)', 'features' => $f([]),
        'record_types' => [
            [
                'name' => 'Appointment', 'prefix' => 'APT', 'link_customer' => true, 'statuses' => 'Booked, Arrived, Done, No show, Cancelled',
                'fields' => [
                    ['label' => 'Service', 'type' => 'text', 'required' => true],
                    ['label' => 'Date and time', 'type' => 'datetime', 'required' => true],
                    ['label' => 'Staff member', 'type' => 'text'],
                    ['label' => 'Notes', 'type' => 'longtext'],
                ],
            ],
            [
                'name' => 'Job card', 'prefix' => 'JOB', 'link_customer' => true, 'statuses' => 'Open, In progress, Waiting, Done, Delivered',
                'fields' => [
                    ['label' => 'Work required', 'type' => 'longtext', 'required' => true],
                    ['label' => 'Assigned to', 'type' => 'text'],
                    ['label' => 'Estimate', 'type' => 'money'],
                    ['label' => 'Due date', 'type' => 'date'],
                ],
            ],
        ]],

    ['code' => 'manufacturing', 'name' => 'Small manufacturing & workshop', 'description' => 'Production is logged as records; it does not consume materials or cost finished goods automatically.',
        'features' => $f(['wholesale', 'packs']),
        'record_types' => [[
            'name' => 'Production batch', 'prefix' => 'PRD', 'statuses' => 'Planned, In production, Finished',
            'fields' => [
                ['label' => 'Product made', 'type' => 'text', 'required' => true],
                ['label' => 'Quantity planned', 'type' => 'number', 'required' => true],
                ['label' => 'Quantity produced', 'type' => 'number'],
                ['label' => 'Materials used', 'type' => 'longtext'],
                ['label' => 'Wastage', 'type' => 'number'],
                ['label' => 'Production date', 'type' => 'date'],
            ],
        ]]],

    ['code' => 'rental', 'name' => 'Rental & hire', 'features' => $f(['serials']),
        'record_types' => [[
            'name' => 'Rental agreement', 'prefix' => 'RNT', 'link_customer' => true, 'statuses' => 'Reserved, Out, Returned, Overdue',
            'fields' => [
                ['label' => 'Item rented', 'type' => 'text', 'required' => true],
                ['label' => 'From', 'type' => 'datetime', 'required' => true],
                ['label' => 'Until', 'type' => 'datetime', 'required' => true],
                ['label' => 'Rent', 'type' => 'money'],
                ['label' => 'Deposit held', 'type' => 'money'],
                ['label' => 'Condition notes', 'type' => 'longtext'],
            ],
        ]]],
];
