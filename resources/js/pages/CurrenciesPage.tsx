import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import DataGrid, { Column, Summary, TotalItem } from 'devextreme-react/data-grid';
import DateBox from 'devextreme-react/date-box';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import TextBox from 'devextreme-react/text-box';
import Page, { Field } from '../components/Page';
import { api, getList, getOne, notifyError, notifySuccess } from '../lib/api';
import { money, numberColumn, toDateString, today } from '../lib/format';
import { can } from '../lib/store';

/** Foreign currencies, daily exchange rates, what is owed abroad, and month-end revaluation. */
export default function CurrenciesPage() {
    const overview = useQuery({ queryKey: ['currencies'], queryFn: () => getOne<any>('/currencies') });
    const base = overview.data?.base_currency ?? '';

    return (
        <Page title="Currencies & exchange" subtitle={`Books are kept in ${base}. Suppliers can bill and be paid in other currencies; gains and losses are booked automatically.`}>
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="Currencies & rates"><Rates base={base} currencies={overview.data?.currencies ?? []} onChanged={() => overview.refetch()} /></Item>
                <Item title="Foreign payables & revaluation"><Exposure base={base} /></Item>
            </TabPanel>
        </Page>
    );
}

function Rates({ base, currencies, onChanged }: { base: string; currencies: any[]; onChanged: () => void }) {
    const client = useQueryClient();
    const [currency, setCurrency] = useState<string | null>(null);
    const history = useQuery({ queryKey: ['exchange-rates', currency], queryFn: () => getList<any>('/exchange-rates', currency ? { currency } : {}) });
    const [editing, setEditing] = useState<any | null>(null);
    const [entry, setEntry] = useState<Record<string, number | null>>({});
    const [date, setDate] = useState(today());
    const manage = can('finance.currency.manage');

    const saveCurrency = async () => {
        try {
            if (editing.id) await api.put(`/currencies/${editing.id}`, editing); else await api.post('/currencies', editing);
            setEditing(null);
            onChanged();
        } catch (e) { notifyError(e); }
    };
    const saveRates = async () => {
        const rates = Object.entries(entry).filter(([, v]) => Number(v) > 0).map(([c, rate]) => ({ currency: c, rate_date: date, rate }));
        if (rates.length === 0) return;
        try {
            await api.post('/exchange-rates', { rates });
            notifySuccess(`${rates.length} rate(s) saved for ${date}.`);
            setEntry({});
            onChanged();
            client.invalidateQueries({ queryKey: ['exchange-rates'] });
        } catch (e) { notifyError(e); }
    };

    return (
        <>
            <div className="erp-card">
                <div className="erp-card-title">Today's rates — {base} for one unit</div>
                <DataGrid dataSource={currencies} keyExpr="id" showRowLines columnAutoWidth noDataText="No foreign currencies yet. Add the ones you buy or sell in.">
                    <Column dataField="code" width={80} />
                    <Column dataField="name" />
                    <Column dataField="symbol" width={80} />
                    <Column dataField="rate_today" caption={`Rate (${base})`} dataType="number" format="#,##0.0000" width={140} />
                    <Column dataField="rate_date" caption="Rate from" dataType="date" width={120} />
                    {manage && <Column caption={`New rate for ${date}`} width={170} cellRender={({ data }) => (
                        <NumberBox value={entry[data.code] ?? null} min={0} format="#,##0.0000" placeholder={data.rate_today ?? ''} onValueChanged={(e) => setEntry((x) => ({ ...x, [data.code]: e.value }))} />
                    )} />}
                    <Column dataField="status" width={90} />
                    {manage && <Column caption="" width={70} cellRender={({ data }) => <Button text="Edit" stylingMode="text" onClick={() => setEditing({ ...data })} />} />}
                </DataGrid>
                {manage && (
                    <div className="erp-row" style={{ marginTop: 12 }}>
                        <Button text="Add currency" icon="add" onClick={() => setEditing({ status: 'active' })} />
                        <DateBox type="date" width={150} value={date} max={new Date()} onValueChanged={(e) => setDate(toDateString(new Date(e.value)))} />
                        <Button text="Save rates" type="default" icon="save" disabled={!Object.values(entry).some((v) => Number(v) > 0)} onClick={saveRates} />
                    </div>
                )}
            </div>
            <div className="erp-card">
                <div className="erp-row">
                    <div className="erp-card-title">Rate history</div>
                    <SelectBox width={160} dataSource={currencies} valueExpr="code" displayExpr="code" placeholder="All currencies" showClearButton value={currency} onValueChanged={(e) => setCurrency(e.value)} />
                </div>
                <DataGrid dataSource={history.data ?? []} keyExpr="id" showRowLines columnAutoWidth height={320}>
                    <Column dataField="rate_date" caption="Date" dataType="date" width={120} />
                    <Column dataField="currency" width={90} />
                    <Column dataField="rate" dataType="number" format="#,##0.0000" />
                    <Column dataField="source" />
                </DataGrid>
            </div>
            {editing && (
                <Popup visible title={editing.id ? 'Edit currency' : 'Add currency'} width={420} height="auto" onHiding={() => setEditing(null)} showCloseButton>
                    <div className="erp-row">
                        <Field label="Code (ISO)"><TextBox width={90} maxLength={3} value={editing.code ?? ''} onValueChanged={(e) => setEditing({ ...editing, code: e.value?.toUpperCase() })} /></Field>
                        <Field label="Symbol"><TextBox width={90} value={editing.symbol ?? ''} onValueChanged={(e) => setEditing({ ...editing, symbol: e.value })} /></Field>
                    </div>
                    <Field label="Name"><TextBox value={editing.name ?? ''} onValueChanged={(e) => setEditing({ ...editing, name: e.value })} /></Field>
                    <Field label="Status"><SelectBox dataSource={['active', 'inactive']} value={editing.status} onValueChanged={(e) => setEditing({ ...editing, status: e.value })} /></Field>
                    <div className="erp-actions"><Button text="Cancel" onClick={() => setEditing(null)} /><Button text="Save" type="default" onClick={saveCurrency} /></div>
                </Popup>
            )}
        </>
    );
}

