import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import DataGrid, { Column, Editing, Lookup, RequiredRule, Summary, TotalItem } from 'devextreme-react/data-grid';
import DateBox from 'devextreme-react/date-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import TextBox from 'devextreme-react/text-box';
import Page, { Field } from '../components/Page';
import { statusCell } from '../components/Status';
import { api, getList, notifyError, notifySuccess } from '../lib/api';
import { money, numberColumn, toDateString, today } from '../lib/format';
import { can, useSession } from '../lib/store';

interface Account { id: string; code: string; name: string; account_type: string }
const MONTHS = Array.from({ length: 12 }, (_, i) => i);
const useAccounts = () => useQuery({ queryKey: ['accounts'], queryFn: () => getList<Account>('/accounts') }).data ?? [];
const accountLabel = (a: Account) => (a ? `${a.code} ${a.name}` : '');

/** Budgets, recurring journals and the year-end close. */
export default function AccountingToolsPage() {
    return (
        <Page title="Accounting tools" subtitle="Budgets, recurring journals and closing the financial year.">
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="Budgets"><Budgets /></Item>
                <Item title="Recurring journals"><Recurring /></Item>
                {can('finance.year.close') && <Item title="Year-end close"><YearEnd /></Item>}
            </TabPanel>
        </Page>
    );
}

// ── Budgets ───────────────────────────────────────────────────

function Budgets() {
    const client = useQueryClient();
    const budgets = useQuery({ queryKey: ['budgets'], queryFn: () => getList<any>('/budgets') });
    const [editing, setEditing] = useState<any | 'new' | null>(null);

    const open = async (id: string) => {
        try { setEditing((await api.get(`/budgets/${id}`)).data.data); } catch (e) { notifyError(e); }
    };

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                {can('finance.budget.manage') && <Button text="New budget" icon="add" type="default" onClick={() => setEditing('new')} />}
                <span className="erp-muted">Compare under Financial reports → Budget vs actual.</span>
            </div>
            <DataGrid dataSource={budgets.data ?? []} keyExpr="id" showRowLines hoverStateEnabled onRowClick={(e) => e.rowType === 'data' && can('finance.budget.manage') && open(e.data.id)}>
                <Column dataField="name" />
                <Column dataField="start_date" caption="Starts" dataType="date" width={120} />
                <Column dataField="lines_count" caption="Lines" width={90} />
                <Column dataField="status" width={110} cellRender={statusCell} />
            </DataGrid>
            {editing && <BudgetEditor budget={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); client.invalidateQueries({ queryKey: ['budgets'] }); }} />}
        </div>
    );
}

