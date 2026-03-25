<?php
/**
 * Navigation Configuration
 * Centralized navigation structure for the PC POS system
 */

if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

return [
    'dashboard' => [
        'label' => 'Dashboard',
        'href' => '/index.php',
        'icon' => 'bi-speedometer2',
        'active_pages' => ['index.php', 'dashboard.php'],
        'permission' => null, // Always visible when logged in
    ],

    'groups' => [
        'catalog' => [
            'label' => 'Catalog',
            'items' => [
                'products' => [
                    'label' => 'Products',
                    'icon' => 'bi-box',
                    'permission' => 'products.view',
                    'children' => [
                        ['label' => 'All Products', 'href' => '/products.php', 'icon' => 'bi-box-seam', 'active_pages' => ['products.php', 'product_list.php', 'product_view.php', 'product_form.php']],
                        ['label' => 'Add Product', 'href' => '/product_form.php?action=add', 'icon' => 'bi-plus-circle', 'active_pages' => ['product_form.php']],
                        ['label' => 'Categories', 'href' => '/categories.php', 'icon' => 'bi-tags', 'active_pages' => ['categories.php']],
                        ['label' => 'Suppliers', 'href' => '/suppliers.php', 'icon' => 'bi-truck', 'active_pages' => ['suppliers.php', 'supplier_form.php', 'supplier_profile.php', 'supplier_payments.php']],
                    ]
                ]
            ]
        ],

        'operations' => [
            'label' => 'Operations',
            'items' => [
                'inventory' => [
                    'label' => 'Inventory',
                    'icon' => 'bi-clipboard-data',
                    'permission' => 'inventory.view',
                    'children' => [
                        ['label' => 'Stock Overview', 'href' => '/inventory.php', 'icon' => 'bi-grid-1x2', 'active_pages' => ['inventory.php', 'stock_in.php', 'stock_adjustment.php']],
                        ['label' => 'Stock Movements', 'href' => '/stock_tracking.php', 'icon' => 'bi-arrow-left-right', 'active_pages' => ['stock_tracking.php']],
                    ]
                ],

                'purchasing' => [
                    'label' => 'Purchasing',
                    'icon' => 'bi-bag-check',
                    'permission' => 'purchase.view',
                    'children' => [
                        ['label' => 'Purchase Orders', 'href' => '/purchase_orders.php', 'icon' => 'bi-file-earmark-text', 'active_pages' => ['purchase_orders.php', 'po_form.php']],
                        ['label' => 'Vendors', 'href' => '/suppliers.php', 'icon' => 'bi-person-badge', 'active_pages' => ['suppliers.php', 'supplier_form.php', 'supplier_profile.php', 'supplier_payments.php']],
                    ]
                ]
            ]
        ],

        'sales' => [
            'label' => 'Sales',
            'items' => [
                'sales' => [
                    'label' => 'Sales',
                    'icon' => 'bi-cash-coin',
                    'permission' => 'sales.view',
                    'children' => [
                        ['label' => 'POS', 'href' => '/pos.php', 'icon' => 'bi-cart', 'active_pages' => ['pos.php', 'cart.php', 'checkout.php', 'payment.php', 'receipt.php', 'hold_transactions.php', 'returns.php']],
                        ['label' => 'Quotations', 'href' => '/quote_management.php', 'icon' => 'bi-file-earmark-text', 'active_pages' => ['quote_management.php', 'quote.php']],
                        ['label' => 'Transactions', 'href' => '/transactions.php', 'icon' => 'bi-receipt', 'active_pages' => ['transactions.php', 'transaction_history.php', 'invoice.php', 'void_transaction.php']],
                        ['label' => 'Customers', 'href' => '/customers.php', 'icon' => 'bi-people', 'active_pages' => ['customers.php', 'customer_form.php', 'customer_profile.php', 'customer_reports.php']],
                        ['label' => 'Warranty', 'href' => '/warranty.php', 'icon' => 'bi-shield-check', 'active_pages' => ['warranty.php']],
                        ['label' => 'Service & Repairs', 'href' => '/repairs.php', 'icon' => 'bi-tools', 'active_pages' => ['repairs.php']],
                    ]
                ]
            ]
        ],

        'analytics' => [
            'label' => 'Analytics',
            'items' => [
                'reports' => [
                    'label' => 'Reports',
                    'icon' => 'bi-graph-up',
                    'permission' => 'reports.view',
                    'children' => [
                        ['label' => 'Reports Hub', 'href' => '/reports.php', 'icon' => 'bi-grid', 'active_pages' => ['reports.php']],
                    ]
                ]
            ]
        ],

        'administration' => [
            'label' => 'Administration',
            'items' => [
                'administration' => [
                    'label' => 'Administration',
                    'icon' => 'bi-gear',
                    'permission' => ['users.view', 'users.create'],
                    'children' => [
                        ['label' => 'Users', 'href' => '/user_management.php', 'icon' => 'bi-people-fill', 'active_pages' => ['user_management.php']],
                        ['label' => 'Register User', 'href' => '/register.php', 'icon' => 'bi-person-plus', 'active_pages' => ['register.php']],
                        ['label' => 'Permissions', 'href' => '/permissions.php', 'icon' => 'bi-shield-check', 'active_pages' => ['permissions.php']],
                        ['label' => 'System Settings', 'href' => '/permissions.php?tab=settings', 'icon' => 'bi-sliders', 'active_pages' => ['settings.php']],
                    ]
                ]
            ]
        ]
    ]
];
