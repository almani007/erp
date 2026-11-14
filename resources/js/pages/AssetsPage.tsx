import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import DataGrid, { Column, FilterRow, Grouping, GroupPanel, Lookup, RequiredRule, SearchPanel, Summary, TotalItem } from 'devextreme-react/data-grid';
import DateBox from 'devextreme-react/date-box';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import TextBox from 'devextreme-react/text-box';
import CrudGrid from '../components/CrudGrid';
import Page, { Field } from '../components/Page';
import { statusCell } from '../components/Status';
import { api, getList, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { money, numberColumn, toDateString, today } from '../lib/format';
import { can, useSession } from '../lib/store';

interface Category { id: string; name: string; method: string; useful_life_months: number | null; rate_percent: string | null; residual_percent: string }
interface Asset {
    id: string; asset_no: string; name: string; category_id: string; category?: { name: string }; branch_id: string | null; location: string | null;
    serial_no: string | null; purchase_date: string; in_service_date: string; cost: string; residual_value: string; method: string;
    useful_life_months: number | null; rate_percent: string | null; accumulated_depreciation: string; book_value: string; depreciated_to: string | null;
    status: string; disposal_date: string | null; disposal_proceeds: string | null; disposal_gain_loss: string | null; notes: string | null;
    schedule?: { month: string; charge: string; accumulated: string; book_value: string }[];
}

const METHODS = [{ id: 'straight_line', name: 'Straight line' }, { id: 'reducing_balance', name: 'Reducing balance' }];
const monthOf = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;

/** Fixed asset register with monthly depreciation, disposal, and gain or loss — all posted to the books. */
export default function AssetsPage() {
    return (
        <Page title="Fixed assets" subtitle="Register, depreciation and disposal — posted to the general ledger.">
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="Register"><Register /></Item>
                {can('assets.view') && <Item title="Depreciation"><Depreciation /></Item>}
                {can('assets.manage') && <Item title="Categories"><Categories /></Item>}
            </TabPanel>
        </Page>
    );
}

function Register() {
    const client = useQueryClient();
    const branches = useSession((s) => s.branches);
    const assets = useQuery({ queryKey: ['fixed-assets'], queryFn: () => getList<Asset>('/fixed-assets') });
    const [adding, setAdding] = useState(false);
    const [card, setCard] = useState<Asset | null>(null);
    const refresh = () => client.invalidateQueries({ queryKey: ['fixed-assets'] });

    const open = async (id: string) => {
        try { setCard((await api.get(`/fixed-assets/${id}`)).data.data); } catch (e) { notifyError(e); }
    };

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                {can('assets.manage') && <Button text="Add asset" icon="add" type="default" onClick={() => setAdding(true)} />}
            </div>
            <DataGrid dataSource={assets.data ?? []} keyExpr="id" showRowLines hoverStateEnabled columnAutoWidth height="calc(100vh - 300px)"
                onRowClick={(e) => e.rowType === 'data' && open(e.data.id)}>
                <SearchPanel visible width={260} />
                <FilterRow visible />
                <GroupPanel visible />
                <Grouping autoExpandAll />
                <Column dataField="asset_no" caption="No." width={110} />
                <Column dataField="name" />
                <Column caption="Category" calculateCellValue={(a: Asset) => a.category?.name} />
                <Column dataField="branch_id" caption="Branch"><Lookup dataSource={branches} valueExpr="id" displayExpr="name" /></Column>
                <Column dataField="location" />
                <Column dataField="in_service_date" caption="In service" dataType="date" width={110} />
                <Column {...numberColumn('cost')} />
                <Column {...numberColumn('accumulated_depreciation')} caption="Depreciated" />
                <Column {...numberColumn('book_value')} caption="Book value" cssClass="erp-strong" />
                <Column dataField="depreciated_to" caption="Charged to" dataType="date" width={110} />
                <Column dataField="status" width={140} cellRender={statusCell} />
                <Summary>{['cost', 'accumulated_depreciation', 'book_value'].map((c) => <TotalItem key={c} column={c} summaryType="sum" valueFormat="#,##0.00" displayFormat="{0}" />)}</Summary>
            </DataGrid>
            {adding && <AddAsset onClose={() => setAdding(false)} onDone={() => { setAdding(false); refresh(); }} />}
            {card && <AssetCard asset={card} onClose={() => setCard(null)} onChanged={() => { setCard(null); refresh(); }} />}
        </div>
    );
}