function Exposure({ base }: { base: string }) {
    const lastMonthEnd = (() => { const d = new Date(); d.setDate(0); return toDateString(d); })();
    const [asOf, setAsOf] = useState(lastMonthEnd);
    const data = useQuery({ queryKey: ['fx-exposure', asOf], queryFn: () => getOne<any>('/fx/exposure', { as_of: asOf }) });
    const history = useQuery({ queryKey: ['fx-revaluations'], queryFn: () => getList<any>('/fx/revaluations') });
    const preview = data.data?.revaluation;

    const run = async () => {
        if (!window.confirm(`Post the revaluation at ${asOf}? It is reversed automatically on the next day.`)) return;
        try {
            await api.post('/fx/revaluations', { as_of: asOf });
            notifySuccess('Revaluation posted and reversed for the next day.');
            data.refetch();
            history.refetch();
        } catch (e) { notifyError(e); }
    };

    return (
        <>
            <div className="erp-card">
                <div className="erp-card-title">Owed to suppliers in foreign currency</div>
                <DataGrid dataSource={data.data?.by_currency ?? []} keyExpr="currency" showRowLines columnAutoWidth noDataText="No open foreign-currency bills.">
                    <Column dataField="currency" width={90} />
                    <Column dataField="bills" caption="Open bills" width={100} />
                    <Column {...numberColumn('open_foreign')} caption="Owed (foreign)" />
                    <Column {...numberColumn('carried')} caption={`In the books (${base})`} />
                </DataGrid>
            </div>
            <div className="erp-card">
                <div className="erp-row">
                    <div className="erp-card-title">Revaluation at</div>
                    <DateBox type="date" width={150} value={asOf} max={new Date()} onValueChanged={(e) => setAsOf(toDateString(new Date(e.value)))} />
                    {can('finance.fx.revalue') && <Button text="Post revaluation" type="default" disabled={!preview?.lines?.length || preview?.missing_rates?.length > 0} onClick={run} />}
                </div>
                {preview?.missing_rates?.length > 0 && <div className="erp-error">No rate on or before {asOf} for {preview.missing_rates.join(', ')}.</div>}
                <div className="erp-muted">
                    Net effect: {Number(preview?.net_adjustment ?? 0) > 0 ? `loss of ${money(preview.net_adjustment)}` : Number(preview?.net_adjustment ?? 0) < 0 ? `gain of ${money(-preview.net_adjustment)}` : 'none'} {base}.
                    The entry is dated {asOf} and reversed the next day, so the real gain or loss is booked when each bill is paid.
                </div>
                <DataGrid dataSource={preview?.lines ?? []} keyExpr="id" showRowLines columnAutoWidth>
                    <Column dataField="number" caption="Bill" width={120} />
                    <Column dataField="party" caption="Supplier" />
                    <Column dataField="currency" width={80} />
                    <Column {...numberColumn('open_foreign')} caption="Owed (foreign)" />
                    <Column dataField="booked_rate" caption="Booked at" dataType="number" format="#,##0.0000" />
                    <Column dataField="closing_rate" caption="Closing rate" dataType="number" format="#,##0.0000" />
                    <Column {...numberColumn('carried')} caption="In the books" />
                    <Column {...numberColumn('revalued')} caption="Revalued" />
                    <Column {...numberColumn('difference')} caption="Loss (+) / gain (−)" cssClass="erp-strong" />
                    <Summary><TotalItem column="difference" summaryType="sum" valueFormat="#,##0.00" displayFormat="{0}" /></Summary>
                </DataGrid>
            </div>
            <div className="erp-card">
                <div className="erp-card-title">Past revaluations</div>
                <DataGrid dataSource={history.data ?? []} keyExpr="id" showRowLines columnAutoWidth>
                    <Column dataField="as_of" dataType="date" width={120} />
                    <Column {...numberColumn('net_adjustment')} caption="Loss (+) / gain (−)" />
                    <Column caption="Documents" calculateCellValue={(r: any) => r.lines?.length ?? 0} width={110} />
                    <Column dataField="created_at" caption="Posted" dataType="datetime" />
                </DataGrid>
            </div>
        </>
    );
}
