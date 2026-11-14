import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import DataGrid, { Column, Export, Summary, TotalItem } from 'devextreme-react/data-grid';
import SelectBox from 'devextreme-react/select-box';
import { getList, getOne } from '../lib/api';
import { money, numberColumn } from '../lib/format';

const sum = (c: string) => <TotalItem key={c} column={c} summaryType="sum" valueFormat="#,##0.00" displayFormat="{0}" />;

/** Account ledger with opening balance and running balance. */
export function GeneralLedger({ companyId, from, to }: { companyId: string | null; from: string; to: string }) {
    const accounts = useQuery({ queryKey: ['accounts'], queryFn: () => getList<{ id: string; code: string; name: string }>('/accounts') }).data ?? [];
    const [accountId, setAccountId] = useState<string | null>(null);
    const gl = useQuery({
        queryKey: ['gl', companyId, accountId, from, to], enabled: !!companyId && !!accountId,
        queryFn: () => getOne<any>('/reports/general-ledger', { company_id: companyId, account_id: accountId, from, to }),
    });
    let running = Number(gl.data?.opening_balance ?? 0);
    const rows = (gl.data?.lines ?? []).map((l: any) => ({ ...l, balance: (running += Number(l.debit) - Number(l.credit)) }));

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start', alignItems: 'center' }}>
                <SelectBox width={380} dataSource={accounts} valueExpr="id" displayExpr={(a: any) => (a ? `${a.code} ${a.name}` : '')} searchEnabled
                    placeholder="Choose an account" value={accountId} onValueChanged={(e) => setAccountId(e.value)} />
                {gl.data && <span>Opening <b>{money(gl.data.opening_balance)}</b> · Closing <b>{money(running)}</b></span>}
            </div>
            <DataGrid dataSource={rows} showRowLines columnAutoWidth height="calc(100vh - 360px)">
                <Export enabled />
                <Column dataField="posting_date" caption="Date" dataType="date" width={110} />
                <Column dataField="journal_no" caption="Journal" width={130} />
                <Column dataField="source_type" caption="Source" width={150} />
                <Column dataField="description" />
                <Column {...numberColumn('debit')} />
                <Column {...numberColumn('credit')} />
                <Column dataField="balance" dataType="number" format="#,##0.00" cssClass="erp-strong" />
                <Summary>{[sum('debit'), sum('credit')]}</Summary>
            </DataGrid>
        </div>
    );
}

/** Profit and loss by month or by branch, side by side. */
export function ProfitLossMatrix({ companyId, from, to }: { companyId: string | null; from: string; to: string }) {
    const [by, setBy] = useState<'month' | 'branch'>('month');
    const q = useQuery({ queryKey: ['pl-matrix', companyId, from, to, by], enabled: !!companyId, queryFn: () => getOne<any>('/reports/profit-loss-matrix', { company_id: companyId, from, to, by }) });
    const columns: { key: string; label: string }[] = q.data?.columns ?? [];
    const rows: any[] = q.data?.rows ?? [];
    const net = (key: string) => rows.reduce((s, r) => s + (r.type === 'revenue' ? 1 : -1) * Number(r[key] ?? 0), 0);

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                <SelectBox width={200} dataSource={[{ id: 'month', name: 'By month' }, { id: 'branch', name: 'By branch' }]} valueExpr="id" displayExpr="name" value={by} onValueChanged={(e) => setBy(e.value)} />
                <span className="erp-muted">Expenses are shown as positive figures.</span>
            </div>
            <DataGrid dataSource={rows} keyExpr="account_id" showRowLines columnAutoWidth height="calc(100vh - 400px)">
                <Export enabled />
                <Column dataField="type" groupIndex={0} caption="" groupCellRender={({ value }) => (value === 'revenue' ? 'Revenue' : 'Expenses')} />
                <Column dataField="code" width={90} fixed />
                <Column dataField="name" fixed />
                {columns.map((c) => <Column key={c.key} dataField={`c_${c.key}`} caption={c.label} dataType="number" format="#,##0" />)}
                <Column dataField="total" dataType="number" format="#,##0" cssClass="erp-strong" />
            </DataGrid>
            <div className="pos-total-row" style={{ fontWeight: 600, padding: '8px 12px', display: 'flex', gap: 16, flexWrap: 'wrap' }}>
                <span>Net profit</span>
                {columns.map((c) => <span key={c.key}>{c.label}: {money(net(`c_${c.key}`))}</span>)}
                <span>Total: {money(net('total'))}</span>
            </div>
        </div>
    );
}