function AddAsset({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
    const categories = useQuery({ queryKey: ['asset-categories'], queryFn: () => getList<Category>('/asset-categories') }).data ?? [];
    const suppliers = useQuery({ queryKey: ['suppliers'], queryFn: () => getList<{ id: string; name: string }>('/suppliers') }).data ?? [];
    const branches = useSession((s) => s.branches);
    const lastMonthEnd = (() => { const d = new Date(); return toDateString(new Date(d.getFullYear(), d.getMonth(), 0)); })();
    const [f, setF] = useState<Record<string, any>>({ purchase_date: today(), funding: 'bank', cost: 0, opening_depreciation: 0 });
    const [busy, setBusy] = useState(false);
    const [key] = useState(newIdempotencyKey);
    const set = (patch: Record<string, unknown>) => setF((x) => ({ ...x, ...patch }));
    const category = categories.find((c) => c.id === f.category_id);
    const method = f.method ?? category?.method;

    const save = async () => {
        setBusy(true);
        try {
            await api.post('/fixed-assets', { ...f, depreciated_to: f.funding === 'opening' ? (f.depreciated_to ?? lastMonthEnd) : null }, idempotent(key));
            notifySuccess('Asset registered and posted.');
            onDone();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title="Add fixed asset" width={860} height="auto" maxHeight="94vh" onHiding={onClose} showCloseButton>
            <div style={{ display: 'grid', gridTemplateColumns: '1.5fr 1fr 1fr', gap: 12 }}>
                <Field label="Name"><TextBox value={f.name ?? ''} onValueChanged={(e) => set({ name: e.value })} /></Field>
                <Field label="Category"><SelectBox dataSource={categories} valueExpr="id" displayExpr="name" value={f.category_id} onValueChanged={(e) => set({ category_id: e.value, method: null, useful_life_months: null, rate_percent: null })} /></Field>
                <Field label="Branch"><SelectBox dataSource={branches} valueExpr="id" displayExpr="name" value={f.branch_id ?? null} showClearButton onValueChanged={(e) => set({ branch_id: e.value })} /></Field>
                <Field label="Serial / registration no."><TextBox value={f.serial_no ?? ''} onValueChanged={(e) => set({ serial_no: e.value })} /></Field>
                <Field label="Location"><TextBox value={f.location ?? ''} onValueChanged={(e) => set({ location: e.value })} /></Field>
                <Field label="Purchase date"><DateBox type="date" value={f.purchase_date} max={new Date()} onValueChanged={(e) => e.value && set({ purchase_date: toDateString(new Date(e.value)) })} /></Field>
                <Field label="Cost"><NumberBox min={0} format="#,##0.00" value={f.cost} onValueChanged={(e) => set({ cost: e.value })} /></Field>
                <Field label={`Residual value (empty = ${category?.residual_percent ?? 0}% of cost)`}><NumberBox min={0} format="#,##0.00" value={f.residual_value ?? null} showClearButton onValueChanged={(e) => set({ residual_value: e.value })} /></Field>
                <Field label="Method"><SelectBox dataSource={METHODS} valueExpr="id" displayExpr="name" value={method} onValueChanged={(e) => set({ method: e.value })} /></Field>
                {method === 'reducing_balance'
                    ? <Field label="Rate per year %"><NumberBox min={0} max={100} value={f.rate_percent ?? Number(category?.rate_percent ?? 0)} onValueChanged={(e) => set({ rate_percent: e.value })} /></Field>
                    : <Field label="Useful life (months)"><NumberBox min={1} value={f.useful_life_months ?? category?.useful_life_months ?? null} onValueChanged={(e) => set({ useful_life_months: e.value })} /></Field>}
                <Field label="Paid / brought in as">
                    <SelectBox dataSource={[{ id: 'bank', name: 'Paid from bank' }, { id: 'cash', name: 'Paid in cash' }, { id: 'supplier', name: 'Owed to supplier' }, { id: 'opening', name: 'Existing asset (opening balance)' }]}
                        valueExpr="id" displayExpr="name" value={f.funding} onValueChanged={(e) => set({ funding: e.value })} />
                </Field>
                {f.funding === 'supplier' && <Field label="Supplier"><SelectBox dataSource={suppliers} valueExpr="id" displayExpr="name" searchEnabled value={f.supplier_id ?? null} onValueChanged={(e) => set({ supplier_id: e.value })} /></Field>}
                {f.funding === 'opening' && (
                    <>
                        <Field label="Depreciation already charged"><NumberBox min={0} format="#,##0.00" value={f.opening_depreciation} onValueChanged={(e) => set({ opening_depreciation: e.value })} /></Field>
                        <Field label="…charged up to"><DateBox type="date" value={f.depreciated_to ?? lastMonthEnd} onValueChanged={(e) => set({ depreciated_to: e.value ? toDateString(new Date(e.value)) : null })} /></Field>
                    </>
                )}
            </div>
            <Field label="Notes"><TextBox value={f.notes ?? ''} onValueChanged={(e) => set({ notes: e.value })} /></Field>
            <div className="erp-actions">
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Saving…' : 'Register asset'} type="default" disabled={busy || !f.name || !f.category_id || !(f.cost > 0)} onClick={save} />
            </div>
        </Popup>
    );
}

function AssetCard({ asset, onClose, onChanged }: { asset: Asset; onClose: () => void; onChanged: () => void }) {
    const [disposing, setDisposing] = useState(false);
    const [d, setD] = useState({ date: today(), proceeds: 0, received_by: 'bank', note: '' });
    const [key] = useState(newIdempotencyKey);
    const gain = d.proceeds - Number(asset.book_value);

    const dispose = async () => {
        try {
            await api.post(`/fixed-assets/${asset.id}/dispose`, d, idempotent(key));
            notifySuccess(`${asset.asset_no} disposed.`);
            onChanged();
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <Popup visible title={`${asset.asset_no} — ${asset.name}`} width="min(960px, 96vw)" height="88vh" onHiding={onClose} showCloseButton>
            <div style={{ display: 'flex', flexDirection: 'column', height: '100%', gap: 10 }}>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12 }}>
                    <div className="erp-kpi"><div className="erp-muted">Cost</div><b>{money(asset.cost)}</b></div>
                    <div className="erp-kpi"><div className="erp-muted">Depreciated</div><b>{money(asset.accumulated_depreciation)}</b></div>
                    <div className="erp-kpi"><div className="erp-muted">Book value</div><b>{money(asset.book_value)}</b></div>
                    <div className="erp-kpi"><div className="erp-muted">Method</div><b>{asset.method === 'reducing_balance' ? `${Number(asset.rate_percent)}% reducing` : `${asset.useful_life_months} months straight line`}</b></div>
                </div>
                <div className="erp-muted">
                    {asset.category?.name} · in service {asset.in_service_date} · residual {money(asset.residual_value)} · charged to {asset.depreciated_to ?? 'not yet'}
                    {asset.status === 'disposed' && <> · disposed {asset.disposal_date} for {money(asset.disposal_proceeds)} ({Number(asset.disposal_gain_loss) >= 0 ? 'gain' : 'loss'} {money(Math.abs(Number(asset.disposal_gain_loss)))})</>}
                </div>
                <div className="erp-muted">Depreciation still to come</div>
                <DataGrid dataSource={asset.schedule ?? []} keyExpr="month" showBorders height="100%" style={{ flex: 1 }}>
                    <Column dataField="month" width={110} />
                    <Column {...numberColumn('charge')} caption="Charge" />
                    <Column {...numberColumn('accumulated')} caption="Accumulated" />
                    <Column {...numberColumn('book_value')} caption="Book value" />
                </DataGrid>
                {disposing && (
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr 2fr', gap: 12, alignItems: 'end' }}>
                        <Field label="Disposal date"><DateBox type="date" value={d.date} max={new Date()} onValueChanged={(e) => e.value && setD({ ...d, date: toDateString(new Date(e.value)) })} /></Field>
                        <Field label="Sale proceeds (0 = written off)"><NumberBox min={0} format="#,##0.00" value={d.proceeds} onValueChanged={(e) => setD({ ...d, proceeds: e.value ?? 0 })} /></Field>
                        <Field label="Received by"><SelectBox dataSource={[{ id: 'bank', name: 'Bank' }, { id: 'cash', name: 'Cash' }]} valueExpr="id" displayExpr="name" value={d.received_by} onValueChanged={(e) => setD({ ...d, received_by: e.value })} /></Field>
                        <Field label={`Reason — ${gain >= 0 ? 'gain' : 'loss'} of ${money(Math.abs(gain))}`}><TextBox value={d.note} onValueChanged={(e) => setD({ ...d, note: e.value })} /></Field>
                    </div>
                )}
                <div className="erp-actions" style={{ marginTop: 0 }}>
                    <span className="erp-muted">Run depreciation up to the disposal month first, so the gain or loss is right.</span>
                    <div style={{ flex: 1 }} />
                    {asset.status !== 'disposed' && can('assets.dispose') && (disposing
                        ? <Button text="Confirm disposal" type="danger" onClick={dispose} />
                        : <Button text="Sell / write off" type="danger" stylingMode="outlined" onClick={() => setDisposing(true)} />)}
                    <Button text="Close" onClick={onClose} />
                </div>
            </div>
        </Popup>
    );
}

function Depreciation() {
    const client = useQueryClient();
    const [period, setPeriod] = useState<Date>(new Date());
    const [preview, setPreview] = useState<{ asset_no: string; name: string; months: number; amount: string }[]>([]);
    const runs = useQuery({ queryKey: ['depreciation-runs'], queryFn: () => getList('/depreciation-runs') });
    const [busy, setBusy] = useState(false);
    const [key, setKey] = useState(newIdempotencyKey);
    const total = preview.reduce((s, p) => s + Number(p.amount), 0);

    useEffect(() => {
        getList<any>('/depreciation-runs/preview', { period: monthOf(period) }).then(setPreview).catch(notifyError);
    }, [period]);

    const run = async () => {
        setBusy(true);
        try {
            await api.post('/depreciation-runs', { period: monthOf(period) }, idempotent(key));
            notifySuccess(`Depreciation for ${monthOf(period)} posted.`);
            setKey(newIdempotencyKey());
            setPreview([]);
            client.invalidateQueries({ queryKey: ['depreciation-runs'] });
            client.invalidateQueries({ queryKey: ['fixed-assets'] });
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    const reverse = async (id: string) => {
        const reason = window.prompt('Reason for reversing this depreciation run?');
        if (!reason) return;
        try {
            await api.post(`/depreciation-runs/${id}/reverse`, { reason });
            notifySuccess('Depreciation run reversed.');
            client.invalidateQueries({ queryKey: ['depreciation-runs'] });
            client.invalidateQueries({ queryKey: ['fixed-assets'] });
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <div style={{ display: 'grid', gridTemplateColumns: '1.3fr 1fr', gap: 16 }}>
            <div className="erp-card">
                <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start', alignItems: 'center' }}>
                    <DateBox type="date" value={period} displayFormat="MMMM yyyy" calendarOptions={{ maxZoomLevel: 'year', minZoomLevel: 'century' }} max={new Date()}
                        onValueChanged={(e) => e.value && setPeriod(new Date(e.value))} />
                    <span>Charge <b>{money(total)}</b> on {preview.length} assets</span>
                    <div style={{ flex: 1 }} />
                    {can('assets.depreciation.run') && <Button text={busy ? 'Posting…' : 'Post depreciation'} type="default" disabled={busy || preview.length === 0} onClick={run} />}
                </div>
                <DataGrid dataSource={preview} showRowLines columnAutoWidth height="calc(100vh - 330px)" noDataText="Nothing to charge for this month.">
                    <Column dataField="asset_no" width={110} />
                    <Column dataField="name" />
                    <Column dataField="months" caption="Months" width={90} />
                    <Column {...numberColumn('amount')} caption="Charge" />
                </DataGrid>
            </div>
            <div className="erp-card">
                <div style={{ fontWeight: 600, marginBottom: 8 }}>Posted runs</div>
                <DataGrid dataSource={runs.data ?? []} keyExpr="id" showRowLines columnAutoWidth>
                    <Column dataField="run_no" width={110} />
                    <Column dataField="period" width={90} />
                    <Column dataField="asset_count" caption="Assets" width={80} />
                    <Column {...numberColumn('total')} />
                    <Column dataField="status" width={100} cellRender={statusCell} />
                    <Column caption="" width={90} cellRender={({ data }: any) => data.status === 'posted' && can('assets.depreciation.run') && <Button text="Reverse" stylingMode="text" onClick={() => reverse(data.id)} />} />
                </DataGrid>
            </div>
        </div>
    );
}

function Categories() {
    return (
        <CrudGrid resource="/asset-categories" canAdd canUpdate height="calc(100vh - 260px)">
            <Column dataField="name"><RequiredRule /></Column>
            <Column dataField="method"><Lookup dataSource={METHODS} valueExpr="id" displayExpr="name" /><RequiredRule /></Column>
            <Column dataField="useful_life_months" caption="Life (months, straight line)" dataType="number" />
            <Column dataField="rate_percent" caption="Rate % a year (reducing)" dataType="number" />
            <Column dataField="residual_percent" caption="Residual %" dataType="number" />
            <Column dataField="status" width={120}><Lookup dataSource={['active', 'inactive']} /></Column>
        </CrudGrid>
    );
}
