import { useEffect, useMemo, useState } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import Button from 'devextreme-react/button';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TextBox from 'devextreme-react/text-box';
import { api, getList, notifyError, notifySuccess } from '../lib/api';
import { Features, ModuleName, useAppConfig } from '../lib/config';
import { Branch, useSession } from '../lib/store';

interface NavLink {
    text: string;
    icon: string;
    path: string;
    permission?: string;
    /** Only shown when this business feature is switched on. */
    feature?: keyof Features;
    /** Hidden when the tenant's plan does not include the module. */
    module?: ModuleName;
}

interface RecordTypeLink { key: string; plural: string; status: string }

const NAV: { section: string; links: NavLink[] }[] = [
    {
        section: 'Overview', links: [
            { text: 'Dashboard', icon: 'home', path: '/', permission: 'reporting.dashboard.view', module: 'reporting' },
            { text: 'Sales analysis', icon: 'datausage', path: '/analytics', permission: 'reporting.analytics.view', module: 'reporting' },
        ],
    },
    {
        section: 'Selling', links: [
            { text: 'Point of Sale', icon: 'cart', path: '/pos', permission: 'sales.sale.create', module: 'sales' },
            { text: 'Sales & returns', icon: 'money', path: '/sales', permission: 'sales.sale.view', module: 'sales' },
            { text: 'Customers', icon: 'group', path: '/customers', permission: 'sales.customer.view', module: 'sales' },
            { text: 'Price checker', icon: 'search', path: '/price-checker', permission: 'catalog.product.view', module: 'catalog' },
            { text: 'Serial & warranty', icon: 'tags', path: '/serials', permission: 'sales.sale.view', feature: 'serials', module: 'sales' },
        ],
    },
    {
        section: 'Catalog', links: [
            { text: 'Products', icon: 'product', path: '/products', permission: 'catalog.product.view', module: 'catalog' },
            { text: 'Pricing & deals', icon: 'percent', path: '/pricing', permission: 'catalog.product.view', module: 'catalog' },
            { text: 'Categories, units & tax', icon: 'hierarchy', path: '/master-data', permission: 'catalog.product.view', module: 'catalog' },
        ],
    },
    {
        section: 'Supply chain', links: [
            { text: 'Purchase orders', icon: 'orderedlist', path: '/purchase-orders', permission: 'purchasing.order.view', module: 'purchasing' },
            { text: 'Reorder suggestions', icon: 'bell', path: '/reorder', permission: 'purchasing.order.view', module: 'purchasing' },
            { text: 'Suppliers', icon: 'user', path: '/suppliers', permission: 'purchasing.supplier.view', module: 'purchasing' },
            { text: 'Stock on hand', icon: 'box', path: '/stock', permission: 'inventory.stock.view', module: 'inventory' },
            { text: 'Adjustments', icon: 'edit', path: '/stock-adjustments', permission: 'inventory.stock.view', module: 'inventory' },
            { text: 'Transfers', icon: 'movetofolder', path: '/stock-transfers', permission: 'inventory.stock.view', module: 'inventory' },
            { text: 'Stock counts', icon: 'checklist', path: '/stock-counts', permission: 'inventory.stock.view', module: 'inventory' },
            { text: 'Recipes & production', icon: 'toolbox', path: '/production', permission: 'production.view', module: 'production' },
            { text: 'Batches & expiry', icon: 'event', path: '/expiry', permission: 'inventory.stock.view', feature: 'expiry', module: 'inventory' },
        ],
    },
    {
        section: 'Finance', links: [
            { text: 'Chart of accounts', icon: 'bulletlist', path: '/accounts', permission: 'finance.account.view', module: 'finance' },
            { text: 'Journal entries', icon: 'textdocument', path: '/journals', permission: 'finance.journal.view', module: 'finance' },
            { text: 'Financial reports', icon: 'chart', path: '/reports', permission: 'finance.report.view', module: 'finance' },
            { text: 'HR & payroll', icon: 'group', path: '/people', permission: 'people.employee.view', module: 'people' },
        ],
    },
    {
        section: 'Administration', links: [
            { text: 'Business & branches', icon: 'map', path: '/organization' },
            { text: 'Custom records', icon: 'columnfield', path: '/record-types', permission: 'organization.settings.view', module: 'records' },
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
    const { features, moduleOn, config } = useAppConfig();
    const isPlatform = !!user?.is_platform_admin;
    const [recordTypes, setRecordTypes] = useState<RecordTypeLink[]>([]);

    useEffect(() => {
        if (isPlatform) return;
        getList<Branch>('/branches').then(setBranches).catch(notifyError);
    }, [setBranches, isPlatform]);

    // Each record type the business defined gets its own menu entry.
    useEffect(() => {
        if (isPlatform || !config || !moduleOn('records')) return;
        const load = () => getList<RecordTypeLink>('/record-types').then(setRecordTypes).catch(() => setRecordTypes([]));
        load();
        window.addEventListener('erp-record-types-changed', load);
        return () => window.removeEventListener('erp-record-types-changed', load);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isPlatform, !!config]);

    useEffect(() => {
        localStorage.setItem('erp-nav-collapsed', collapsed ? '1' : '0');
    }, [collapsed]);

    const sections = useMemo(() => {
        if (isPlatform) {
            return [{ section: 'Platform', links: [
                { text: 'Businesses (tenants)', icon: 'home', path: '/saas/tenants' },
                { text: 'Business templates', icon: 'copy', path: '/saas/templates' },
            ] }];
        }
        const allowed = (link: NavLink) => (!link.permission || permissions.includes('*') || permissions.includes(link.permission))
            && (!link.feature || features[link.feature]) && (!link.module || moduleOn(link.module));
        const records: NavLink[] = recordTypes.filter((t) => t.status === 'active')
            .map((t) => ({ text: t.plural, icon: 'doc', path: `/records/${t.key}`, permission: 'records.view', module: 'records' }));
        const nav = records.length > 0 ? [...NAV.slice(0, 2), { section: 'Records', links: records }, ...NAV.slice(2)] : NAV;
        return nav.map((s) => ({ ...s, links: s.links.filter(allowed) })).filter((s) => s.links.length > 0);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [permissions, JSON.stringify(features), JSON.stringify(config?.entitlements), recordTypes, isPlatform]);

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
                    {!isPlatform && <SelectBox
                        width={220}
                        dataSource={branchOptions}
                        valueExpr="id"
                        displayExpr="name"
                        value={activeBranchId ?? ALL_BRANCHES}
                        onValueChanged={(e) => setActiveBranch(e.value === ALL_BRANCHES ? null : e.value)}
                        stylingMode="outlined"
                        inputAttr={{ 'aria-label': 'Branch' }}
                    />}
                    <div className="erp-user" role="button" tabIndex={0} title="Change password" style={{ cursor: 'pointer' }}
                        onClick={() => setPasswordOpen(true)} onKeyDown={(e) => e.key === 'Enter' && setPasswordOpen(true)}>
                        <div className="erp-avatar">{initials}</div>
                        <div>
                            <div className="erp-user-name">{user?.name}</div>
                            <div className="erp-user-role">{isPlatform ? 'platform admin' : (roles[0] ?? 'user').replace(/_/g, ' ')}</div>
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