function BudgetEditor({ budget, onClose, onSaved }: { budget: any | null; onClose: () => void; onSaved: () => void }) {
    const accounts = useAccounts().filter((a) => ['revenue', 'expense'].includes(a.account_type));
    const branches = useSession((s) => s.branches);
    const [name, setName] = useState(budget?.name ?? `Budget ${new Date().getFullYear()}`);
    const [start, setStart] = useState<string>(budget?.start_date ?? `${new Date().getFullYear()}-01-01`);
    const [rows, setRows] = useState<any[]>(() => (budget?.lines ?? []).map((l: any) => ({
        account_id: l.account_id, branch_id: l.branch_id, ...Object.fromEntries(MONTHS.map((i) => [`m${i}`, Number(l.amounts[i] ?? 0)])),
    })));
    const startDate = new Date(start);
    const label = (i: number) => new Date(startDate.getFullYear(), startDate.getMonth() + i, 1).toLocaleString('en', { month: 'short' });

    const spread = () => {
        const total = Number(window.prompt('Yearly amount to spread evenly over the 12 months, on every row?') ?? 0);
        if (!total) return;
        setRows(rows.map((r) => ({ ...r, ...Object.fromEntries(MONTHS.map((i) => [`m${i}`, Math.round((total / 12) * 100) / 100])) })));
    };

    const save = async () => {
        const body = { name, start_date: start, lines: rows.filter((r) => r.account_id).map((r) => ({ account_id: r.account_id, branch_id: r.branch_id ?? null, amounts: MONTHS.map((i) => r[`m${i}`] ?? 0) })) };
        try {
            if (budget) await api.put(`/budgets/${budget.id}`, body); else await api.post('/budgets', body);
            notifySuccess('Budget saved.');
            onSaved();
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <Popup visible title={budget ? budget.name : 'New budget'} width="98vw" height="92vh" onHiding={onClose} showCloseButton>
            <div style={{ display: 'flex', flexDirection: 'column', height: '100%', gap: 8 }}>
                <div style={{ display: 'flex', gap: 12, alignItems: 'end' }}>
                    <Field label="Name"><TextBox width={280} value={name} onValueChanged={(e) => setName(e.value)} /></Field>
                    <Field label="First month"><DateBox type="date" displayFormat="MMMM yyyy" calendarOptions={{ maxZoomLevel: 'year' }} value={start} onValueChanged={(e) => e.value && setStart(toDateString(new Date(e.value)))} /></Field>
                    <Button text="Same amount every month…" onClick={spread} />
                </div>
                <DataGrid dataSource={rows} showBorders columnAutoWidth height="100%" style={{ flex: 1 }}
                    onRowInserted={() => setRows([...rows])} onRowUpdated={() => setRows([...rows])} onRowRemoved={() => setRows([...rows])}>
                    <Editing mode="batch" allowAdding allowUpdating allowDeleting newRowPosition="last" startEditAction="click" />
                    <Column dataField="account_id" caption="Account" minWidth={240} fixed><Lookup dataSource={accounts} valueExpr="id" displayExpr={accountLabel} /><RequiredRule /></Column>
                    <Column dataField="branch_id" caption="Branch" width={140}><Lookup dataSource={branches} valueExpr="id" displayExpr="name" allowClearing /></Column>
                    {MONTHS.map((i) => <Column key={i} dataField={`m${i}`} caption={label(i)} dataType="number" format="#,##0" width={95} />)}
                    <Column caption="Year" dataType="number" format="#,##0" width={110} calculateCellValue={(r: any) => MONTHS.reduce((s, i) => s + Number(r[`m${i}`] ?? 0), 0)} />
                </DataGrid>
                <div className="erp-actions" style={{ marginTop: 0 }}>
                    <span className="erp-muted">Revenue and expense accounts. Leave branch empty for the whole company. Click Save in the grid toolbar to keep cell edits, then Save budget.</span>
                    <div style={{ flex: 1 }} />
                    <Button text="Cancel" onClick={onClose} />
                    <Button text="Save budget" type="default" onClick={save} />
                </div>
            </div>
        </Popup>
    );
}

// ── Recurring journals ────────────────────────────────────────

function Recurring() {
    const client = useQueryClient();
    const rows = useQuery({ queryKey: ['recurring'], queryFn: () => getList<any>('/recurring-journals') });
    const [editing, setEditing] = useState<any | 'new' | null>(null);
    const canManage = can('finance.recurring.manage');
    const refresh = () => client.invalidateQueries({ queryKey: ['recurring'] });

    const runNow = async () => {
        try {
            const { data } = await api.post('/recurring-journals/run');
            notifySuccess(`Posted ${data.data.posted} journals${data.data.failed ? `, ${data.data.failed} failed — see Last error` : ''}.`);
            refresh();
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                {canManage && <Button text="New recurring journal" icon="add" type="default" onClick={() => setEditing('new')} />}
                {canManage && <Button text="Post what is due now" icon="refresh" onClick={runNow} />}
                <span className="erp-muted">Due journals are also posted automatically every night.</span>
            </div>
            <DataGrid dataSource={rows.data ?? []} keyExpr="id" showRowLines columnAutoWidth hoverStateEnabled onRowClick={(e) => e.rowType === 'data' && canManage && setEditing(e.data)}>
                <Column dataField="name" />
                <Column dataField="frequency" width={110} />
                <Column caption="Amount" dataType="number" format="#,##0.00" calculateCellValue={(r: any) => r.lines.reduce((s: number, l: any) => s + Number(l.debit || 0), 0)} />
                <Column dataField="next_run_date" caption="Next" dataType="date" width={110} />
                <Column dataField="end_date" caption="Until" dataType="date" width={110} />
                <Column dataField="runs_count" caption="Posted" width={90} />
                <Column dataField="last_error" caption="Last error" />
                <Column dataField="status" width={110} cellRender={statusCell} />
            </DataGrid>
            {editing && <RecurringEditor value={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); refresh(); }} />}
        </div>
    );
}