export function CashFlow({ companyId, from, to }: { companyId: string | null; from: string; to: string }) {
    const q = useQuery({ queryKey: ['cash-flow', companyId, from, to], enabled: !!companyId, queryFn: () => getOne<any>('/reports/cash-flow', { company_id: companyId, from, to }) });
    const d = q.data;
    const section = (key: string, title: string) => (
        <div style={{ marginBottom: 16 }}>
            <h3 style={{ fontWeight: 500, margin: '0 0 6px' }}>{title}</h3>
            {(d?.sections?.[key] ?? []).map((r: any) => (
                <div key={r.label} style={{ display: 'flex', justifyContent: 'space-between', padding: '4px 12px', borderBottom: '1px solid var(--erp-border, #eee)' }}>
                    <span>{r.label}</span><span>{money(r.amount)}</span>
                </div>
            ))}
            <div className="pos-total-row" style={{ fontWeight: 600, padding: '6px 12px' }}><span>Net cash from {title.toLowerCase()}</span><span>{money(d?.totals?.[key])}</span></div>
        </div>
    );

    return (
        <div className="erp-card" style={{ maxWidth: 760 }}>
            <div className="pos-total-row" style={{ padding: '6px 12px' }}><span>Cash and bank at the start</span><span>{money(d?.opening)}</span></div>
            {section('operating', 'Operating activities')}
            {section('investing', 'Investing activities')}
            {section('financing', 'Financing activities')}
            <div className="pos-total-row" style={{ fontWeight: 600, padding: '6px 12px' }}><span>Net change in cash</span><span>{money(d?.totals?.net)}</span></div>
            <div className="pos-total-row" style={{ fontWeight: 700, padding: '6px 12px' }}><span>Cash and bank at the end</span><span>{money(d?.closing)}</span></div>
            <div className="erp-muted" style={{ marginTop: 8 }}>Direct method: each cash and bank movement is classified by the other side of its journal.</div>
        </div>
    );
}

export function BudgetVsActual({ from, to, branchId }: { from: string; to: string; branchId: string | null }) {
    const budgets = useQuery({ queryKey: ['budgets'], queryFn: () => getList<any>('/budgets') }).data ?? [];
    const [budgetId, setBudgetId] = useState<string | null>(null);
    const id = budgetId ?? budgets[0]?.id ?? null;
    const q = useQuery({ queryKey: ['bva', id, from, to, branchId], enabled: !!id, queryFn: () => getList<any>(`/reports/budget-vs-actual/${id}`, { from, to, branch_id: branchId ?? undefined }) });

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                <SelectBox width={300} dataSource={budgets} valueExpr="id" displayExpr="name" value={id} placeholder="No budgets yet — add one under Accounting tools" onValueChanged={(e) => setBudgetId(e.value)} />
            </div>
            <DataGrid dataSource={q.data ?? []} keyExpr="account_id" showRowLines columnAutoWidth height="calc(100vh - 380px)"
                onCellPrepared={(e) => { if (e.rowType === 'data' && e.column.dataField === 'variance') e.cellElement.style.color = e.data.favourable ? 'var(--erp-success, #15803d)' : 'var(--erp-danger, #b91c1c)'; }}>
                <Export enabled />
                <Column dataField="code" width={90} />
                <Column dataField="name" />
                <Column dataField="type" width={100} />
                <Column {...numberColumn('budget')} />
                <Column {...numberColumn('actual')} />
                <Column {...numberColumn('variance')} />
                <Column dataField="variance_percent" caption="%" dataType="number" width={80} />
            </DataGrid>
        </div>
    );
}

export function SalesTaxReturn({ companyId, from, to }: { companyId: string | null; from: string; to: string }) {
    const q = useQuery({ queryKey: ['sales-tax', companyId, from, to], enabled: !!companyId, queryFn: () => getOne<any>('/reports/sales-tax', { company_id: companyId, from, to }) });
    const d = q.data;
    const line = (label: string, value: unknown, strong = false) => (
        <div style={{ display: 'flex', justifyContent: 'space-between', padding: '5px 12px', fontWeight: strong ? 700 : 400, borderBottom: '1px solid var(--erp-border, #eee)' }}><span>{label}</span><span>{money(value)}</span></div>
    );

    return (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <div className="erp-card">
                <h3 style={{ fontWeight: 500, marginTop: 0 }}>Output tax (sales)</h3>
                <DataGrid dataSource={d?.output_by_rate ?? []} showBorders>
                    <Column dataField="rate" caption="Rate %" width={90} />
                    <Column {...numberColumn('taxable')} caption="Taxable value" />
                    <Column {...numberColumn('tax')} caption="Tax" />
                </DataGrid>
                {line('Less: tax on sales returns', d?.sales_returns_tax)}
                {line('Output tax', d?.output_tax, true)}
            </div>
            <div className="erp-card">
                <h3 style={{ fontWeight: 500, marginTop: 0 }}>Input tax (purchases)</h3>
                <DataGrid dataSource={d?.input_by_rate ?? []} showBorders>
                    <Column dataField="rate" caption="Rate %" width={90} />
                    <Column {...numberColumn('taxable')} caption="Taxable value" />
                    <Column {...numberColumn('tax')} caption="Tax" />
                </DataGrid>
                {line('Add: tax on expense bills', d?.expense_input_tax)}
                {line('Less: tax on debit notes', d?.debit_notes_tax)}
                {line('Input tax', d?.input_tax, true)}
            </div>
            <div className="erp-card" style={{ gridColumn: '1 / -1' }}>
                {line(Number(d?.payable ?? 0) >= 0 ? 'Sales tax payable for the period' : 'Sales tax refundable / carried forward', Math.abs(Number(d?.payable ?? 0)), true)}
                <div className="erp-muted" style={{ marginTop: 6 }}>A working summary for the sales tax return. Check it against your tax adviser's figures before filing.</div>
            </div>
        </div>
    );
}

