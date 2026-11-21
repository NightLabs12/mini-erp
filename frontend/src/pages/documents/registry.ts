import type { ComponentType } from 'react'
import {
  GoodsReceiptFormPage,
  GoodsReceiptsListPage,
  PurchaseInvoiceFormPage,
  PurchaseInvoicesListPage,
  PurchaseOrderFormPage,
  PurchaseOrdersListPage,
  SupplierPaymentFormPage,
  SupplierPaymentsListPage,
} from './purchasing'
import {
  CustomerPaymentFormPage,
  CustomerPaymentsListPage,
  DeliveryOrderFormPage,
  DeliveryOrdersListPage,
  SalesInvoiceFormPage,
  SalesInvoicesListPage,
  SalesOrderFormPage,
  SalesOrdersListPage,
} from './sales'
import {
  StockOpnameFormPage,
  StockOpnamesListPage,
  WarehouseTransferFormPage,
  WarehouseTransfersListPage,
} from './inventory'
import { JournalFormPage, JournalsListPage } from './journal'

export interface DocumentRouteEntry {
  list: ComponentType
  form: ComponentType
}

export const documentRoutes: Record<string, DocumentRouteEntry> = {
  'purchase-orders': { list: PurchaseOrdersListPage, form: PurchaseOrderFormPage },
  'goods-receipts': { list: GoodsReceiptsListPage, form: GoodsReceiptFormPage },
  'purchase-invoices': { list: PurchaseInvoicesListPage, form: PurchaseInvoiceFormPage },
  'supplier-payments': { list: SupplierPaymentsListPage, form: SupplierPaymentFormPage },
  'sales-orders': { list: SalesOrdersListPage, form: SalesOrderFormPage },
  'delivery-orders': { list: DeliveryOrdersListPage, form: DeliveryOrderFormPage },
  'sales-invoices': { list: SalesInvoicesListPage, form: SalesInvoiceFormPage },
  'customer-payments': { list: CustomerPaymentsListPage, form: CustomerPaymentFormPage },
  'warehouse-transfers': { list: WarehouseTransfersListPage, form: WarehouseTransferFormPage },
  'stock-opnames': { list: StockOpnamesListPage, form: StockOpnameFormPage },
  journals: { list: JournalsListPage, form: JournalFormPage },
}
