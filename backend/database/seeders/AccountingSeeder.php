<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Core\Models\AccountSetting;
use App\Domain\Master\Models\ItemCategory;
use Illuminate\Database\Seeder;

/**
 * COA default, pemetaan account_settings, dan 12 periode tahun berjalan.
 *
 * Idempoten: akun dan setting diperbarui berdasarkan kode/key, sedangkan
 * periode memakai firstOrCreate agar status closed tidak terbuka kembali saat
 * seeder dijalankan ulang.
 */
class AccountingSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, Account> $accounts */
        $accounts = [];

        foreach ($this->accounts() as $definition) {
            $accounts[$definition['code']] = Account::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'type' => $definition['type'],
                    'normal_balance' => $definition['normal_balance'],
                    'is_postable' => $definition['is_postable'] ?? true,
                    'is_active' => true,
                ],
            );
        }

        foreach ($this->accounts() as $definition) {
            $parentCode = $definition['parent'] ?? null;
            $parent = $parentCode === null ? null : $accounts[$parentCode];

            $accounts[$definition['code']]->update([
                'parent_id' => $parent?->getKey(),
                'level' => $parent === null ? 1 : $parent->level + 1,
            ]);
        }

        $this->seedAccountSettings($accounts);
        $this->seedCategoryAccounts($accounts);
        $this->seedPeriods();
    }

    /**
     * @param  array<string, Account>  $accounts
     */
    private function seedAccountSettings(array $accounts): void
    {
        $settings = [
            'inventory_trading' => ['1-1400', 'Persediaan barang dagang.'],
            'inventory_raw' => ['1-1410', 'Persediaan bahan baku.'],
            'inventory_finished' => ['1-1420', 'Persediaan barang jadi.'],
            'grir' => ['2-1200', 'Barang diterima belum ditagih.'],
            'accounts_payable' => ['2-1100', 'Hutang usaha.'],
            'accounts_receivable' => ['1-1300', 'Piutang usaha.'],
            'vat_in' => ['1-1500', 'PPN masukan.'],
            'vat_out' => ['2-1300', 'PPN keluaran.'],
            'sales_revenue' => ['4-1000', 'Pendapatan penjualan.'],
            'sales_return' => ['4-2000', 'Retur penjualan.'],
            'cogs' => ['5-1000', 'Harga pokok penjualan.'],
            'stock_adjustment' => ['5-2000', 'Selisih persediaan.'],
            'purchase_price_variance' => ['5-3000', 'Selisih harga pembelian.'],
            'cash_default' => ['1-1100', 'Kas default.'],
            'bank_default' => ['1-1200', 'Bank default.'],
        ];

        foreach ($settings as $key => [$code, $description]) {
            AccountSetting::query()->updateOrCreate(
                ['key' => $key],
                ['account_id' => $accounts[$code]->getKey(), 'description' => $description],
            );
        }
    }

    /**
     * @param  array<string, Account>  $accounts
     */
    private function seedCategoryAccounts(array $accounts): void
    {
        $defaults = [
            'trading' => ['1-1400', '5-1000', '4-1000'],
            'raw_material' => ['1-1410', '5-1000', '4-1000'],
            'finished_good' => ['1-1420', '5-1000', '4-1000'],
        ];

        foreach ($defaults as $itemType => [$inventory, $cogs, $sales]) {
            foreach ([
                'inventory_account_id' => $inventory,
                'cogs_account_id' => $cogs,
                'sales_account_id' => $sales,
            ] as $column => $code) {
                ItemCategory::query()
                    ->where('item_type', $itemType)
                    ->whereNull($column)
                    ->update([$column => $accounts[$code]->getKey()]);
            }
        }
    }

    private function seedPeriods(): void
    {
        $year = (int) now()->year;

        for ($month = 1; $month <= 12; $month++) {
            AccountingPeriod::query()->firstOrCreate(
                ['year' => $year, 'month' => $month],
                ['status' => AccountingPeriod::STATUS_OPEN],
            );
        }
    }

    /**
     * @return list<array{
     *     code: string,
     *     name: string,
     *     type: string,
     *     normal_balance: string,
     *     parent?: string,
     *     is_postable?: bool
     * }>
     */
    private function accounts(): array
    {
        return [
            ['code' => '1-0000', 'name' => 'Aset', 'type' => 'asset', 'normal_balance' => 'D', 'is_postable' => false],
            ['code' => '1-1000', 'name' => 'Aset Lancar', 'type' => 'asset', 'normal_balance' => 'D', 'parent' => '1-0000', 'is_postable' => false],
            ['code' => '1-2000', 'name' => 'Aset Tetap', 'type' => 'asset', 'normal_balance' => 'D', 'parent' => '1-0000', 'is_postable' => false],
            ['code' => '1-1100', 'name' => 'Kas', 'type' => 'asset', 'normal_balance' => 'D', 'parent' => '1-1000'],
            ['code' => '1-1200', 'name' => 'Bank', 'type' => 'asset', 'normal_balance' => 'D', 'parent' => '1-1000'],
            ['code' => '1-1300', 'name' => 'Piutang Usaha', 'type' => 'asset', 'normal_balance' => 'D', 'parent' => '1-1000'],
            ['code' => '1-1400', 'name' => 'Persediaan Barang Dagang', 'type' => 'asset', 'normal_balance' => 'D', 'parent' => '1-1000'],
            ['code' => '1-1410', 'name' => 'Persediaan Bahan Baku', 'type' => 'asset', 'normal_balance' => 'D', 'parent' => '1-1000'],
            ['code' => '1-1420', 'name' => 'Persediaan Barang Jadi', 'type' => 'asset', 'normal_balance' => 'D', 'parent' => '1-1000'],
            ['code' => '1-1500', 'name' => 'PPN Masukan', 'type' => 'asset', 'normal_balance' => 'D', 'parent' => '1-1000'],
            ['code' => '1-1600', 'name' => 'Perlengkapan', 'type' => 'asset', 'normal_balance' => 'D', 'parent' => '1-1000'],
            ['code' => '1-1700', 'name' => 'Beban Dibayar Dimuka', 'type' => 'asset', 'normal_balance' => 'D', 'parent' => '1-1000'],
            ['code' => '1-1800', 'name' => 'Piutang Lain-lain', 'type' => 'asset', 'normal_balance' => 'D', 'parent' => '1-1000'],
            ['code' => '1-2100', 'name' => 'Peralatan', 'type' => 'asset', 'normal_balance' => 'D', 'parent' => '1-2000'],
            ['code' => '1-2200', 'name' => 'Akumulasi Penyusutan Peralatan', 'type' => 'asset', 'normal_balance' => 'C', 'parent' => '1-2000'],

            ['code' => '2-0000', 'name' => 'Kewajiban', 'type' => 'liability', 'normal_balance' => 'C', 'is_postable' => false],
            ['code' => '2-1000', 'name' => 'Kewajiban Lancar', 'type' => 'liability', 'normal_balance' => 'C', 'parent' => '2-0000', 'is_postable' => false],
            ['code' => '2-2000', 'name' => 'Kewajiban Jangka Panjang', 'type' => 'liability', 'normal_balance' => 'C', 'parent' => '2-0000', 'is_postable' => false],
            ['code' => '2-1100', 'name' => 'Hutang Usaha', 'type' => 'liability', 'normal_balance' => 'C', 'parent' => '2-1000'],
            ['code' => '2-1200', 'name' => 'GR/IR (Barang Diterima Belum Ditagih)', 'type' => 'liability', 'normal_balance' => 'C', 'parent' => '2-1000'],
            ['code' => '2-1300', 'name' => 'PPN Keluaran', 'type' => 'liability', 'normal_balance' => 'C', 'parent' => '2-1000'],
            ['code' => '2-1400', 'name' => 'Hutang Gaji', 'type' => 'liability', 'normal_balance' => 'C', 'parent' => '2-1000'],
            ['code' => '2-1500', 'name' => 'Hutang Pajak', 'type' => 'liability', 'normal_balance' => 'C', 'parent' => '2-1000'],
            ['code' => '2-1600', 'name' => 'Biaya Masih Harus Dibayar', 'type' => 'liability', 'normal_balance' => 'C', 'parent' => '2-1000'],
            ['code' => '2-2100', 'name' => 'Hutang Bank Jangka Panjang', 'type' => 'liability', 'normal_balance' => 'C', 'parent' => '2-2000'],

            ['code' => '3-0000', 'name' => 'Ekuitas', 'type' => 'equity', 'normal_balance' => 'C', 'is_postable' => false],
            ['code' => '3-1000', 'name' => 'Modal Saham', 'type' => 'equity', 'normal_balance' => 'C', 'parent' => '3-0000'],
            ['code' => '3-2000', 'name' => 'Laba Ditahan', 'type' => 'equity', 'normal_balance' => 'C', 'parent' => '3-0000'],
            ['code' => '3-3000', 'name' => 'Laba Tahun Berjalan', 'type' => 'equity', 'normal_balance' => 'C', 'parent' => '3-0000', 'is_postable' => false],
            ['code' => '3-4000', 'name' => 'Prive/Dividen', 'type' => 'equity', 'normal_balance' => 'D', 'parent' => '3-0000'],

            ['code' => '4-0000', 'name' => 'Pendapatan', 'type' => 'revenue', 'normal_balance' => 'C', 'is_postable' => false],
            ['code' => '4-1000', 'name' => 'Penjualan', 'type' => 'revenue', 'normal_balance' => 'C', 'parent' => '4-0000'],
            ['code' => '4-1100', 'name' => 'Diskon Penjualan', 'type' => 'revenue', 'normal_balance' => 'D', 'parent' => '4-0000'],
            ['code' => '4-2000', 'name' => 'Retur Penjualan', 'type' => 'revenue', 'normal_balance' => 'D', 'parent' => '4-0000'],
            ['code' => '4-3000', 'name' => 'Pendapatan Lain-lain', 'type' => 'revenue', 'normal_balance' => 'C', 'parent' => '4-0000'],

            ['code' => '5-0000', 'name' => 'Harga Pokok dan Beban Pokok', 'type' => 'cogs', 'normal_balance' => 'D', 'is_postable' => false],
            ['code' => '5-1000', 'name' => 'HPP', 'type' => 'cogs', 'normal_balance' => 'D', 'parent' => '5-0000'],
            ['code' => '5-2000', 'name' => 'Selisih Persediaan', 'type' => 'cogs', 'normal_balance' => 'D', 'parent' => '5-0000'],
            ['code' => '5-3000', 'name' => 'Selisih Harga Pembelian', 'type' => 'cogs', 'normal_balance' => 'D', 'parent' => '5-0000'],
            ['code' => '5-4000', 'name' => 'Beban Angkut Pembelian', 'type' => 'cogs', 'normal_balance' => 'D', 'parent' => '5-0000'],

            ['code' => '6-0000', 'name' => 'Beban Operasional', 'type' => 'expense', 'normal_balance' => 'D', 'is_postable' => false],
            ['code' => '6-1000', 'name' => 'Beban Gaji', 'type' => 'expense', 'normal_balance' => 'D', 'parent' => '6-0000'],
            ['code' => '6-2000', 'name' => 'Beban Sewa', 'type' => 'expense', 'normal_balance' => 'D', 'parent' => '6-0000'],
            ['code' => '6-3000', 'name' => 'Beban Operasional Lain', 'type' => 'expense', 'normal_balance' => 'D', 'parent' => '6-0000'],
            ['code' => '6-4000', 'name' => 'Beban Listrik dan Air', 'type' => 'expense', 'normal_balance' => 'D', 'parent' => '6-0000'],
            ['code' => '6-5000', 'name' => 'Beban Penyusutan', 'type' => 'expense', 'normal_balance' => 'D', 'parent' => '6-0000'],
            ['code' => '6-6000', 'name' => 'Beban Pemasaran', 'type' => 'expense', 'normal_balance' => 'D', 'parent' => '6-0000'],
            ['code' => '6-7000', 'name' => 'Beban Administrasi', 'type' => 'expense', 'normal_balance' => 'D', 'parent' => '6-0000'],
            ['code' => '6-8000', 'name' => 'Beban Bunga', 'type' => 'expense', 'normal_balance' => 'D', 'parent' => '6-0000'],
            ['code' => '6-9000', 'name' => 'Beban Pajak', 'type' => 'expense', 'normal_balance' => 'D', 'parent' => '6-0000'],
        ];
    }
}
