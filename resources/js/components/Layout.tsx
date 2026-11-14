import { useEffect, useMemo, useState } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import Button from 'devextreme-react/button';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TextBox from 'devextreme-react/text-box';
import { api, getList, notifyError, notifySuccess } from '../lib/api';
import { Branch, useSession } from '../lib/store';

interface NavLink {
    text: string;
    icon: string;
    path: string;
    permission?: string;
}

const NAV: { section: string; links: NavLink[] }[] = [
    {
        section: 'Overview', links: [
            { text: 'Dashboard', icon: 'home', path: '/', permission: 'reporting.dashboard.view' },
            { text: 'Sales analysis', icon: 'datausage', path: '/analytics', permission: 'reporting.analytics.view' },
        ],
    },
    {
        section: 'Selling', links: [
            { text: 'Point of Sale', icon: 'cart', path: '/pos', permission: 'sales.sale.create' },
            { text: 'Sales & returns', icon: 'money', path: '/sales', permission: 'sales.sale.view' },
            { text: 'Customers', icon: 'group', path: '/customers', permission: 'sales.customer.view' },
            { text: 'Price checker', icon: 'search', path: '/price-checker', permission: 'catalog.product.view' },
        ],
    },
    {
        section: 'Catalog', links: [
            { text: 'Products', icon: 'product', path: '/products', permission: 'catalog.product.view' },
            { text: 'Pricing & deals', icon: 'percent', path: '/pricing', permission: 'catalog.product.view' },
            { text: 'Categories, units & tax', icon: 'hierarchy', path: '/master-data', permission: 'catalog.product.view' },
        ],
    },
    {
        section: 'Supply chain', links: [
            { text: 'Purchase orders', icon: 'orderedlist', path: '/purchase-orders', permission: 'purchasing.order.view' },
            { text: 'Reorder suggestions', icon: 'bell', path: '/reorder', permission: 'purchasing.order.view' },
            { text: 'Suppliers', icon: 'user', path: '/suppliers', permission: 'purchasing.supplier.view' },
            { text: 'Stock on hand', icon: 'box', path: '/stock', permission: 'inventory.stock.view' },
            { text: 'Adjustments', icon: 'edit', path: '/stock-adjustments', permission: 'inventory.stock.view' },
            { text: 'Transfers', icon: 'movetofolder', path: '/stock-transfers', permission: 'inventory.stock.view' },
            { text: 'Stock counts', icon: 'checklist', path: '/stock-counts', permission: 'inventory.stock.view' },
        ],
    },
    {
        section: 'Finance', links: [
            { text: 'Chart of accounts', icon: 'bulletlist', path: '/accounts', permission: 'finance.account.view' },
            { text: 'Journal entries', icon: 'textdocument', path: '/journals', permission: 'finance.journal.view' },
            { text: 'Financial reports', icon: 'chart', path: '/reports', permission: 'finance.report.view' },
        ],
    },
    {
        section: 'Administration', links: [
            { text: 'Business & branches', icon: 'map', path: '/organization' },
            { text: 'Users & roles', icon: 'key', path: '/users', permission: 'identity.user.view' },
            { text: 'Audit log', icon: 'clock', path: '/audit-log', permission: 'identity.audit.view' },
        ],
    },
];

const ALL_BRANCHES = '__all__';

