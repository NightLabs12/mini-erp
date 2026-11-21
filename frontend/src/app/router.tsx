import { Navigate, createBrowserRouter } from 'react-router-dom'
import { ProtectedLayout } from '../layout/ProtectedLayout'
import { ComingSoonPage } from '../pages/ComingSoonPage'
import { DashboardPage } from '../pages/DashboardPage'
import { HealthPage } from '../pages/HealthPage'
import { LoginPage } from '../pages/LoginPage'
import { NotFoundPage } from '../pages/NotFoundPage'
import { AccountsPage } from '../pages/master/AccountsPage'
import { DocumentFormRoute, DocumentListRoute } from '../pages/documents/DocumentRoute'
import { IncomeStatementPage } from '../pages/reports/IncomeStatementPage'
import { CustomersPage } from '../pages/master/CustomersPage'
import { ItemCategoriesPage } from '../pages/master/ItemCategoriesPage'
import { ItemsPage } from '../pages/master/ItemsPage'
import { SellingPricesPage } from '../pages/master/SellingPricesPage'
import { SuppliersPage } from '../pages/master/SuppliersPage'
import { UnitsPage } from '../pages/master/UnitsPage'
import { WarehousesPage } from '../pages/master/WarehousesPage'

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  {
    element: <ProtectedLayout />,
    children: [
      { path: '/', element: <Navigate to="/dashboard" replace /> },
      { path: '/dashboard', element: <DashboardPage /> },
      { path: '/health', element: <HealthPage /> },
      { path: '/master/units', element: <UnitsPage /> },
      { path: '/master/item-categories', element: <ItemCategoriesPage /> },
      { path: '/master/items', element: <ItemsPage /> },
      { path: '/master/warehouses', element: <WarehousesPage /> },
      { path: '/master/suppliers', element: <SuppliersPage /> },
      { path: '/master/customers', element: <CustomersPage /> },
      { path: '/master/selling-prices', element: <SellingPricesPage /> },
      { path: '/master/accounts', element: <AccountsPage /> },
      { path: '/documents/:docType', element: <DocumentListRoute /> },
      { path: '/documents/:docType/new', element: <DocumentFormRoute /> },
      { path: '/documents/:docType/:id', element: <DocumentFormRoute /> },
      { path: '/reports/income-statement', element: <IncomeStatementPage /> },
      { path: '/modules/:moduleKey', element: <ComingSoonPage /> },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
])
