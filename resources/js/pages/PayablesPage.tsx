import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import CheckBox from 'devextreme-react/check-box';
import DataGrid, { Column, Editing, FilterRow, Lookup, RequiredRule, SearchPanel, Summary, TotalItem } from 'devextreme-react/data-grid';
import DateBox from 'devextreme-react/date-box';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import TextBox from 'devextreme-react/text-box';
import Page, { Field } from '../components/Page';
import { statusCell } from '../components/Status';
import { api, getList, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { useAppConfig } from '../lib/config';
import { money, numberColumn, startOfMonth, toDateString, today } from '../lib/format';
import { printDocument } from '../lib/print';
import { can } from '../lib/store';

interface Supplier { id: string; supplier_code: string; name: string; payment_terms_days?: number }
interface Receipt { id: string; grn_no: string; received_at: string; grand_total: string; returned_total: string; supplier_invoice_no?: string | null; pick?: boolean }
interface Bill { id: string; bill_no: string; supplier_invoice_no: string; bill_date: string; due_date: string; supplier?: Supplier; received_value: string; variance: string; expense_total: string; expense_tax: string; grand_total: string; balance_due: string; status: string }
interface Expense { account_id: string; description: string; amount: number; tax_amount?: number }
interface AgingRow { supplier_id: string; supplier_code: string; name: string; phone: string | null; balance: string; current: string; d1_30: string; d31_60: string; d61_90: string; d90_plus: string; not_billed: string; other: string }

/** What we owe suppliers: their invoices matched to goods received, goods sent back, and payments. */
export default function PayablesPage() {
    return (
        <Page title="Payables" subtitle="Supplier invoices matched to goods received, debit notes, aging and payments.">
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="What we owe"><Aging /></Item>
                {can('purchasing.bill.manage') && <Item title="Supplier bills"><Bills /></Item>}
                {can('purchasing.return.create') && <Item title="Debit notes (returns)"><Returns /></Item>}
            </TabPanel>
        </Page>
    );
}

const useSuppliers = () => useQuery({ queryKey: ['suppliers'], queryFn: () => getList<Supplier>('/suppliers') }).data ?? [];

// ── Bills ─────────────────────────────────────────────────────

function Bills() {
    const client = useQueryClient();
    const bills = useQuery({ queryKey: ['supplier-bills'], queryFn: () => getList<Bill>('/supplier-bills') });
    const [open, setOpen] = useState(false);

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                <Button text="Enter supplier invoice" icon="add" type="default" onClick={() => setOpen(true)} />
            </div>
            <DataGrid dataSource={bills.data ?? []} keyExpr="id" showRowLines columnAutoWidth height="calc(100vh - 300px)">
                <SearchPanel visible width={260} />
                <FilterRow visible />
                <Column dataField="bill_no" caption="Bill" width={120} />
                <Column dataField="supplier_invoice_no" caption="Their invoice" />
                <Column caption="Supplier" calculateCellValue={(b: Bill) => b.supplier?.name} />
                <Column dataField="bill_date" dataType="date" width={110} sortOrder="desc" />
                <Column dataField="due_date" dataType="date" width={110} />
                <Column {...numberColumn('received_value')} caption="Goods received" />
                <Column {...numberColumn('variance')} caption="Difference" />
                <Column {...numberColumn('expense_total')} caption="Expenses" />
                <Column {...numberColumn('grand_total')} caption="Total" />
                <Column {...numberColumn('balance_due')} caption="Open" cssClass="erp-strong" />
                <Column dataField="status" width={100} cellRender={statusCell} />
                <Summary><TotalItem column="balance_due" summaryType="sum" valueFormat="#,##0.00" displayFormat="{0}" /></Summary>
            </DataGrid>
            {open && <BillDialog onClose={() => setOpen(false)} onDone={() => { setOpen(false); client.invalidateQueries({ queryKey: ['supplier-bills'] }); client.invalidateQueries({ queryKey: ['payables'] }); }} />}
        </div>
    );
}

function BillDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
    const suppliers = useSuppliers();
    const accounts = useQuery({ queryKey: ['accounts'], queryFn: () => getList<{ id: string; code: string; name: string; account_type: string }>('/accounts') }).data ?? [];
    const [supplierId, setSupplierId] = useState<string | null>(null);
    const [receipts, setReceipts] = useState<Receipt[]>([]);
    const [invoiceNo, setInvoiceNo] = useState('');
    const [billDate, setBillDate] = useState(today());
    const [dueDate, setDueDate] = useState<string | null>(null);
    const [goodsAmount, setGoodsAmount] = useState<number | null>(null);
    const [expenses, setExpenses] = useState<Expense[]>([]);
    const [notes, setNotes] = useState('');
    const [busy, setBusy] = useState(false);
    const [key] = useState(newIdempotencyKey);

    useEffect(() => {
        if (!supplierId) return;
        getList<Receipt>(`/suppliers/${supplierId}/unbilled-receipts`).then((r) => setReceipts(r.map((x) => ({ ...x, pick: true })))).catch(notifyError);
    }, [supplierId]);

    const picked = receipts.filter((r) => r.pick);
    const received = picked.reduce((s, r) => s + Number(r.grand_total) - Number(r.returned_total), 0);
    const theirGoods = goodsAmount ?? received;
    const variance = picked.length ? theirGoods - received : 0;
    const expenseTotal = expenses.reduce((s, e) => s + Number(e.amount || 0) + Number(e.tax_amount || 0), 0);
    const total = received + variance + expenseTotal;

    const save = async () => {
        setBusy(true);
        try {
            await api.post('/supplier-bills', {
                supplier_id: supplierId, supplier_invoice_no: invoiceNo, bill_date: billDate, due_date: dueDate,
                goods_receipt_ids: picked.map((r) => r.id), invoice_goods_amount: picked.length ? theirGoods : null,
                expenses, notes: notes || null,
            }, idempotent(key));
            notifySuccess('Supplier invoice recorded.');
            onDone();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title="Enter supplier invoice" width="min(1000px, 97vw)" height="auto" maxHeight="94vh" onHiding={onClose} showCloseButton>
            <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr 1fr', gap: 12 }}>
                <Field label="Supplier">
                    <SelectBox dataSource={suppliers} valueExpr="id" displayExpr={(s: Supplier) => (s ? `${s.name} (${s.supplier_code})` : '')} searchEnabled value={supplierId} onValueChanged={(e) => setSupplierId(e.value)} />
                </Field>
                <Field label="Their invoice no."><TextBox value={invoiceNo} onValueChanged={(e) => setInvoiceNo(e.value)} /></Field>
                <Field label="Invoice date"><DateBox type="date" value={billDate} max={new Date()} onValueChanged={(e) => e.value && setBillDate(toDateString(new Date(e.value)))} /></Field>
                <Field label="Due (empty = supplier terms)"><DateBox type="date" value={dueDate} showClearButton onValueChanged={(e) => setDueDate(e.value ? toDateString(new Date(e.value)) : null)} /></Field>
            </div>

            <div className="erp-muted" style={{ margin: '10px 0 4px' }}>Goods receipts this invoice covers</div>
            <DataGrid dataSource={receipts} keyExpr="id" showBorders height={170} noDataText={supplierId ? 'No receipts waiting for an invoice.' : 'Choose a supplier.'}>
                <Column caption="" width={50} cellRender={({ data }: { data: Receipt }) => (
                    <CheckBox value={!!data.pick} onValueChanged={(e) => setReceipts(receipts.map((r) => (r.id === data.id ? { ...r, pick: e.value } : r)))} />
                )} />
                <Column dataField="grn_no" caption="Receipt" />
                <Column dataField="received_at" caption="Received" dataType="date" />
                <Column dataField="supplier_invoice_no" caption="Invoice no. noted at receipt" />
                <Column caption="Value (after returns)" dataType="number" format="#,##0.00" calculateCellValue={(r: Receipt) => Number(r.grand_total) - Number(r.returned_total)} />
            </DataGrid>
            {picked.length > 0 && (
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12, marginTop: 8, alignItems: 'end' }}>
                    <Field label="Goods received (our value)"><TextBox readOnly value={money(received)} /></Field>
                    <Field label="Goods on their invoice"><NumberBox format="#,##0.00" min={0} value={theirGoods} onValueChanged={(e) => setGoodsAmount(e.value)} /></Field>
                    <div style={{ paddingBottom: 8, color: Math.abs(variance) > 0.004 ? 'var(--erp-danger, #b91c1c)' : undefined }}>
                        {Math.abs(variance) > 0.004 ? <>Difference <b>{money(variance)}</b> — posted to price variance</> : 'Matches the goods received'}
                    </div>
                </div>
            )}

            <div className="erp-muted" style={{ margin: '10px 0 4px' }}>Other charges on the invoice (freight, services, rent, utilities)</div>
            <DataGrid dataSource={expenses} showBorders height={150}
                onRowInserted={() => setExpenses([...expenses])} onRowUpdated={() => setExpenses([...expenses])} onRowRemoved={() => setExpenses([...expenses])}>
                <Editing mode="cell" allowAdding allowUpdating allowDeleting newRowPosition="last" />
                <Column dataField="account_id" caption="Expense account">
                    <Lookup dataSource={accounts.filter((a) => a.account_type === 'expense' || a.account_type === 'asset')} valueExpr="id" displayExpr={(a: any) => (a ? `${a.code} ${a.name}` : '')} />
                    <RequiredRule />
                </Column>
                <Column dataField="description"><RequiredRule /></Column>
                <Column dataField="amount" dataType="number" format="#,##0.00" width={130}><RequiredRule /></Column>
                <Column dataField="tax_amount" caption="Input tax" dataType="number" format="#,##0.00" width={120} />
            </DataGrid>
            <Field label="Notes"><TextBox value={notes} onValueChanged={(e) => setNotes(e.value)} /></Field>

            <div className="erp-actions">
                <span>Invoice total <b>{money(total)}</b></span>
                <div style={{ flex: 1 }} />
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Saving…' : 'Record invoice'} type="default" disabled={busy || !supplierId || !invoiceNo || (picked.length === 0 && expenses.length === 0)} onClick={save} />
            </div>
        </Popup>
    );
}

