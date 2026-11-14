import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import DataGrid, { Column, Editing, FilterRow, SearchPanel, Summary, TotalItem } from 'devextreme-react/data-grid';
import DateBox from 'devextreme-react/date-box';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TextBox from 'devextreme-react/text-box';
import Page, { Field } from '../components/Page';
import { api, getList, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { useAppConfig } from '../lib/config';
import { money, numberColumn, startOfMonth, toDateString, today } from '../lib/format';
import { printDocument } from '../lib/print';
import { can } from '../lib/store';

interface AgingRow {
    customer_id: string; customer_code: string; name: string; phone: string | null; credit_limit: string; balance: string;
    current: string; d1_30: string; d31_60: string; d61_90: string; d90_plus: string; not_by_invoice: string; overdue: string;
}
interface OpenInvoice { id: string; sale_no: string; sold_at: string; due_date: string | null; grand_total: string; balance_due: string; reference: string | null; pay?: number }
interface Statement {
    customer: { id: string; customer_code: string; name: string; phone: string | null; address: string | null; tax_no: string | null };
    from: string; to: string; opening: string; closing: string;
    entries: { date: string; doc_no: string; kind: string; reference: string | null; due_date: string | null; debit: string; credit: string; balance: string }[];
}

const sumCol = (c: string) => <TotalItem column={c} summaryType="sum" valueFormat="#,##0.00" displayFormat="{0}" />;

/** Who owes what, how overdue it is, statements, and receipts applied to invoices. */
export default function ReceivablesPage() {
    const client = useQueryClient();
    const [asOf, setAsOf] = useState<string>(today());
    const aging = useQuery({ queryKey: ['aging', asOf], queryFn: () => getList<AgingRow>('/receivables/aging', { as_of: asOf }) });
    const [statementFor, setStatementFor] = useState<AgingRow | null>(null);
    const [receiving, setReceiving] = useState<AgingRow | null>(null);

    return (
        <Page title="Receivables" subtitle="Customer balances by age, account statements and receipts against invoices.">
            <div className="erp-card">
                <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start', alignItems: 'center' }}>
                    <span className="erp-muted">Aged as of</span>
                    <DateBox type="date" value={asOf} onValueChanged={(e) => e.value && setAsOf(toDateString(new Date(e.value)))} />
                </div>
                <DataGrid dataSource={aging.data ?? []} keyExpr="customer_id" showRowLines hoverStateEnabled columnAutoWidth height="calc(100vh - 260px)"
                    onCellPrepared={(e) => {
                        if (e.rowType === 'data' && ['d31_60', 'd61_90', 'd90_plus'].includes(String(e.column.dataField)) && Number(e.value) > 0) {
                            e.cellElement.style.color = 'var(--erp-danger, #b91c1c)';
                            e.cellElement.style.fontWeight = '600';
                        }
                    }}>
                    <SearchPanel visible width={260} />
                    <FilterRow visible />
                    <Column dataField="customer_code" caption="Code" width={120} />
                    <Column dataField="name" caption="Customer" />
                    <Column dataField="phone" width={130} />
                    <Column {...numberColumn('credit_limit')} caption="Limit" />
                    <Column {...numberColumn('current')} caption="Not due" />
                    <Column {...numberColumn('d1_30')} caption="1–30" />
                    <Column {...numberColumn('d31_60')} caption="31–60" />
                    <Column {...numberColumn('d61_90')} caption="61–90" />
                    <Column {...numberColumn('d90_plus')} caption="90+" />
                    <Column {...numberColumn('not_by_invoice')} caption="Other / unapplied" />
                    <Column {...numberColumn('balance')} caption="Total owed" cssClass="erp-strong" sortOrder="desc" />
                    <Column caption="" width={200} cellRender={({ data }: { data: AgingRow }) => (
                        <>
                            <Button text="Statement" stylingMode="text" onClick={() => setStatementFor(data)} />
                            {can('sales.payment.receive') && Number(data.balance) > 0 && <Button text="Receive" stylingMode="text" type="success" onClick={() => setReceiving(data)} />}
                        </>
                    )} />
                    <Summary>{['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'not_by_invoice', 'balance'].map((c) => <TotalItem key={c} column={c} summaryType="sum" valueFormat="#,##0.00" displayFormat="{0}" />)}</Summary>
                </DataGrid>
            </div>
            {statementFor && <StatementDialog row={statementFor} onClose={() => setStatementFor(null)} />}
            {receiving && <ReceiveDialog row={receiving} onClose={() => setReceiving(null)} onDone={() => { setReceiving(null); client.invalidateQueries({ queryKey: ['aging'] }); }} />}
        </Page>
    );
}

function StatementDialog({ row, onClose }: { row: AgingRow; onClose: () => void }) {
    const { config } = useAppConfig();
    const [from, setFrom] = useState(startOfMonth());
    const [to, setTo] = useState(today());
    const [data, setData] = useState<Statement | null>(null);

    useEffect(() => {
        api.get(`/customers/${row.customer_id}/statement`, { params: { from, to } }).then((r) => setData(r.data.data)).catch(notifyError);
    }, [row.customer_id, from, to]);

    const print = () => data && printDocument({
        title: 'Statement of account', number: `${data.from} to ${data.to}`, company: config?.company, currency: config?.company?.base_currency,
        party: { name: data.customer.name, code: data.customer.customer_code, phone: data.customer.phone, address: data.customer.address, tax_no: data.customer.tax_no },
        meta: [['Period', `${data.from} – ${data.to}`], ['Opening balance', money(data.opening)], ['Closing balance', money(data.closing)]],
        table: {
            head: ['Date', 'Document', 'Type', 'Reference', 'Due', 'Debit', 'Credit', 'Balance'],
            align: ['left', 'left', 'left', 'left', 'left', 'right', 'right', 'right'],
            rows: [[data.from, '', 'Opening balance', '', '', '', '', money(data.opening)],
                ...data.entries.map((e) => [e.date, e.doc_no, e.kind, e.reference ?? '', e.due_date ?? '', Number(e.debit) ? money(e.debit) : '', Number(e.credit) ? money(e.credit) : '', money(e.balance)])],
        },
        totals: [['Amount due', data.closing, true]],
    });

    return (
        <Popup visible title={`Statement — ${row.name}`} width="min(1000px, 96vw)" height="86vh" onHiding={onClose} showCloseButton>
            <div style={{ display: 'flex', flexDirection: 'column', height: '100%', gap: 8 }}>
                <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                    <DateBox type="date" value={from} onValueChanged={(e) => e.value && setFrom(toDateString(new Date(e.value)))} />
                    <span>to</span>
                    <DateBox type="date" value={to} onValueChanged={(e) => e.value && setTo(toDateString(new Date(e.value)))} />
                    <div style={{ flex: 1 }} />
                    <span>Opening <b>{money(data?.opening)}</b> · Closing <b>{money(data?.closing)}</b></span>
                    <Button text="Print / PDF" icon="print" disabled={!data} onClick={print} />
                </div>
                <DataGrid dataSource={data?.entries ?? []} showRowLines columnAutoWidth height="100%" style={{ flex: 1 }}>
                    <Column dataField="date" dataType="date" width={110} />
                    <Column dataField="doc_no" caption="Document" />
                    <Column dataField="kind" caption="Type" />
                    <Column dataField="reference" />
                    <Column dataField="due_date" caption="Due" dataType="date" width={110} />
                    <Column {...numberColumn('debit')} />
                    <Column {...numberColumn('credit')} />
                    <Column {...numberColumn('balance')} cssClass="erp-strong" />
                    <Summary>{sumCol('debit')}{sumCol('credit')}</Summary>
                </DataGrid>
            </div>
        </Popup>
    );
}

function ReceiveDialog({ row, onClose, onDone }: { row: AgingRow; onClose: () => void; onDone: () => void }) {
    const companies = useQuery({ queryKey: ['first-company'], queryFn: () => api.get('/app-config').then((r) => r.data.data.company) });
    const [invoices, setInvoices] = useState<OpenInvoice[]>([]);
    const [amount, setAmount] = useState<number>(Number(row.balance));
    const [method, setMethod] = useState('bank');
    const [reference, setReference] = useState('');
    const [manual, setManual] = useState(false);
    const [busy, setBusy] = useState(false);
    const [key] = useState(newIdempotencyKey);

    useEffect(() => {
        getList<OpenInvoice>(`/customers/${row.customer_id}/open-invoices`).then((list) => setInvoices(list.map((i) => ({ ...i, pay: 0 })))).catch(notifyError);
    }, [row.customer_id]);

    // Oldest first, as the server does when nothing is picked.
    const autoSplit = (total: number) => {
        let left = total;
        return invoices.map((i) => { const pay = Math.min(Number(i.balance_due), Math.max(left, 0)); left -= pay; return { ...i, pay: Math.round(pay * 100) / 100 }; });
    };
    const shown = manual ? invoices : autoSplit(amount);
    const allocated = shown.reduce((s, i) => s + Number(i.pay || 0), 0);

    const save = async () => {
        setBusy(true);
        try {
            await api.post(`/customers/${row.customer_id}/payments`, {
                company_id: companies.data?.id, method, amount, reference: reference || null,
                ...(manual ? { allocations: invoices.filter((i) => Number(i.pay) > 0).map((i) => ({ sale_id: i.id, amount: i.pay })) } : {}),
            }, idempotent(key));
            notifySuccess(`Receipt of ${money(amount)} recorded.`);
            onDone();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title={`Receive payment — ${row.name}`} width={820} height="auto" maxHeight="90vh" onHiding={onClose} showCloseButton>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1.4fr', gap: 12 }}>
                <Field label={`Amount (owes ${money(row.balance)})`}><NumberBox min={0} max={Number(row.balance)} format="#,##0.00" value={amount} onValueChanged={(e) => setAmount(e.value ?? 0)} /></Field>
                <Field label="Received by">
                    <SelectBox dataSource={[{ id: 'bank', name: 'Bank / cheque' }, { id: 'cash', name: 'Cash' }]} valueExpr="id" displayExpr="name" value={method} onValueChanged={(e) => setMethod(e.value)} />
                </Field>
                <Field label="Reference (cheque / transfer no.)"><TextBox value={reference} onValueChanged={(e) => setReference(e.value)} /></Field>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, margin: '10px 0 6px' }}>
                <span className="erp-muted">{manual ? 'Choose what this receipt pays' : 'Applied to the oldest invoices first'}</span>
                <div style={{ flex: 1 }} />
                <Button text={manual ? 'Apply automatically' : 'Choose invoices'} stylingMode="text" onClick={() => { if (!manual) setInvoices(autoSplit(amount)); setManual(!manual); }} />
            </div>
            <DataGrid dataSource={shown} keyExpr="id" showBorders height={260} onRowUpdated={() => setInvoices([...invoices])}>
                <Editing mode="cell" allowUpdating={manual} />
                <Column dataField="sale_no" caption="Invoice" allowEditing={false} />
                <Column dataField="sold_at" caption="Date" dataType="date" allowEditing={false} width={110} />
                <Column dataField="due_date" caption="Due" dataType="date" allowEditing={false} width={110} />
                <Column {...numberColumn('grand_total')} caption="Total" allowEditing={false} />
                <Column {...numberColumn('balance_due')} caption="Open" allowEditing={false} />
                <Column dataField="pay" caption="Apply" dataType="number" format="#,##0.00" width={120} />
            </DataGrid>
            <div className="erp-actions">
                <span>Applied to invoices <b>{money(allocated)}</b>{amount - allocated > 0.004 ? <> · on account <b>{money(amount - allocated)}</b></> : null}</span>
                <div style={{ flex: 1 }} />
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Saving…' : 'Record receipt'} type="default" disabled={busy || amount <= 0 || (manual && allocated - amount > 0.004)} onClick={save} />
            </div>
        </Popup>
    );
}
