import { useCallback, useEffect, useState } from 'react';
import Button from 'devextreme-react/button';
import CheckBox from 'devextreme-react/check-box';
import DataGrid, { Column, FilterRow, SearchPanel } from 'devextreme-react/data-grid';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TextBox from 'devextreme-react/text-box';
import Page, { Field } from '../../components/Page';
import { statusCell } from '../../components/Status';
import { api, getList, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../../lib/api';
import { FEATURE_LABELS, ModuleName } from '../../lib/config';

interface Entitlements { modules?: Partial<Record<ModuleName, boolean>>; features?: Record<string, boolean>; max_users?: number; max_branches?: number }
interface TenantRow {
    id: string; slug: string; legal_name: string; status: string; default_currency: string; business_template: string | null;
    entitlements: Entitlements | null; users: number; branches: number; sales_30d: number; created_at: string;
}
interface Template { code: string; name: string }

export const MODULES: { key: ModuleName; label: string }[] = [
    { key: 'sales', label: 'Sales & POS' },
    { key: 'catalog', label: 'Products & pricing' },
    { key: 'inventory', label: 'Inventory' },
    { key: 'purchasing', label: 'Purchasing' },
    { key: 'finance', label: 'Accounting' },
    { key: 'reporting', label: 'Dashboards & analysis' },
    { key: 'records', label: 'Custom records' },
    { key: 'production', label: 'Recipes & production' },
];

interface PlanState { modules: Record<string, boolean>; features: Record<string, boolean>; max_users: number | null; max_branches: number | null }

const planFrom = (e: Entitlements | null): PlanState => ({
    modules: Object.fromEntries(MODULES.map((m) => [m.key, e?.modules?.[m.key] !== false])),
    features: Object.fromEntries(FEATURE_LABELS.map((f) => [f.key, e?.features?.[f.key] !== false])),
    max_users: e?.max_users ?? null,
    max_branches: e?.max_branches ?? null,
});

/** Platform operator: every business on the system, its plan and status. */
export default function SaasTenantsPage() {
    const [rows, setRows] = useState<TenantRow[]>([]);
    const [templates, setTemplates] = useState<Template[]>([]);
    const [creating, setCreating] = useState(false);
    const [managing, setManaging] = useState<TenantRow | null>(null);

    const load = useCallback(() => getList<TenantRow>('/saas/tenants').then(setRows).catch(notifyError), []);
    useEffect(() => {
        load();
        getList<Template>('/saas/templates').then(setTemplates).catch(notifyError);
    }, [load]);

    return (
        <Page title="Businesses" subtitle="Every tenant on the platform: plan, limits and status."
            actions={<Button text="New business" icon="add" type="default" onClick={() => setCreating(true)} />}>
            <div className="erp-card">
                <DataGrid dataSource={rows} keyExpr="id" showBorders={false} showRowLines hoverStateEnabled columnAutoWidth
                    onRowClick={(e) => e.data.slug !== 'platform' && setManaging(e.data)}>
                    <SearchPanel visible width={240} />
                    <FilterRow visible />
                    <Column dataField="legal_name" caption="Business" />
                    <Column dataField="slug" />
                    <Column dataField="business_template" caption="Template" />
                    <Column dataField="users" dataType="number" width={80} />
                    <Column dataField="branches" dataType="number" width={90} />
                    <Column dataField="sales_30d" caption="Sales (30 days)" dataType="number" width={120} />
                    <Column caption="Plan" calculateCellValue={(r: TenantRow) => {
                        const off = MODULES.filter((m) => r.entitlements?.modules?.[m.key] === false).map((m) => m.label);
                        return off.length ? `Without ${off.join(', ')}` : 'Everything';
                    }} />
                    <Column dataField="status" width={110} cellRender={statusCell} />
                    <Column dataField="created_at" caption="Since" dataType="date" width={110} />
                </DataGrid>
            </div>
            {creating && <NewTenant templates={templates} onClose={() => setCreating(false)} onCreated={load} />}
            {managing && <ManageTenant tenant={managing} templates={templates} onClose={() => setManaging(null)} onSaved={(r) => { setRows(r); setManaging(null); }} />}
        </Page>
    );
}

function PlanEditor({ plan, onChange }: { plan: PlanState; onChange: (p: PlanState) => void }) {
    return (
        <>
            <div className="erp-nav-section" style={{ padding: '8px 0 4px' }}>Modules in the plan</div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 6 }}>
                {MODULES.map((m) => (
                    <CheckBox key={m.key} text={m.label} value={plan.modules[m.key]}
                        onValueChanged={(e) => onChange({ ...plan, modules: { ...plan.modules, [m.key]: e.value } })} />
                ))}
            </div>
            <div className="erp-nav-section" style={{ padding: '12px 0 4px' }}>Features allowed</div>
            <div style={{ display: 'grid', gap: 6 }}>
                {FEATURE_LABELS.map((f) => (
                    <CheckBox key={f.key} text={f.label} value={plan.features[f.key]}
                        onValueChanged={(e) => onChange({ ...plan, features: { ...plan.features, [f.key]: e.value } })} />
                ))}
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginTop: 12 }}>
                <Field label="Max users (empty = unlimited)">
                    <NumberBox value={plan.max_users ?? undefined} min={1} showClearButton onValueChanged={(e) => onChange({ ...plan, max_users: e.value || null })} />
                </Field>
                <Field label="Max branches (empty = unlimited)">
                    <NumberBox value={plan.max_branches ?? undefined} min={1} showClearButton onValueChanged={(e) => onChange({ ...plan, max_branches: e.value || null })} />
                </Field>
            </div>
        </>
    );
}