// ── Debit notes ───────────────────────────────────────────────

function Returns() {
    const client = useQueryClient();
    const rows = useQuery({ queryKey: ['purchase-returns'], queryFn: () => getList('/purchase-returns') });
    const [open, setOpen] = useState(false);

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                <Button text="Return goods to supplier" icon="revert" type="default" onClick={() => setOpen(true)} />
            </div>
            <DataGrid dataSource={rows.data ?? []} keyExpr="id" showRowLines columnAutoWidth>
                <SearchPanel visible width={260} />
                <Column dataField="return_no" caption="Debit note" width={120} />
                <Column dataField="returned_at" caption="Date" dataType="date" width={110} sortOrder="desc" />
                <Column caption="Supplier" calculateCellValue={(r: any) => r.supplier?.name} />
                <Column dataField="reason" />
                <Column {...numberColumn('grand_total')} caption="Credit from supplier" />
                <Column {...numberColumn('stock_cost')} caption="Stock value out" />
            </DataGrid>
            {open && <ReturnDialog onClose={() => setOpen(false)} onDone={() => { setOpen(false); client.invalidateQueries({ queryKey: ['purchase-returns'] }); }} />}
        </div>
    );
}

function ReturnDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
    const suppliers = useSuppliers();
    const [supplierId, setSupplierId] = useState<string | null>(null);
    const [receipts, setReceipts] = useState<Receipt[]>([]);
    const [receiptId, setReceiptId] = useState<string | null>(null);
    const [items, setItems] = useState<any[]>([]);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [key] = useState(newIdempotencyKey);

    useEffect(() => { if (supplierId) getList<Receipt>('/goods-receipts', { supplier_id: supplierId }).then(setReceipts).catch(notifyError); }, [supplierId]);
    useEffect(() => {
        if (!receiptId) return;
        api.get(`/goods-receipts/${receiptId}/returnable`).then((r) => setItems(r.data.data.items.map((i: any) => ({ ...i, left: Number(i.quantity) - Number(i.returned_quantity), qty: 0 })))).catch(notifyError);
    }, [receiptId]);

    const save = async () => {
        setBusy(true);
        try {
            await api.post('/purchase-returns', { goods_receipt_id: receiptId, reason, lines: items.filter((i) => i.qty > 0).map((i) => ({ goods_receipt_item_id: i.id, quantity: i.qty })) }, idempotent(key));
            notifySuccess('Debit note recorded. Stock and the supplier account are updated.');
            onDone();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title="Return goods to supplier" width={820} height="auto" maxHeight="92vh" onHiding={onClose} showCloseButton>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                <Field label="Supplier"><SelectBox dataSource={suppliers} valueExpr="id" displayExpr="name" searchEnabled value={supplierId} onValueChanged={(e) => { setSupplierId(e.value); setReceiptId(null); setItems([]); }} /></Field>
                <Field label="Goods receipt"><SelectBox dataSource={receipts} valueExpr="id" displayExpr={(r: Receipt) => (r ? `${r.grn_no} — ${String(r.received_at).slice(0, 10)} — ${money(r.grand_total)}` : '')} value={receiptId} onValueChanged={(e) => setReceiptId(e.value)} /></Field>
            </div>
            <DataGrid dataSource={items} keyExpr="id" showBorders height={230} onRowUpdated={() => setItems([...items])}>
                <Editing mode="cell" allowUpdating />
                <Column dataField="sku" allowEditing={false} width={110} />
                <Column dataField="name" allowEditing={false} />
                <Column dataField="batch_no" caption="Batch" allowEditing={false} width={100} />
                <Column dataField="left" caption="Can return" allowEditing={false} width={100} />
                <Column {...numberColumn('unit_cost')} caption="Cost" allowEditing={false} />
                <Column dataField="qty" caption="Return qty" dataType="number" width={110} />
            </DataGrid>
            <Field label="Reason"><TextBox value={reason} placeholder="Damaged, expired, wrong item …" onValueChanged={(e) => setReason(e.value)} /></Field>
            <div className="erp-actions">
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Saving…' : 'Record debit note'} type="default" disabled={busy || !receiptId || !reason || !items.some((i) => i.qty > 0)} onClick={save} />
            </div>
        </Popup>
    );
}

