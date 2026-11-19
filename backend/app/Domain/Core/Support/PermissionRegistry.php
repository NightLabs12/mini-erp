<?php

declare(strict_types=1);

namespace App\Domain\Core\Support;

/**
 * Sumber tunggal daftar permission.
 *
 * Slug mengikuti pola {modul}.{resource}.{aksi} sesuai SPEC bagian 3.11.
 * Resource dan aksi di sini juga menjadi acuan route, policy, dan menu frontend.
 */
final class PermissionRegistry
{
    /** @var list<string> */
    public const CRUD = ['view', 'create', 'update', 'delete'];

    /** @var list<string> */
    public const DOCUMENT = ['view', 'create', 'update', 'delete', 'approve', 'disapprove', 'close', 'print'];

    /** @var list<string> */
    public const PAYMENT = ['view', 'create', 'update', 'delete', 'approve', 'print'];

    /** @var list<string> */
    public const REPORT_CODES = [
        'general-ledger',
        'trial-balance',
        'income-statement',
        'balance-sheet',
        'cash-bank',
        'journal',
        'stock-card',
        'stock-position',
        'stock-movement',
        'ap-aging',
        'ar-aging',
        'ap-outstanding',
        'ar-outstanding',
        'sales-summary',
        'purchase-summary',
        'so-outstanding',
        'po-outstanding',
        'reconciliation',
    ];

    /** @var list<string> */
    public const ROLES = ['admin', 'purchasing', 'sales', 'warehouse', 'finance', 'viewer'];

    /**
     * @return array<string, array<string, list<string>>>
     */
    public static function modules(): array
    {
        $reports = [];

        foreach (self::REPORT_CODES as $code) {
            $reports[$code] = ['view', 'export'];
        }

        return [
            'core' => [
                'user' => self::CRUD,
                'role' => ['view', 'update'],
                'audit' => ['view'],
                'setting' => ['view', 'update'],
            ],
            'master' => [
                'unit' => self::CRUD,
                'item-category' => self::CRUD,
                'item' => self::CRUD,
                'warehouse' => self::CRUD,
                'supplier' => self::CRUD,
                'customer' => self::CRUD,
                'selling-price' => self::CRUD,
                'account' => self::CRUD,
            ],
            'purchase' => [
                'purchase-request' => self::DOCUMENT,
                'purchase-order' => self::DOCUMENT,
                'goods-receipt' => self::DOCUMENT,
                'purchase-invoice' => self::DOCUMENT,
                'supplier-payment' => self::PAYMENT,
            ],
            'sales' => [
                'sales-order' => self::DOCUMENT,
                'delivery-order' => self::DOCUMENT,
                'sales-invoice' => self::DOCUMENT,
                'customer-payment' => self::PAYMENT,
            ],
            'inventory' => [
                'warehouse-transfer' => self::DOCUMENT,
                'stock-opname' => self::DOCUMENT,
                'stock' => ['view', 'export'],
            ],
            'accounting' => [
                'journal' => ['view', 'create', 'update', 'delete', 'print', 'export'],
                'period' => ['view', 'close', 'reopen'],
            ],
            'report' => $reports,
        ];
    }

    /**
     * Seluruh slug permission, terurut.
     *
     * @return list<string>
     */
    public static function permissions(): array
    {
        $slugs = [];

        foreach (self::modules() as $module => $resources) {
            foreach ($resources as $resource => $actions) {
                foreach ($actions as $action) {
                    $slugs[] = "{$module}.{$resource}.{$action}";
                }
            }
        }

        sort($slugs);

        return $slugs;
    }

    /**
     * Pola permission per role. Pola memakai wildcard fnmatch, misalnya
     * `purchase.*.*` atau `*.view`.
     *
     * @return array<string, list<string>>
     */
    public static function rolePatterns(): array
    {
        return [
            'admin' => ['*'],
            'purchasing' => [
                'master.*.view',
                'purchase.*.*',
                'inventory.stock.*',
                'report.purchase-summary.*',
                'report.po-outstanding.*',
                'report.ap-outstanding.*',
                'report.ap-aging.*',
                'report.stock-position.*',
                'report.stock-movement.*',
            ],
            'sales' => [
                'master.*.view',
                'sales.*.*',
                'inventory.stock.*',
                'report.sales-summary.*',
                'report.so-outstanding.*',
                'report.ar-outstanding.*',
                'report.ar-aging.*',
                'report.stock-position.*',
            ],
            'warehouse' => [
                'master.*.view',
                'inventory.*.*',
                'purchase.goods-receipt.view',
                'purchase.goods-receipt.approve',
                'purchase.goods-receipt.print',
                'report.stock-card.*',
                'report.stock-position.*',
                'report.stock-movement.*',
            ],
            'finance' => [
                'master.*.view',
                'accounting.*.*',
                'purchase.purchase-invoice.*',
                'purchase.supplier-payment.*',
                'sales.sales-invoice.*',
                'sales.customer-payment.*',
                'inventory.stock.*',
                'report.*.*',
            ],
            'viewer' => ['*.view'],
        ];
    }

    /**
     * Permission konkret untuk satu role.
     *
     * @return list<string>
     */
    public static function permissionsForRole(string $role): array
    {
        $patterns = self::rolePatterns()[$role] ?? [];
        $matched = [];

        foreach (self::permissions() as $permission) {
            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, $permission)) {
                    $matched[] = $permission;

                    break;
                }
            }
        }

        sort($matched);

        return array_values(array_unique($matched));
    }
}