function NewTenant({ templates, onClose, onCreated }: { templates: Template[]; onClose: () => void; onCreated: () => void }) {
    const [form, setForm] = useState({
        legal_name: '', slug: '', currency: 'PKR', timezone: 'Asia/Karachi', template: null as string | null,
        owner_name: '', owner_email: '', owner_password: '',
    });
    const [plan, setPlan] = useState<PlanState>(planFrom(null));
    const [busy, setBusy] = useState(false);
    const [key] = useState(newIdempotencyKey);
    const set = (patch: Partial<typeof form>) => setForm((f) => ({ ...f, ...patch }));

    const save = async () => {
        setBusy(true);
        try {
            await api.post('/saas/tenants', { ...form, ...plan }, idempotent(key));
            notifySuccess(`${form.legal_name} is ready. The owner can sign in now.`);
            onCreated();
            onClose();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title="New business" width={720} height="auto" maxHeight="92vh" onHiding={onClose} showCloseButton>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                <Field label="Business name"><TextBox value={form.legal_name} valueChangeEvent="input"
                    onValueChanged={(e) => set({ legal_name: e.value, slug: form.slug || String(e.value).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') })} /></Field>
                <Field label="Short name (unique)"><TextBox value={form.slug} onValueChanged={(e) => set({ slug: e.value })} /></Field>
                <Field label="Kind of business">
                    <SelectBox dataSource={templates} valueExpr="code" displayExpr="name" value={form.template} showClearButton onValueChanged={(e) => set({ template: e.value })} />
                </Field>
                <div style={{ display: 'grid', gridTemplateColumns: '90px 1fr', gap: 12 }}>
                    <Field label="Currency"><TextBox value={form.currency} maxLength={3} onValueChanged={(e) => set({ currency: e.value })} /></Field>
                    <Field label="Time zone"><TextBox value={form.timezone} onValueChanged={(e) => set({ timezone: e.value })} /></Field>
                </div>
                <Field label="Owner name"><TextBox value={form.owner_name} onValueChanged={(e) => set({ owner_name: e.value })} /></Field>
                <Field label="Owner email (sign-in)"><TextBox value={form.owner_email} mode="email" onValueChanged={(e) => set({ owner_email: e.value })} /></Field>
                <Field label="Owner password (10+ characters)"><TextBox value={form.owner_password} mode="password" valueChangeEvent="input" onValueChanged={(e) => set({ owner_password: e.value })} /></Field>
            </div>
            <PlanEditor plan={plan} onChange={setPlan} />
            <div className="erp-actions">
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Creating…' : 'Create business'} type="default"
                    disabled={busy || !form.legal_name || !form.slug || !form.owner_email || form.owner_password.length < 10} onClick={save} />
            </div>
        </Popup>
    );
}

function ManageTenant({ tenant, templates, onClose, onSaved }: { tenant: TenantRow; templates: Template[]; onClose: () => void; onSaved: (rows: TenantRow[]) => void }) {
    const [plan, setPlan] = useState<PlanState>(planFrom(tenant.entitlements));
    const [template, setTemplate] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const run = async (fn: () => Promise<void>) => {
        setBusy(true);
        try { await fn(); } catch (e) { notifyError(e); } finally { setBusy(false); }
    };
    const save = (extra: Record<string, unknown> = {}) => run(async () => {
        const { data } = await api.put(`/saas/tenants/${tenant.id}`, { ...plan, ...extra });
        notifySuccess('Saved.');
        onSaved(data.data);
    });
    const apply = () => run(async () => {
        const { data } = await api.post(`/saas/tenants/${tenant.id}/apply-template`, { template });
        notifySuccess(`Added ${data.data.record_types_added} record types and ${data.data.custom_fields_added} fields.`);
    });

    const suspended = tenant.status === 'suspended';

    return (
        <Popup visible title={tenant.legal_name} width={680} height="auto" maxHeight="92vh" onHiding={onClose} showCloseButton>
            <PlanEditor plan={plan} onChange={setPlan} />
            <div className="erp-nav-section" style={{ padding: '12px 0 4px' }}>Apply a business template (adds fields and record types; removes nothing)</div>
            <div style={{ display: 'flex', gap: 8 }}>
                <SelectBox dataSource={templates} valueExpr="code" displayExpr="name" value={template} onValueChanged={(e) => setTemplate(e.value)} width={340} />
                <Button text="Apply" disabled={!template || busy} onClick={apply} />
            </div>
            <div className="erp-actions">
                <Button text={suspended ? 'Reactivate' : 'Suspend'} type={suspended ? 'success' : 'danger'} stylingMode="outlined" disabled={busy}
                    onClick={() => save({ status: suspended ? 'active' : 'suspended' })} />
                <div style={{ flex: 1 }} />
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Saving…' : 'Save plan'} type="default" disabled={busy} onClick={() => save()} />
            </div>
        </Popup>
    );
}