export default function Layout() {
    const navigate = useNavigate();
    const { pathname } = useLocation();
    const { user, roles, permissions, branches, activeBranchId, setBranches, setActiveBranch, signOut } = useSession();
    const [collapsed, setCollapsed] = useState(() => localStorage.getItem('erp-nav-collapsed') === '1');
    const [passwordOpen, setPasswordOpen] = useState(false);

    useEffect(() => {
        getList<Branch>('/branches').then(setBranches).catch(notifyError);
    }, [setBranches]);

    useEffect(() => {
        localStorage.setItem('erp-nav-collapsed', collapsed ? '1' : '0');
    }, [collapsed]);

    const sections = useMemo(() => {
        const allowed = (link: NavLink) => !link.permission || permissions.includes('*') || permissions.includes(link.permission);
        return NAV.map((s) => ({ ...s, links: s.links.filter(allowed) })).filter((s) => s.links.length > 0);
    }, [permissions]);

    const logout = async () => {
        try {
            await api.post('/auth/logout');
        } catch {
            // The token is discarded locally either way.
        }
        signOut();
    };

    const initials = (user?.name ?? '?').split(' ').map((part) => part[0]).slice(0, 2).join('').toUpperCase();
    const branchOptions = [{ id: ALL_BRANCHES, name: 'All branches' }, ...branches];

    return (
        <div className={`erp-shell${collapsed ? ' collapsed' : ''}`}>
            <nav className="erp-nav" aria-label="Main">
                <div className="erp-nav-brand">
                    <div className="erp-logo">B</div>
                    {!collapsed && <span className="erp-nav-brand-name">Brixel ERP</span>}
                </div>
                <div className="erp-nav-scroll">
                    {sections.map((section) => (
                        <div key={section.section}>
                            <div className="erp-nav-section">{section.section}</div>
                            {section.links.map((link) => (
                                <button
                                    key={link.path}
                                    type="button"
                                    className={`erp-nav-link${pathname === link.path ? ' active' : ''}`}
                                    title={collapsed ? link.text : undefined}
                                    aria-current={pathname === link.path ? 'page' : undefined}
                                    onClick={() => navigate(link.path)}
                                >
                                    <i className={`dx-icon dx-icon-${link.icon}`} aria-hidden="true" />
                                    <span>{link.text}</span>
                                </button>
                            ))}
                        </div>
                    ))}
                </div>
            </nav>
            <div className="erp-main">
                <header className="erp-topbar">
                    <Button icon="menu" stylingMode="text" hint={collapsed ? 'Expand menu' : 'Collapse menu'} onClick={() => setCollapsed((v) => !v)} />
                    <div className="erp-topbar-spacer" />
                    <SelectBox
                        width={220}
                        dataSource={branchOptions}
                        valueExpr="id"
                        displayExpr="name"
                        value={activeBranchId ?? ALL_BRANCHES}
                        onValueChanged={(e) => setActiveBranch(e.value === ALL_BRANCHES ? null : e.value)}
                        stylingMode="outlined"
                        inputAttr={{ 'aria-label': 'Branch' }}
                    />
                    <div className="erp-user" role="button" tabIndex={0} title="Change password" style={{ cursor: 'pointer' }}
                        onClick={() => setPasswordOpen(true)} onKeyDown={(e) => e.key === 'Enter' && setPasswordOpen(true)}>
                        <div className="erp-avatar">{initials}</div>
                        <div>
                            <div className="erp-user-name">{user?.name}</div>
                            <div className="erp-user-role">{(roles[0] ?? 'user').replace(/_/g, ' ')}</div>
                        </div>
                    </div>
                    <Button icon="runner" stylingMode="text" hint="Sign out" onClick={logout} />
                </header>
                {/* Remount pages when the branch changes so every grid reloads in the new scope. */}
                <main className="erp-content" key={activeBranchId ?? ALL_BRANCHES}>
                    <Outlet />
                </main>
                {passwordOpen && <ChangePassword onClose={() => setPasswordOpen(false)} />}
            </div>
        </div>
    );
}

function ChangePassword({ onClose }: { onClose: () => void }) {
    const [current, setCurrent] = useState('');
    const [next, setNext] = useState('');
    const [busy, setBusy] = useState(false);

    const save = async () => {
        setBusy(true);
        try {
            await api.post('/auth/password', { current_password: current, password: next });
            notifySuccess('Password changed. Other devices have been signed out.');
            onClose();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title="Change password" width={420} height="auto" onHiding={onClose} showCloseButton>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                <TextBox label="Current password" mode="password" value={current} valueChangeEvent="input" onValueChanged={(e) => setCurrent(e.value)} />
                <TextBox label="New password (at least 10 characters)" mode="password" value={next} valueChangeEvent="input" onValueChanged={(e) => setNext(e.value)} />
            </div>
            <div className="erp-actions">
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Saving…' : 'Change password'} type="default" disabled={busy || !current || next.length < 10} onClick={save} />
            </div>
        </Popup>
    );
}
