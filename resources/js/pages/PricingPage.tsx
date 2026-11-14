import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import CheckBox from 'devextreme-react/check-box';
import DateBox from 'devextreme-react/date-box';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import TagBox from 'devextreme-react/tag-box';
import TextBox from 'devextreme-react/text-box';
import { Column, Lookup, RequiredRule } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import { statusCell } from '../components/Status';
import Page, { Field } from '../components/Page';
import { api, getList, notifyError, notifySuccess } from '../lib/api';
import { numberColumn } from '../lib/format';
import { useAppConfig } from '../lib/config';
import { useCan, useCompanyId } from '../lib/store';

const GRID_HEIGHT = 'calc(100vh - 290px)';
const TYPES = [{ id: 'percentage', name: 'Percent off' }, { id: 'fixed', name: 'Amount off per unit' }];

export default function PricingPage() {
    const canManage = useCan('catalog.pricing.manage');
    const companyId = useCompanyId();
    const branches = useQuery({ queryKey: ['branches'], queryFn: () => getList('/branches') });
    const products = useQuery({ queryKey: ['products'], queryFn: () => getList('/products', { limit: 1000 }) });
    const categories = useQuery({ queryKey: ['categories'], queryFn: () => getList('/categories') });
    const promotions = useQuery({ queryKey: ['promotions', companyId], queryFn: () => getList('/promotions', { company_id: companyId }), enabled: !!companyId });
    const [editing, setEditing] = useState<any | null>(null);
    const [version, setVersion] = useState(0);
    const { config, features, refetch } = useAppConfig();
    const canSettings = useCan('organization.settings.manage');

    if (!companyId) {
        return <Page title="Pricing & deals"><div className="erp-card">No company is available for your account.</div></Page>;
    }

    const scope = (p: any) => {
        const parts = [];
        if (p.product_ids?.length) parts.push(`${p.product_ids.length} products`);
        if (p.category_ids?.length) parts.push(`${p.category_ids.length} categories`);
        return parts.length ? parts.join(', ') : 'All products';
    };

    return (
        <Page
            title="Pricing & deals"
            subtitle="A line sells at its branch price (or default price), less the single best deal that applies"
            actions={canManage && <Button text="New deal" icon="plus" type="default" onClick={() => setEditing({})} />}
        >
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="Deals">
                    <CrudGrid key={version} resource="/promotions" params={{ company_id: companyId }} height={GRID_HEIGHT} onRowClick={canManage ? setEditing : undefined}>
                        <Column dataField="name" />
                        <Column dataField="type" width={170}><Lookup dataSource={TYPES} valueExpr="id" displayExpr="name" /></Column>
                        <Column {...numberColumn('value')} width={110} />
                        <Column caption="Applies to" calculateCellValue={scope} />
                        <Column dataField="branch_id" caption="Branch"><Lookup dataSource={branches.data ?? []} valueExpr="id" displayExpr="name" /></Column>
                        <Column dataField="requires_coupon" caption="Coupon only" dataType="boolean" width={120} />
                        <Column dataField="start_at" caption="From" dataType="date" width={120} />
                        <Column dataField="end_at" caption="Until" dataType="date" width={120} />
                        <Column dataField="status" width={110} cellRender={statusCell} />
                    </CrudGrid>
                </Item>
                <Item title="Coupons">
                    <CrudGrid resource="/coupons" params={{ company_id: companyId }} createDefaults={{ company_id: companyId }} canAdd={canManage} canUpdate={canManage} height={GRID_HEIGHT}>
                        <Column dataField="code" width={160}><RequiredRule /></Column>
                        <Column dataField="promotion_rule_id" caption="Unlocks deal">
                            <Lookup dataSource={promotions.data ?? []} valueExpr="id" displayExpr="name" />
                            <RequiredRule />
                        </Column>
                        <Column dataField="max_uses" caption="Max uses" dataType="number" width={120} />
                        <Column dataField="used_count" caption="Used" dataType="number" width={100} allowEditing={false} />
                        <Column dataField="valid_from" dataType="date" width={140}><RequiredRule /></Column>
                        <Column dataField="valid_to" dataType="date" width={140} />
                        <Column dataField="status" width={130}><Lookup dataSource={['active', 'inactive']} /></Column>
                    </CrudGrid>
                </Item>
                <Item title="Branch prices">
                    <div className="erp-muted" style={{ marginBottom: 10 }}>Overrides a product's default sale price in one branch. Delete the row to go back to the default.</div>
                    <CrudGrid resource="/branch-prices" canAdd={canManage} canUpdate={canManage} canDelete={canManage} height={GRID_HEIGHT}>
                        <Column dataField="branch_id" caption="Branch">
                            <Lookup dataSource={branches.data ?? []} valueExpr="id" displayExpr="name" />
                            <RequiredRule />
                        </Column>
                        <Column dataField="product_id" caption="Product">
                            <Lookup dataSource={products.data ?? []} valueExpr="id" displayExpr={(p: any) => (p ? `${p.sku} — ${p.name}` : '')} />
                            <RequiredRule />
                        </Column>
                        <Column {...numberColumn('price')} caption="Branch price"><RequiredRule /></Column>
                    </CrudGrid>
                </Item>
                {features.rates && (
                    <Item title="Market rates">
                        <div className="erp-muted" style={{ marginBottom: 10 }}>
                            Today's rate for goods priced by weight — gold, silver, steel. Changing a rate re-prices every product linked to it
                            immediately, and the change is recorded in the audit log.
                        </div>
                        <CrudGrid resource="/market-rates" canAdd={canManage} canUpdate={canManage} height={GRID_HEIGHT}>
                            <Column dataField="code" width={160}><RequiredRule /></Column>
                            <Column dataField="name"><RequiredRule /></Column>
                            <Column dataField="per_unit" caption="Per" width={120}><Lookup dataSource={['gram', 'tola', 'kg', 'piece']} /><RequiredRule /></Column>
                            <Column {...numberColumn('rate')} caption="Rate today"><RequiredRule /></Column>
                            <Column dataField="updated_at" caption="Last changed" dataType="datetime" width={190} allowEditing={false} />
                        </CrudGrid>
                    </Item>
                )}
                <Item title="Loyalty points">
                    <LoyaltySettings config={config?.loyalty} canEdit={canSettings} onSaved={refetch} />
                </Item>
            </TabPanel>
            {editing && (
                <DealForm
                    deal={editing} companyId={companyId} branches={branches.data ?? []} products={products.data ?? []} categories={categories.data ?? []}
                    onClose={(saved) => { setEditing(null); if (saved) { setVersion((v) => v + 1); promotions.refetch(); } }}
                />
            )}
        </Page>
    );
}