function RecurringEditor({ value, onClose, onSaved }: { value: any | null; onClose: () => void; onSaved: () => void }) {
    const accounts = useAccounts();
    const branches = useSession((s) => s.branches);
    const [f, setF] = useState<any>(() => value ? { ...value, lines: value.lines.map((l: any) => ({ ...l, debit: Number(l.debit) || null, credit: Number(l.credit) || null })) }
        : { frequency: 'monthly', next_run_date: today(), lines: [] });
    const set = (patch: any) => setF((x: any) => ({ ...x, ...patch }));
    const dr = f.lines.reduce((s: number, l: any) => s + Number(l.debit || 0), 0);
    const cr = f.lines.reduce((s: number, l: any) => s + Number(l.credit || 0), 0);

    const save = async () => {
        const body = { name: f.name, description: f.description, frequency: f.frequency, next_run_date: f.next_run_date, end_date: f.end_date || null, status: f.status === 'paused' ? 'paused' : 'active', lines: f.lines };
        try {
            if (value) await api.put(`/recurring-journals/${value.id}`, body); else await api.post('/recurring-journals', body);
            notifySuccess('Saved.');
            onSaved();
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <Popup visible title={value ? value.name : 'New recurring journal'} width={900} height="auto" maxHeight="92vh" onHiding={onClose} showCloseButton>
            <div style={{ display: 'grid', gridTemplateColumns: '1.4fr 1fr 1fr 1fr', gap: 12 }}>
                <Field label="Name"><TextBox value={f.name ?? ''} placeholder="Shop rent" onValueChanged={(e) => set({ name: e.value })} /></Field>
                <Field label="Every"><SelectBox dataSource={[{ id: 'weekly', name: 'Week' }, { id: 'monthly', name: 'Month' }, { id: 'quarterly', name: 'Quarter' }, { id: 'yearly', name: 'Year' }]} valueExpr="id" displayExpr="name" value={f.frequency} onValueChanged={(e) => set({ frequency: e.value })} /></Field>
                <Field label="Next posting"><DateBox type="date" value={f.next_run_date} onValueChanged={(e) => e.value && set({ next_run_date: toDateString(new Date(e.value)) })} /></Field>
                <Field label="Until (optional)"><DateBox type="date" value={f.end_date ?? null} showClearButton onValueChanged={(e) => set({ end_date: e.value ? toDateString(new Date(e.value)) : null })} /></Field>
            </div>
            <Field label="Journal description"><TextBox value={f.description ?? ''} onValueChanged={(e) => set({ description: e.value })} /></Field>
            <DataGrid dataSource={f.lines} showBorders height={240}
                onRowInserted={() => set({ lines: [...f.lines] })} onRowUpdated={() => set({ lines: [...f.lines] })} onRowRemoved={() => set({ lines: [...f.lines] })}>
                <Editing mode="cell" allowAdding allowUpdating allowDeleting newRowPosition="last" />
                <Column dataField="account_id" caption="Account"><Lookup dataSource={accounts} valueExpr="id" displayExpr={accountLabel} /><RequiredRule /></Column>
                <Column dataField="branch_id" caption="Branch" width={150}><Lookup dataSource={branches} valueExpr="id" displayExpr="name" allowClearing /></Column>
                <Column dataField="description" />
                <Column dataField="debit" dataType="number" format="#,##0.00" width={130} />
                <Column dataField="credit" dataType="number" format="#,##0.00" width={130} />
                <Summary><TotalItem column="debit" summaryType="sum" valueFormat="#,##0.00" displayFormat="{0}" /><TotalItem column="credit" summaryType="sum" valueFormat="#,##0.00" displayFormat="{0}" /></Summary>
            </DataGrid>
            <div className="erp-actions">
                {Math.abs(dr - cr) > 0.004 && <span style={{ color: 'var(--erp-danger, #b91c1c)' }}>Out of balance by {money(dr - cr)}</span>}
                {value && <Button text={f.status === 'paused' ? 'Resume' : 'Pause'} stylingMode="outlined" onClick={() => set({ status: f.status === 'paused' ? 'active' : 'paused' })} />}
                <div style={{ flex: 1 }} />
                <Button text="Cancel" onClick={onClose} />
                <Button text="Save" type="default" disabled={!f.name || f.lines.length < 2 || Math.abs(dr - cr) > 0.004 || dr === 0} onClick={save} />
            </div>
        </Popup>
    );
}

// ── Year-end close ────────────────────────────────────────────

function YearEnd() {
    const client = useQueryClient();
    const closings = useQuery({ queryKey: ['year-end'], queryFn: () => getList<any>('/year-end-closings') });
    const year = new Date().getFullYear() - 1;
    const [start, setStart] = useState(`${year}-01-01`);
    const [end, setEnd] = useState(`${year}-12-31`);
    const [confirm, setConfirm] = useState(false);

    const close = async () => {
        try {
            const { data } = await api.post('/year-end-closings', { year_start: start, year_end: end });
            notifySuccess(`Year closed. ${Number(data.data.net_income) >= 0 ? 'Profit' : 'Loss'} of ${money(Math.abs(Number(data.data.net_income)))} moved to retained earnings.`);
            setConfirm(false);
            client.invalidateQueries({ queryKey: ['year-end'] });
        } catch (e) {
            notifyError(e);
        }
    };
    const reopen = async (id: string) => {
        const reason = window.prompt('Why reopen this year?');
        if (!reason) return;
        try {
            await api.post(`/year-end-closings/${id}/reopen`, { reason });
            notifySuccess('Year reopened.');
            client.invalidateQueries({ queryKey: ['year-end'] });
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <div className="erp-card">
            <p style={{ maxWidth: 760 }}>Closing moves every income and expense balance for the year into retained earnings with one journal on the last day,
                then closes the year's accounting periods so nothing more can be posted into them. Profit and loss reports still show the year's figures.</p>
            <div style={{ display: 'flex', gap: 12, alignItems: 'end' }}>
                <Field label="Year starts"><DateBox type="date" value={start} onValueChanged={(e) => e.value && setStart(toDateString(new Date(e.value)))} /></Field>
                <Field label="Year ends"><DateBox type="date" value={end} max={new Date()} onValueChanged={(e) => e.value && setEnd(toDateString(new Date(e.value)))} /></Field>
                {confirm
                    ? <><Button text="Yes, close the year" type="danger" onClick={close} /><Button text="Cancel" onClick={() => setConfirm(false)} /></>
                    : <Button text="Close year…" type="default" onClick={() => setConfirm(true)} />}
            </div>
            <DataGrid dataSource={closings.data ?? []} keyExpr="id" showRowLines style={{ marginTop: 16 }}>
                <Column dataField="year_start" dataType="date" />
                <Column dataField="year_end" dataType="date" />
                <Column {...numberColumn('net_income')} caption="Profit / (loss)" />
                <Column dataField="status" width={110} cellRender={statusCell} />
                <Column caption="" width={110} cellRender={({ data }: any) => data.status === 'closed' && <Button text="Reopen" stylingMode="text" onClick={() => reopen(data.id)} />} />
            </DataGrid>
        </div>
    );
}
