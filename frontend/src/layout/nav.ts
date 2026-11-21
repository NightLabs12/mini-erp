export interface NavItem {
  key: string
  label: string
  to: string
  permission: string
}

export interface NavGroup {
  key: string
  label: string
  items: NavItem[]
}

function moduleItem(key: string, label: string, permission: string): NavItem {
  return { key, label, to: `/modules/${key}`, permission }
}

export const navigation: NavGroup[] = [
  {
    key: 'main',
    label: 'Utama',
    items: [
      { key: 'dashboard', label: 'Dashboard', to: '/dashboard', permission: 'core.dashboard.view' },
      { key: 'health', label: 'Status sistem', to: '/health', permission: 'core.dashboard.view' },
    ],
  },
  {
    key: 'master',
    label: 'Master data',
    items: [
      { key: 'units', label: 'Satuan', to: '/master/units', permission: 'master.unit.view' },
      {
        key: 'item-categories',
        label: 'Kategori item',
        to: '/master/item-categories',
        permission: 'master.item-category.view',
      },
      { key: 'items', label: 'Item', to: '/master/items', permission: 'master.item.view' },
      { key: 'warehouses', label: 'Gudang', to: '/master/warehouses', permission: 'master.warehouse.view' },
      { key: 'suppliers', label: 'Supplier', to: '/master/suppliers', permission: 'master.supplier.view' },
      { key: 'customers', label: 'Customer', to: '/master/customers', permission: 'master.customer.view' },
      {
        key: 'selling-prices',
        label: 'Harga jual',
        to: '/master/selling-prices',
        permission: 'master.selling-price.view',
      },
      { key: 'accounts', label: 'Chart of Accounts', to: '/master/accounts', permission: 'master.account.view' },
    ],
  },
  {
    key: 'purchase',
    label: 'Pembelian',
    items: [
      moduleItem('purchase-requests', 'Purchase Request', 'purchase.purchase-request.view'),
      { key: 'purchase-orders', label: 'Purchase Order', to: '/documents/purchase-orders', permission: 'purchase.purchase-order.view' },
      { key: 'goods-receipts', label: 'Goods Receipt', to: '/documents/goods-receipts', permission: 'purchase.goods-receipt.view' },
      { key: 'purchase-invoices', label: 'Purchase Invoice', to: '/documents/purchase-invoices', permission: 'purchase.purchase-invoice.view' },
      { key: 'supplier-payments', label: 'Payment Out', to: '/documents/supplier-payments', permission: 'purchase.supplier-payment.view' },
    ],
  },
  {
    key: 'sales',
    label: 'Penjualan',
    items: [
      { key: 'sales-orders', label: 'Sales Order', to: '/documents/sales-orders', permission: 'sales.sales-order.view' },
      { key: 'delivery-orders', label: 'Surat Jalan', to: '/documents/delivery-orders', permission: 'sales.delivery-order.view' },
      { key: 'sales-invoices', label: 'Sales Invoice', to: '/documents/sales-invoices', permission: 'sales.sales-invoice.view' },
      { key: 'customer-payments', label: 'Payment In', to: '/documents/customer-payments', permission: 'sales.customer-payment.view' },
    ],
  },
  {
    key: 'inventory',
    label: 'Persediaan',
    items: [
      moduleItem('stock-balances', 'Saldo stok', 'inventory.stock.view'),
      moduleItem('stock-ledger', 'Ledger stok', 'inventory.stock.view'),
      { key: 'warehouse-transfers', label: 'Transfer gudang', to: '/documents/warehouse-transfers', permission: 'inventory.warehouse-transfer.view' },
      { key: 'stock-opnames', label: 'Stock opname', to: '/documents/stock-opnames', permission: 'inventory.stock-opname.view' },
    ],
  },
  {
    key: 'accounting',
    label: 'Akuntansi',
    items: [
      { key: 'journals', label: 'Jurnal', to: '/documents/journals', permission: 'accounting.journal.view' },
      moduleItem('accounting-periods', 'Periode akuntansi', 'accounting.period.view'),
    ],
  },
  {
    key: 'report',
    label: 'Laporan',
    items: [
      moduleItem('general-ledger', 'Buku besar', 'report.general-ledger.view'),
      moduleItem('trial-balance', 'Neraca saldo', 'report.trial-balance.view'),
      { key: 'income-statement', label: 'Laba rugi', to: '/reports/income-statement', permission: 'report.income-statement.view' },
      moduleItem('balance-sheet', 'Neraca', 'report.balance-sheet.view'),
      moduleItem('cash-bank', 'Kas dan bank', 'report.cash-bank.view'),
      moduleItem('journal-report', 'Laporan jurnal', 'report.journal.view'),
      moduleItem('stock-card', 'Kartu stok', 'report.stock-card.view'),
      moduleItem('stock-position', 'Posisi stok', 'report.stock-position.view'),
      moduleItem('stock-movement', 'Mutasi stok', 'report.stock-movement.view'),
      moduleItem('ap-aging', 'AP aging', 'report.ap-aging.view'),
      moduleItem('ar-aging', 'AR aging', 'report.ar-aging.view'),
      moduleItem('ap-outstanding', 'AP outstanding', 'report.ap-outstanding.view'),
      moduleItem('ar-outstanding', 'AR outstanding', 'report.ar-outstanding.view'),
      moduleItem('sales-summary', 'Ringkasan penjualan', 'report.sales-summary.view'),
      moduleItem('purchase-summary', 'Ringkasan pembelian', 'report.purchase-summary.view'),
      moduleItem('so-outstanding', 'SO outstanding', 'report.so-outstanding.view'),
      moduleItem('po-outstanding', 'PO outstanding', 'report.po-outstanding.view'),
      moduleItem('reconciliation', 'Rekonsiliasi', 'report.reconciliation.view'),
    ],
  },
  {
    key: 'system',
    label: 'Sistem',
    items: [
      moduleItem('users', 'Pengguna', 'core.user.view'),
      moduleItem('audit-logs', 'Audit trail', 'core.audit.view'),
      moduleItem('settings', 'Pengaturan', 'core.setting.view'),
    ],
  },
]

const itemsByKey = new Map<string, NavItem>()

for (const group of navigation) {
  for (const item of group.items) {
    itemsByKey.set(item.key, item)
  }
}

export function findNavItem(key: string): NavItem | undefined {
  return itemsByKey.get(key)
}