function DealForm({ deal, companyId, branches, products, categories, onClose }: {
    deal: any; companyId: string; branches: any[]; products: any[]; categories: any[]; onClose: (saved: boolean) => void;
}) {
    const isNew = !deal.id;
    const [form, setForm] = useState({
        name: deal.name ?? '',
        type: deal.type ?? 'percentage',
        value: Number(deal.value ?? 10),
        start_at: deal.start_at ?? new Date().toISOString(),
        end_at: deal.end_at ?? null,
        branch_id: deal.branch_id ?? null,
        requires_coupon: deal.requires_coupon ?? false,
        product_ids: deal.product_ids ?? [],
        category_ids: deal.category_ids ?? [],
        status: deal.status ?? 'active',
    });
    const [busy, setBusy] = useState(false);
    const set = (patch: Partial<typeof form>) => setForm((f) => ({ ...f, ...patch }));

    const save = async () => {
        setBusy(true);
        try {
            if (isNew) {
                const { status, ...body } = form;
                await api.post('/promotions', { ...body, company_id: companyId });
            } else {
                const { type, ...body } = form; // the kind of deal is fixed once created
                await api.put(`/promotions/${deal.id}`, body);
            }
            notifySuccess(isNew ? 'Deal created' : 'Deal updated');
            onClose(true);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title={isNew ? 'New deal' : `Edit ${deal.name}`} width={640} height="auto" onHiding={() => onClose(false)} showCloseButton>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                <TextBox label="Name (shown to the cashier and on the receipt)" value={form.name} valueChangeEvent="input" onValueChanged={(e) => set({ name: e.value })} />
                <div className="erp-row" style={{ marginBottom: 0 }}>
                    <SelectBox label="Kind" width={220} dataSource={TYPES} valueExpr="id" displayExpr="name" value={form.type} readOnly={!isNew} onValueChanged={(e) => set({ type: e.value })} />
                    <NumberBox label={form.type === 'percentage' ? 'Percent' : 'Amount per unit'} width={160} value={form.value} min={0} max={form.type === 'percentage' ? 100 : undefined} onValueChanged={(e) => set({ value: e.value ?? 0 })} />
                    <SelectBox label="Branch" width={200} dataSource={branches} valueExpr="id" displayExpr="name" value={form.branch_id} showClearButton placeholder="All branches" onValueChanged={(e) => set({ branch_id: e.value ?? null })} />
                </div>
                <div className="erp-row" style={{ marginBottom: 0 }}>
                    <Field label="Starts"><DateBox type="datetime" width={220} value={form.start_at} onValueChanged={(e) => set({ start_at: e.value })} /></Field>
                    <Field label="Ends (optional)"><DateBox type="datetime" width={220} value={form.end_at} showClearButton onValueChanged={(e) => set({ end_at: e.value ?? null })} /></Field>
                </div>
                <TagBox label="Products (leave both lists empty for all products)" dataSource={products} valueExpr="id" displayExpr="name" searchEnabled value={form.product_ids} onValueChanged={(e) => set({ product_ids: e.value })} />
                <TagBox label="Categories" dataSource={categories} valueExpr="id" displayExpr="name" value={form.category_ids} onValueChanged={(e) => set({ category_ids: e.value })} />
                <CheckBox text="Only applies when a coupon code for this deal is entered" value={form.requires_coupon} onValueChanged={(e) => set({ requires_coupon: !!e.value })} />
                {!isNew && <SelectBox label="Status" width={200} dataSource={['active', 'inactive']} value={form.status} onValueChanged={(e) => set({ status: e.value })} />}
            </div>
            <div className="erp-actions">
                <Button text="Cancel" onClick={() => onClose(false)} />
                <Button text={busy ? 'Saving…' : 'Save deal'} type="default" disabled={busy || !form.name.trim() || form.value <= 0} onClick={save} />
            </div>
        </Popup>
    );
}

function LoyaltySettings({ config, canEdit, onSaved }: { config?: { earn_per_amount: string; point_value: string }; canEdit: boolean; onSaved: () => void }) {
    const [earn, setEarn] = useState<number | null>(null);
    const [value, setValue] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const earnNow = earn ?? Number(config?.earn_per_amount ?? 0);
    const valueNow = value ?? Number(config?.point_value ?? 0);

    const save = async () => {
        setBusy(true);
        try {
            await api.put('/settings', { group: 'loyalty', key: 'earn_per_amount', value: String(earnNow) });
            await api.put('/settings', { group: 'loyalty', key: 'point_value', value: String(valueNow) });
            notifySuccess('Loyalty settings saved');
            onSaved();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="erp-card" style={{ maxWidth: 620 }}>
            <div className="erp-card-title">How customers earn and spend points</div>
            <div className="erp-row">
                <NumberBox label="Amount spent per 1 point (0 = off)" width={260} value={earnNow} min={0} readOnly={!canEdit} onValueChanged={(e) => setEarn(e.value ?? 0)} />
                <NumberBox label="Value of 1 point when redeemed" width={240} value={valueNow} min={0} readOnly={!canEdit} onValueChanged={(e) => setValue(e.value ?? 0)} />
            </div>
            <div className="erp-muted">
                {earnNow > 0 && valueNow > 0
                    ? `A customer spending 10,000 earns ${Math.floor(10000 / earnNow)} points, worth ${(Math.floor(10000 / earnNow) * valueNow).toFixed(2)} off a later sale.`
                    : 'Loyalty is switched off until both values are above zero.'}
                {' '}Points are earned on sales to a named customer and redeemed at the till as a payment method.
            </div>
            {canEdit && <div className="erp-actions"><Button text={busy ? 'Saving…' : 'Save'} type="default" disabled={busy} onClick={save} /></div>}
        </div>
    );
}
