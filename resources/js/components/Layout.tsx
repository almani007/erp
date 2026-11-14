import { useEffect, useMemo, useState } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import Drawer from 'devextreme-react/drawer';
import Toolbar, { Item } from 'devextreme-react/toolbar';
import TreeView from 'devextreme-react/tree-view';
import SelectBox from 'devextreme-react/select-box';
import { api, getList, notifyError } from '../lib/api';
import { Branch, useSession } from '../lib/store';

interface NavItem {
    id: string;
    text: string;
    icon?: string;
    path?: string;
    permission?: string;
    items?: NavItem[];
}

const NAV: NavItem[] = [
    { id: 'dashboard', text: 'Dashboard', icon: 'home', path: '/', permission: 'reporting.dashboard.view' },
    { id: 'pos', text: 'Point of Sale', icon: 'cart', path: '/pos', permission: 'sales.sale.create' },
    {
        id: 'sales', text: 'Sales', icon: 'money', items: [
            { id: 'sales-history', text: 'Sales history', path: '/sales', permission: 'sales.sale.view' },
            { id: 'customers', text: 'Customers', path: '/customers', permission: 'sales.customer.view' },
        ],
    },
    {
        id: 'catalog', text: 'Catalog', icon: 'product', items: [
            { id: 'products', text: 'Products', path: '/products', permission: 'catalog.product.view' },
            { id: 'master-data', text: 'Categories, units & tax', path: '/master-data', permission: 'catalog.product.view' },
        ],
    },
    {
        id: 'inventory', text: 'Inventory', icon: 'box', items: [
            { id: 'stock', text: 'Stock on hand', path: '/stock', permission: 'inventory.stock.view' },
            { id: 'adjustments', text: 'Adjustments', path: '/stock-adjustments', permission: 'inventory.stock.view' },
            { id: 'transfers', text: 'Transfers', path: '/stock-transfers', permission: 'inventory.stock.view' },
        ],
    },
    {
        id: 'finance', text: 'Finance', icon: 'chart', items: [
            { id: 'accounts', text: 'Chart of accounts', path: '/accounts', permission: 'finance.account.view' },
            { id: 'journals', text: 'Journal entries', path: '/journals', permission: 'finance.journal.view' },
            { id: 'reports', text: 'Financial reports', path: '/reports', permission: 'finance.report.view' },
        ],
    },
    {
        id: 'admin', text: 'Administration', icon: 'preferences', items: [
            { id: 'organization', text: 'Branches & warehouses', path: '/organization' },
            { id: 'users', text: 'Users & roles', path: '/users', permission: 'identity.user.view' },
            { id: 'audit', text: 'Audit log', path: '/audit-log', permission: 'identity.audit.view' },
        ],
    },
];

const ALL_BRANCHES = '__all__';

export default function Layout() {
    const navigate = useNavigate();
    const location = useLocation();
    const { user, permissions, branches, activeBranchId, setBranches, setActiveBranch, signOut } = useSession();
    const [open, setOpen] = useState(true);

    useEffect(() => {
        getList<Branch>('/branches').then(setBranches).catch(notifyError);
    }, [setBranches]);

    const items = useMemo(() => {
        const allowed = (item: NavItem) => !item.permission || permissions.includes('*') || permissions.includes(item.permission);
        const mark = (item: NavItem) => ({ ...item, selected: item.path === location.pathname });
        return NAV
            .map((item) => (item.items ? { ...item, expanded: true, items: item.items.filter(allowed).map(mark) } : mark(item)))
            .filter((item) => (item.items ? item.items.length > 0 : allowed(item)));
    }, [permissions, location.pathname]);

    const logout = async () => {
        try {
            await api.post('/auth/logout');
        } catch {
            // The token is discarded locally either way.
        }
        signOut();
    };

    const branchOptions = [{ id: ALL_BRANCHES, name: 'All branches' }, ...branches];

    return (
        <div className="erp-shell">
            <div className="erp-topbar">
                <Toolbar>
                    <Item location="before" widget="dxButton" options={{ icon: 'menu', stylingMode: 'text', onClick: () => setOpen((v) => !v) }} />
                    <Item location="before" render={() => <span className="erp-brand">Brixel ERP</span>} />
                    <Item
                        location="after"
                        render={() => (
                            <SelectBox
                                width={220}
                                dataSource={branchOptions}
                                valueExpr="id"
                                displayExpr="name"
                                value={activeBranchId ?? ALL_BRANCHES}
                                onValueChanged={(e) => setActiveBranch(e.value === ALL_BRANCHES ? null : e.value)}
                                stylingMode="outlined"
                            />
                        )}
                    />
                    <Item location="after" render={() => <span className="erp-muted">{user?.name}</span>} />
                    <Item location="after" widget="dxButton" options={{ icon: 'runner', text: 'Sign out', stylingMode: 'text', onClick: logout }} />
                </Toolbar>
            </div>
            <div className="erp-body">
                <Drawer
                    opened={open}
                    openedStateMode="shrink"
                    position="left"
                    revealMode="slide"
                    height="100%"
                    render={() => (
                        <div className="erp-nav">
                            <TreeView
                                items={items}
                                keyExpr="id"
                                selectionMode="single"
                                selectByClick
                                expandEvent="click"
                                onItemClick={(e) => {
                                    const path = (e.itemData as NavItem | undefined)?.path;
                                    if (path) {
                                        navigate(path);
                                    }
                                }}
                            />
                        </div>
                    )}
                >
                    {/* Remount pages when the branch changes so every grid reloads in the new scope. */}
                    <div className="erp-content" key={activeBranchId ?? ALL_BRANCHES}>
                        <Outlet />
                    </div>
                </Drawer>
            </div>
        </div>
    );
}
