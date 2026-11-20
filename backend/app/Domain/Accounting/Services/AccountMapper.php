<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Core\Models\AccountSetting;
use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\ItemCategory;
use App\Exceptions\ApiException;

/**
 * Satu-satunya tempat menerjemahkan key akun menjadi Account.
 *
 * Urutan resolusi:
 * 1. Override akun pada kategori item (inventory/cogs/sales), bila diisi.
 * 2. Default dari account_settings berdasarkan item_type.
 *
 * Kode akun tidak pernah ditulis di service dokumen, sehingga COA dapat
 * diubah tanpa mengubah kode.
 */
final class AccountMapper
{
    public const INVENTORY_TRADING = 'inventory_trading';

    public const INVENTORY_RAW = 'inventory_raw';

    public const INVENTORY_FINISHED = 'inventory_finished';

    public const GRIR = 'grir';

    public const ACCOUNTS_PAYABLE = 'accounts_payable';

    public const ACCOUNTS_RECEIVABLE = 'accounts_receivable';

    public const VAT_IN = 'vat_in';

    public const VAT_OUT = 'vat_out';

    public const SALES_REVENUE = 'sales_revenue';

    public const SALES_RETURN = 'sales_return';

    public const COGS = 'cogs';

    public const STOCK_ADJUSTMENT = 'stock_adjustment';

    public const PURCHASE_PRICE_VARIANCE = 'purchase_price_variance';

    public const CASH_DEFAULT = 'cash_default';

    public const BANK_DEFAULT = 'bank_default';

    public function accountFor(string $key): Account
    {
        $setting = AccountSetting::query()
            ->with('account')
            ->where('key', $key)
            ->first();

        if (! $setting instanceof AccountSetting || ! $setting->account instanceof Account) {
            throw ApiException::unprocessable(
                "Pemetaan akun '{$key}' belum diisi di account_settings.",
                'ACCOUNT_MAPPING_MISSING',
                ['account_settings' => ["Key {$key} belum punya akun."]],
            );
        }

        return $setting->account;
    }

    public function inventoryFor(Item $item): Account
    {
        $category = $this->category($item);

        return $this->overrideOrSetting(
            $category?->inventory_account_id,
            $this->inventoryKey($category?->item_type),
            'inventory_account_id',
        );
    }

    public function cogsFor(Item $item): Account
    {
        $category = $this->category($item);

        return $this->overrideOrSetting($category?->cogs_account_id, self::COGS, 'cogs_account_id');
    }

    public function salesFor(Item $item): Account
    {
        $category = $this->category($item);

        return $this->overrideOrSetting($category?->sales_account_id, self::SALES_REVENUE, 'sales_account_id');
    }

    private function inventoryKey(?string $itemType): string
    {
        return match ($itemType) {
            'raw_material' => self::INVENTORY_RAW,
            'finished_good' => self::INVENTORY_FINISHED,
            default => self::INVENTORY_TRADING,
        };
    }

    private function overrideOrSetting(?int $accountId, string $fallbackKey, string $field): Account
    {
        if ($accountId === null) {
            return $this->accountFor($fallbackKey);
        }

        $account = Account::query()->find($accountId);

        if (! $account instanceof Account) {
            throw ApiException::unprocessable(
                "Akun override {$field} pada kategori item tidak ditemukan.",
                'ACCOUNT_MAPPING_MISSING',
                [$field => ['Akun kategori tidak ditemukan.']],
            );
        }

        return $account;
    }

    private function category(Item $item): ?ItemCategory
    {
        $category = $item->relationLoaded('category')
            ? $item->getRelation('category')
            : $item->category()->first();

        return $category instanceof ItemCategory ? $category : null;
    }
}
