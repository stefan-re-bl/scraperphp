<?php

return [
    'version' => 'commercial-v1',
    'dimension_weights' => ['fit' => 0.35, 'need' => 0.35, 'contact' => 0.15, 'evidence' => 0.15],
    'minimum_confidence' => 0.35,
    'minimum_coverage' => 0.25,
    'keyword_fit' => 70,
    'profiles' => [
        'booking' => [
            'fit' => ['booking_business' => 80, 'service_offered' => 30],
            'need' => ['manual_booking_dependency' => 100, 'manual_contact_dependency' => 50, 'operational_complexity' => 40, 'observed_problem' => 70],
            'known' => ['booking_business', 'booking_system_visible', 'manual_booking_dependency', 'channel_whatsapp'],
            'existing_solution' => 'booking_system_visible', 'existing_solution_need_cap' => 35,
        ],
        'web' => [
            'fit' => ['clear_offer' => 50, 'website_verified' => 30, 'service_offered' => 40],
            'need' => ['observed_problem' => 80, 'weak_cta' => 60, 'no_https' => 25, 'broken_contact_form' => 90],
            'known' => ['website_verified', 'website_functional', 'channel_form', 'observed_problem'],
        ],
        'ecommerce' => [
            'fit' => ['product_sales' => 80, 'products_visible' => 70, 'catalog_visible' => 60],
            'need' => ['manual_ordering' => 100, 'catalog_without_purchase' => 70, 'observed_problem' => 60],
            'known' => ['product_sales', 'catalog_visible', 'ecommerce_visible', 'online_purchase_visible'],
            'existing_solution' => 'online_purchase_visible', 'existing_solution_need_cap' => 35,
        ],
        'generic' => [
            'fit' => ['clear_offer' => 50], 'need' => ['observed_problem' => 60],
            'known' => ['clear_offer', 'observed_problem', 'website_verified'],
        ],
    ],
    'concepts' => ['products_visible' => 'product_sales', 'product_sales' => 'product_sales',
        'booking_visible' => 'booking_system_visible', 'booking_system_visible' => 'booking_system_visible',
        'ecommerce_visible' => 'online_purchase_visible', 'online_purchase_visible' => 'online_purchase_visible'],
    'ignored' => ['no_website', 'website_unreachable', 'hard_to_find_contact', 'no_visible_booking', 'phone_format_invalid'],
];