// ── Aging, statement, payment ─────────────────────────────────

function Aging() {
    const client = useQueryClient();
    const { config } = useAppConfig();
    const [asOf, setAsOf] = useState(today());
    const rows = useQuery({ queryKey: ['payables', asOf], queryFn: () => getList<AgingRow>('/payables/aging', { as_of: asOf }) });
    const [statement, setStatement] = useState<AgingRow | null>(null);
    const [paying, setPaying] = useState<AgingRow | null>(null);

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start', alignItems: 'center' }}>
                <span className="erp-muted">Aged as of</span>
                <DateBox type="date" value={asOf} onValueChanged={(e) => e.value && setAsOf(toDateString(new Date(e.value)))} />
            </div>
            <DataGrid dataSource={rows.data ?? []} keyExpr="supplier_id" showRowLines columnAutoWidth height="calc(100vh - 300px)">
                <SearchPanel visible width={260} />
                <Column dataField="supplier_code" caption="Code" width={120} />
                <Column dataField="name" caption="Supplier" />
                <Column {...numberColumn('current')} caption="Not due" />
                <Column {...numberColumn('d1_30')} caption="1–30" />
                <Column {...numberColumn('d31_60')} caption="31–60" />
                <Column {...numberColumn('d61_90')} caption="61–90" />
                <Column {...numberColumn('d90_plus')} caption="90+" />
                <Column {...numberColumn('not_billed')} caption="Received, not billed" />
                <Column {...numberColumn('other')} caption="Unapplied / other" />
                <Column {...numberColumn('balance')} caption="Total owed" cssClass="erp-strong" sortOrder="desc" />
                <Column caption="" width={190} cellRender={({ data }: { data: AgingRow }) => (
                    <>
                        <Button text="Statement" stylingMode="text" onClick={() => setStatement(data)} />
                        {can('purchasing.payment.create') && Number(data.balance) > 0 && <Button text="Pay" stylingMode="text" type="success" onClick={() => setPaying(data)} />}
                    </>
                )} />
                <Summary>{['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'not_billed', 'balance'].map((c) => <TotalItem key={c} column={c} summaryType="sum" valueFormat="#,##0.00" displayFormat="{0}" />)}</Summary>
            </DataGrid>
            {statement && <SupplierStatement row={statement} config={config} onClose={() => setStatement(null)} />}
            {paying && <PayDialog row={paying} companyId={config?.company?.id} onClose={() => setPaying(null)} onDone={() => { setPaying(null); client.invalidateQueries({ queryKey: ['payables'] }); client.invalidateQueries({ queryKey: ['supplier-bills'] }); }} />}
        </div>
    );
}

function SupplierStatement({ row, config, onClose }: { row: AgingRow; config: any; onClose: () => void }) {
    const [from, setFrom] = useState(startOfMonth());
    const [to, setTo] = useState(today());
    const [data, setData] = useState<any>(null);
    useEffect(() => { api.get(`/suppliers/${row.supplier_id}/statement`, { params: { from, to } }).then((r) => setData(r.data.data)).catch(notifyError); }, [row.supplier_id, from, to]);

    const print = () => data && printDocument({
        title: 'Supplier statement', number: `${data.from} to ${data.to}`, company: config?.company, currency: config?.company?.base_currency,
        party: { name: data.supplier.name, code: data.supplier.supplier_code, phone: data.supplier.phone, address: data.supplier.address, tax_no: data.supplier.tax_no },
        meta: [['Opening balance', money(data.opening)], ['Closing balance', money(data.closing)]],
        table: { head: ['Date', 'Document', 'Type', 'Reference', 'Debit', 'Credit', 'Balance'], align: ['left', 'left', 'left', 'left', 'right', 'right', 'right'],
            rows: [[data.from, '', 'Opening balance', '', '', '', money(data.opening)], ...data.entries.map((e: any) => [e.date, e.doc_no, e.kind, e.reference ?? '', Number(e.debit) ? money(e.debit) : '', Number(e.credit) ? money(e.credit) : '', money(e.balance)])] },
        totals: [['We owe', data.closing, true]],
    });

    return (
        <Popup visible title={`Statement — ${row.name}`} width="min(980px, 96vw)" height="86vh" onHiding={onClose} showCloseButton>
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
                    <Column {...numberColumn('debit')} />
                    <Column {...numberColumn('credit')} />
                    <Column {...numberColumn('balance')} cssClass="erp-strong" />
                </DataGrid>
            </div>
        </Popup>
    );
}

function PayDialog({ row, companyId, onClose, onDone }: { row: AgingRow; companyId?: string; onClose: () => void; onDone: () => void }) {
    const [bills, setBills] = useState<(Bill & { pay?: number })[]>([]);
    const [amount, setAmount] = useState(Number(row.balance));
    const [method, setMethod] = useState('bank');
    const [reference, setReference] = useState('');
    const [manual, setManual] = useState(false);
    const [busy, setBusy] = useState(false);
    const [key] = useState(newIdempotencyKey);
    useEffect(() => { getList<Bill>(`/suppliers/${row.supplier_id}/open-bills`).then((b) => setBills(b.map((x) => ({ ...x, pay: 0 })))).catch(notifyError); }, [row.supplier_id]);

    const auto = (total: number) => { let left = total; return bills.map((b) => { const pay = Math.min(Number(b.balance_due), Math.max(left, 0)); left -= pay; return { ...b, pay: Math.round(pay * 100) / 100 }; }); };
    const shown = manual ? bills : auto(amount);
    const allocated = shown.reduce((s, b) => s + Number(b.pay || 0), 0);

    const save = async () => {
        setBusy(true);
        try {
            await api.post(`/suppliers/${row.supplier_id}/payments`, {
                company_id: companyId, method, amount, reference: reference || null,
                ...(manual ? { allocations: bills.filter((b) => Number(b.pay) > 0).map((b) => ({ supplier_bill_id: b.id, amount: b.pay })) } : {}),
            }, idempotent(key));
            notifySuccess(`Payment of ${money(amount)} recorded.`);
            onDone();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title={`Pay ${row.name}`} width={820} height="auto" maxHeight="90vh" onHiding={onClose} showCloseButton>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1.4fr', gap: 12 }}>
                <Field label={`Amount (we owe ${money(row.balance)})`}><NumberBox min={0} max={Number(row.balance)} format="#,##0.00" value={amount} onValueChanged={(e) => setAmount(e.value ?? 0)} /></Field>
                <Field label="Paid from"><SelectBox dataSource={[{ id: 'bank', name: 'Bank / cheque' }, { id: 'cash', name: 'Cash' }]} valueExpr="id" displayExpr="name" value={method} onValueChanged={(e) => setMethod(e.value)} /></Field>
                <Field label="Cheque / transfer no."><TextBox value={reference} onValueChanged={(e) => setReference(e.value)} /></Field>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', margin: '10px 0 6px' }}>
                <span className="erp-muted">{manual ? 'Choose the bills this pays' : 'Applied to the bills due first'}</span>
                <div style={{ flex: 1 }} />
                <Button text={manual ? 'Apply automatically' : 'Choose bills'} stylingMode="text" onClick={() => { if (!manual) setBills(auto(amount)); setManual(!manual); }} />
            </div>
            <DataGrid dataSource={shown} keyExpr="id" showBorders height={240} onRowUpdated={() => setBills([...bills])}>
                <Editing mode="cell" allowUpdating={manual} />
                <Column dataField="bill_no" allowEditing={false} />
                <Column dataField="supplier_invoice_no" caption="Their invoice" allowEditing={false} />
                <Column dataField="due_date" dataType="date" allowEditing={false} width={110} />
                <Column {...numberColumn('balance_due')} caption="Open" allowEditing={false} />
                <Column dataField="pay" caption="Pay" dataType="number" format="#,##0.00" width={120} />
            </DataGrid>
            <div className="erp-actions">
                <span>Applied to bills <b>{money(allocated)}</b></span>
                <div style={{ flex: 1 }} />
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Saving…' : 'Record payment'} type="default" disabled={busy || amount <= 0 || (manual && allocated - amount > 0.004)} onClick={save} />
            </div>
        </Popup>
    );
}
