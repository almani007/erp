import { Navigate, Route, Routes } from 'react-router-dom';
import { useSession } from './lib/store';
import Layout from './components/Layout';
import LoginPage from './pages/LoginPage';
import DashboardPage from './pages/DashboardPage';
import ProductsPage from './pages/ProductsPage';
import MasterDataPage from './pages/MasterDataPage';
import StockPage from './pages/StockPage';
import StockAdjustmentsPage from './pages/StockAdjustmentsPage';
import StockTransfersPage from './pages/StockTransfersPage';
import PosPage from './pages/PosPage';
import SalesPage from './pages/SalesPage';
import CustomersPage from './pages/CustomersPage';
import AccountsPage from './pages/AccountsPage';
import JournalsPage from './pages/JournalsPage';
import ReportsPage from './pages/ReportsPage';
import OrganizationPage from './pages/OrganizationPage';
import UsersPage from './pages/UsersPage';
import AuditLogPage from './pages/AuditLogPage';
import PricingPage from './pages/PricingPage';
import PriceCheckerPage from './pages/PriceCheckerPage';
import PurchaseOrdersPage from './pages/PurchaseOrdersPage';
import SuppliersPage from './pages/SuppliersPage';
import StockCountsPage from './pages/StockCountsPage';
import ReorderPage from './pages/ReorderPage';
import AnalyticsPage from './pages/AnalyticsPage';

export default function App() {
    const token = useSession((s) => s.token);

    if (!token) {
        return (
            <Routes>
                <Route path="/login" element={<LoginPage />} />
                <Route path="*" element={<Navigate to="/login" replace />} />
            </Routes>
        );
    }

    return (
        <Routes>
            <Route path="/pos" element={<PosPage />} />
            <Route element={<Layout />}>
                <Route path="/" element={<DashboardPage />} />
                <Route path="/products" element={<ProductsPage />} />
                <Route path="/master-data" element={<MasterDataPage />} />
                <Route path="/stock" element={<StockPage />} />
                <Route path="/stock-adjustments" element={<StockAdjustmentsPage />} />
                <Route path="/stock-transfers" element={<StockTransfersPage />} />
                <Route path="/sales" element={<SalesPage />} />
                <Route path="/customers" element={<CustomersPage />} />
                <Route path="/accounts" element={<AccountsPage />} />
                <Route path="/journals" element={<JournalsPage />} />
                <Route path="/reports" element={<ReportsPage />} />
                <Route path="/organization" element={<OrganizationPage />} />
                <Route path="/users" element={<UsersPage />} />
                <Route path="/audit-log" element={<AuditLogPage />} />
                <Route path="/pricing" element={<PricingPage />} />
                <Route path="/price-checker" element={<PriceCheckerPage />} />
                <Route path="/purchase-orders" element={<PurchaseOrdersPage />} />
                <Route path="/suppliers" element={<SuppliersPage />} />
                <Route path="/stock-counts" element={<StockCountsPage />} />
                <Route path="/reorder" element={<ReorderPage />} />
                <Route path="/analytics" element={<AnalyticsPage />} />
            </Route>
            <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
    );
}
