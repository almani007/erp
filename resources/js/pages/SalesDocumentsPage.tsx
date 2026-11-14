import { useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import DataGrid, { Column, Editing, FilterRow, Lookup, RequiredRule, SearchPanel, Summary, TotalItem } from 'devextreme-react/data-grid';
import DateBox from 'devextreme-react/date-box';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import TextArea from 'devextreme-react/text-area';
import TextBox from 'devextreme-react/text-box';
import Page, { Field } from '../components/Page';
import { statusCell } from '../components/Status';
import { api, getList, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { useAppConfig } from '../lib/config';
import { money, numberColumn, qty, toDateString, today } from '../lib/format';
import { printDocument } from '../lib/print';
import { can, useSession } from '../lib/store';

type DocType = 'quotation' | 'order';
interface Customer { id: string; customer_code: string; name: string; phone?: string | null; email?: string | null; tax_no?: string | null; address?: string | null; payment_days?: number; price_tier?: string; credit_limit?: string; balance?: string }
interface Product { id: string; sku: string; name: string; sale_price?: string; track_serial?: boolean }
interface DocLine { id?: string; line_no?: number; product_id: string; product?: Product; description?: string | null; quantity: string | number; unit_price?: string | number | null; discount?: string | number | null; tax_amount?: string; line_total?: string; invoiced_quantity?: string }
interface Doc {
    id: string; doc_type: DocType; doc_no: string; status: string; doc_date: string; valid_until: string | null; delivery_date: string | null;
    payment_days: number; price_tier: string | null; reference: string | null; notes: string | null; terms: string | null;
    warehouse_id: string; branch_id: string; customer_id: string; customer?: Customer; subtotal: string; discount_total: string; tax_total: string; grand_total: string;
    lines?: DocLine[]; invoices?: { id: string; sale_no: string; sold_at: string; grand_total: string; balance_due: string; due_date: string | null; status: string }[];
}

const LABEL: Record<DocType, string> = { quotation: 'Quotation', order: 'Sales order' };

/**
 * Quotations and sales orders for trade and wholesale customers. An order
 * is invoiced in one go or in parts; each invoice takes stock out, books
 * the sale, and is due after the agreed credit days.
 */
export default function SalesDocumentsPage() {
    return (
        <Page title="Quotations & sales orders" subtitle="Quote, confirm, deliver and invoice on account — with credit terms and printable documents.">
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="Sales orders"><DocList type="order" /></Item>
                <Item title="Quotations"><DocList type="quotation" /></Item>
                {can('organization.settings.manage') && <Item title="Print settings"><PrintSettings /></Item>}
            </TabPanel>
        </Page>
    );
}

function useLookups() {
    const customers = useQuery({ queryKey: ['customers'], queryFn: () => getList<Customer>('/customers') });
    const products = useQuery({ queryKey: ['products'], queryFn: () => getList<Product>('/products', { limit: 1000 }) });
    const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: () => getList<{ id: string; name: string; branch_id: string }>('/warehouses') });
    return { customers: customers.data ?? [], products: products.data ?? [], warehouses: warehouses.data ?? [] };
}

function DocList({ type }: { type: DocType }) {
    const client = useQueryClient();
    const docs = useQuery({ queryKey: ['sales-documents', type], queryFn: () => getList<Doc>('/sales-documents', { type }) });
    const [open, setOpen] = useState<Doc | 'new' | null>(null);
    const canManage = can('sales.order.manage');

    const openDoc = async (id: string) => {
        try {
            const { data } = await api.get(`/sales-documents/${id}`);
            setOpen(data.data);
        } catch (e) {
            notifyError(e);
        }
    };
    const refresh = () => client.invalidateQueries({ queryKey: ['sales-documents'] });

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                {canManage && <Button text={`New ${LABEL[type].toLowerCase()}`} icon="add" type="default" onClick={() => setOpen('new')} />}
            </div>
            <DataGrid dataSource={docs.data ?? []} keyExpr="id" showRowLines hoverStateEnabled columnAutoWidth height="calc(100vh - 290px)"
                onRowClick={(e) => e.rowType === 'data' && openDoc(e.data.id)}>
                <SearchPanel visible width={260} />
                <FilterRow visible />
                <Column dataField="doc_no" caption="No." width={130} />
                <Column dataField="doc_date" caption="Date" dataType="date" width={110} sortOrder="desc" />
                <Column caption="Customer" calculateCellValue={(d: Doc) => d.customer?.name} />
                <Column dataField="reference" caption="Customer ref." />
                {type === 'quotation'
                    ? <Column dataField="valid_until" caption="Valid until" dataType="date" width={110} />
                    : <Column dataField="delivery_date" caption="Delivery" dataType="date" width={110} />}
                <Column {...numberColumn('grand_total')} caption="Total" />
                <Column dataField="status" width={140} cellRender={statusCell} />
                <Summary><TotalItem column="grand_total" summaryType="sum" valueFormat="#,##0.00" displayFormat="{0}" /></Summary>
            </DataGrid>
            {open && <DocEditor type={type} doc={open === 'new' ? null : open} onClose={() => setOpen(null)}
                onChanged={(d) => { refresh(); if (d) setOpen(d); }} />}
        </div>
    );
}

function DocEditor({ type, doc, onClose, onChanged }: { type: DocType; doc: Doc | null; onClose: () => void; onChanged: (d: Doc | null) => void }) {
    const { customers, products, warehouses } = useLookups();
    const { config } = useAppConfig();
    const branches = useSession((s) => s.branches);
    const kind = doc?.doc_type ?? type;
    const editable = !doc || (kind === 'quotation' ? ['draft', 'sent'].includes(doc.status) : doc.status === 'open' && !(doc.invoices ?? []).some((i) => i.status === 'completed'));
    const canManage = can('sales.order.manage');

    const [h, setH] = useState(() => ({
        customer_id: doc?.customer_id ?? null as string | null,
        warehouse_id: doc?.warehouse_id ?? null as string | null,
        doc_date: doc?.doc_date ?? today(),
        valid_until: doc?.valid_until ?? (kind === 'quotation' ? toDateString(new Date(Date.now() + 15 * 864e5)) : null),
        delivery_date: doc?.delivery_date ?? null,
        payment_days: doc?.payment_days ?? 0,
        price_tier: doc?.price_tier ?? null as string | null,
        reference: doc?.reference ?? '',
        notes: doc?.notes ?? '',
        terms: doc?.terms ?? (kind === 'quotation' ? config?.print?.quotation_terms : config?.print?.invoice_terms) ?? '',
    }));
    const [lines, setLines] = useState<DocLine[]>(() => (doc?.lines ?? []).map((l) => ({
        product_id: l.product_id, quantity: Number(l.quantity), unit_price: Number(l.unit_price), discount: Number(l.discount ?? 0), description: l.description,
    })));
    const [busy, setBusy] = useState(false);
    const [invoicing, setInvoicing] = useState(false);
    const set = (patch: Partial<typeof h>) => setH((x) => ({ ...x, ...patch }));
    const customer = customers.find((c) => c.id === h.customer_id);

    const estimate = useMemo(() => lines.reduce((s, l) => {
        const price = l.unit_price ?? Number(products.find((p) => p.id === l.product_id)?.sale_price ?? 0);
        return s + Number(price) * Number(l.quantity || 0) - Number(l.discount || 0);
    }, 0), [lines, products]);

    const run = async (fn: () => Promise<{ data: { data: Doc } }>, msg: string) => {
        setBusy(true);
        try {
            const { data } = await fn();
            notifySuccess(msg);
            onChanged(data.data);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    const save = () => run(() => {
        const body = { ...h, doc_type: kind, lines: lines.map((l) => ({ ...l, unit_price: l.unit_price === null || l.unit_price === undefined || l.unit_price === '' ? null : l.unit_price })) };
        return doc ? api.put(`/sales-documents/${doc.id}`, body) : api.post('/sales-documents', body);
    }, `${LABEL[kind]} saved.`);

    const print = () => {
        if (!doc) return;
        printDocument({
            title: LABEL[kind], number: doc.doc_no, company: config?.company,
            branch: branches.find((b) => b.id === doc.branch_id)?.name, currency: config?.company?.base_currency,
            party: { name: doc.customer?.name ?? '', code: doc.customer?.customer_code, phone: doc.customer?.phone, address: doc.customer?.address, tax_no: doc.customer?.tax_no },
            meta: [['Date', doc.doc_date], kind === 'quotation' ? ['Valid until', doc.valid_until] : ['Delivery', doc.delivery_date],
                ['Payment terms', doc.payment_days ? `${doc.payment_days} days` : 'On delivery'], ['Your reference', doc.reference]],
            lines: (doc.lines ?? []).map((l) => ({ description: l.description || l.product?.name || '', sku: l.product?.sku, quantity: l.quantity,
                unit_price: l.unit_price ?? 0, discount: l.discount ?? 0, tax: l.tax_amount, total: l.line_total ?? 0 })),
            totals: [['Subtotal', doc.subtotal], ['Discount', doc.discount_total], ['Sales tax', doc.tax_total], ['Total', doc.grand_total, true]],
            notes: doc.notes, terms: doc.terms,
        });
    };

    const title = doc ? `${LABEL[kind]} ${doc.doc_no}` : `New ${LABEL[kind].toLowerCase()}`;

    return (
        <Popup visible title={title} width="min(1180px, 98vw)" height="94vh" onHiding={onClose} showCloseButton>
            <div style={{ display: 'flex', flexDirection: 'column', height: '100%', gap: 8 }}>
                {doc && <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>{statusCell({ value: doc.status })}
                    {customer && <span className="erp-muted">Credit limit {money(customer.credit_limit)} · owes {money(customer.balance)}</span>}</div>}
                <div style={{ display: 'grid', gridTemplateColumns: '2fr 1.2fr 1fr 1fr 0.8fr', gap: 12 }}>
                    <Field label="Customer">
                        <SelectBox dataSource={customers} valueExpr="id" displayExpr={(c: Customer) => (c ? `${c.name} (${c.customer_code})` : '')} searchEnabled
                            disabled={!editable} value={h.customer_id}
                            onValueChanged={(e) => { const c = customers.find((x) => x.id === e.value); set({ customer_id: e.value, payment_days: c?.payment_days ?? h.payment_days }); }} />
                    </Field>
                    <Field label="Deliver from">
                        <SelectBox dataSource={warehouses} valueExpr="id" displayExpr="name" disabled={!editable} value={h.warehouse_id} onValueChanged={(e) => set({ warehouse_id: e.value })} />
                    </Field>
                    <Field label="Date"><DateBox type="date" disabled={!editable} value={h.doc_date} onValueChanged={(e) => e.value && set({ doc_date: toDateString(new Date(e.value)) })} /></Field>
                    {kind === 'quotation'
                        ? <Field label="Valid until"><DateBox type="date" disabled={!editable} value={h.valid_until} onValueChanged={(e) => set({ valid_until: e.value ? toDateString(new Date(e.value)) : null })} /></Field>
                        : <Field label="Delivery date"><DateBox type="date" disabled={!editable} value={h.delivery_date} showClearButton onValueChanged={(e) => set({ delivery_date: e.value ? toDateString(new Date(e.value)) : null })} /></Field>}
                    <Field label="Credit days"><NumberBox min={0} max={365} disabled={!editable} value={h.payment_days} onValueChanged={(e) => set({ payment_days: e.value ?? 0 })} /></Field>
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 2fr', gap: 12 }}>
                    <Field label="Price list">
                        <SelectBox dataSource={[{ id: 'retail', name: 'Retail' }, { id: 'wholesale', name: 'Wholesale' }]} valueExpr="id" displayExpr="name"
                            placeholder="Customer's own" showClearButton disabled={!editable} value={h.price_tier} onValueChanged={(e) => set({ price_tier: e.value })} />
                    </Field>
                    <Field label="Customer reference / PO no."><TextBox disabled={!editable} value={h.reference} onValueChanged={(e) => set({ reference: e.value })} /></Field>
                    <Field label="Notes"><TextBox disabled={!editable} value={h.notes} onValueChanged={(e) => set({ notes: e.value })} /></Field>
                </div>

                <DataGrid dataSource={editable ? lines : (doc?.lines ?? [])} showBorders columnAutoWidth style={{ flex: 1, minHeight: 200 }} height="100%"
                    onRowInserted={() => setLines([...lines])} onRowUpdated={() => setLines([...lines])} onRowRemoved={() => setLines([...lines])}>
                    <Editing mode="cell" allowAdding={editable} allowUpdating={editable} allowDeleting={editable} newRowPosition="last" />
                    <Column dataField="product_id" caption="Product" minWidth={260}>
                        <Lookup dataSource={products} valueExpr="id" displayExpr={(p: Product) => (p ? `${p.sku} — ${p.name}` : '')} />
                        <RequiredRule />
                    </Column>
                    <Column dataField="description" caption="Description (optional)" />
                    <Column dataField="quantity" caption="Qty" dataType="number" width={90}><RequiredRule /></Column>
                    <Column dataField="unit_price" caption="Rate" dataType="number" width={110} format="#,##0.00" editorOptions={{ placeholder: 'price list' }} />
                    <Column dataField="discount" caption="Discount" dataType="number" width={100} format="#,##0.00" />
                    {!editable && <Column {...numberColumn('tax_amount')} caption="Tax" width={100} />}
                    {!editable && <Column {...numberColumn('line_total')} caption="Amount" width={120} />}
                    {!editable && kind === 'order' && <Column caption="Invoiced" width={90} calculateCellValue={(l: DocLine) => qty(l.invoiced_quantity)} />}
                </DataGrid>

                {editable && <Field label="Terms & conditions (printed)"><TextArea height={54} value={h.terms ?? ''} onValueChanged={(e) => set({ terms: e.value })} /></Field>}

                {doc?.invoices && doc.invoices.length > 0 && (
                    <div className="erp-muted">Invoices: {doc.invoices.map((i) => `${i.sale_no} (${money(i.grand_total)}${i.status !== 'completed' ? `, ${i.status}` : Number(i.balance_due) > 0 ? `, due ${money(i.balance_due)}` : ', paid'})`).join(' · ')}</div>
                )}

                <div className="erp-actions" style={{ marginTop: 0 }}>
                    <span>{doc && !editable
                        ? <>Subtotal {money(doc.subtotal)} · Discount {money(doc.discount_total)} · Tax {money(doc.tax_total)} · <b>Total {money(doc.grand_total)}</b></>
                        : doc ? <>Saved total <b>{money(doc.grand_total)}</b> · tax and deals are worked out on save</> : <>Estimate before tax <b>{money(estimate)}</b></>}</span>
                    <div style={{ flex: 1 }} />
                    {doc && <Button text="Print / PDF" icon="print" onClick={print} />}
                    {canManage && doc && kind === 'quotation' && doc.status === 'draft' && <Button text="Mark sent" disabled={busy} onClick={() => run(() => api.post(`/sales-documents/${doc.id}/status`, { status: 'sent' }), 'Marked as sent.')} />}
                    {canManage && doc && kind === 'quotation' && ['draft', 'sent'].includes(doc.status) && <Button text="Accepted" disabled={busy} onClick={() => run(() => api.post(`/sales-documents/${doc.id}/status`, { status: 'accepted' }), 'Marked as accepted.')} />}
                    {canManage && doc && kind === 'quotation' && ['draft', 'sent', 'accepted'].includes(doc.status) && <Button text="Rejected" stylingMode="outlined" disabled={busy} onClick={() => run(() => api.post(`/sales-documents/${doc.id}/status`, { status: 'rejected' }), 'Marked as rejected.')} />}
                    {canManage && doc && kind === 'quotation' && ['draft', 'sent', 'accepted'].includes(doc.status) && <Button text="Convert to order" type="success" disabled={busy} onClick={() => run(() => api.post(`/sales-documents/${doc.id}/convert`), 'Sales order created.')} />}
                    {canManage && doc && kind === 'order' && doc.status === 'open' && <Button text="Cancel order" stylingMode="outlined" type="danger" disabled={busy} onClick={() => run(() => api.post(`/sales-documents/${doc.id}/cancel`), 'Order cancelled.')} />}
                    {can('sales.invoice.create') && doc && kind === 'order' && ['open', 'partly_invoiced'].includes(doc.status) && <Button text="Create invoice" type="success" icon="money" onClick={() => setInvoicing(true)} />}
                    {canManage && editable && <Button text={busy ? 'Saving…' : 'Save'} type="default" disabled={busy || !h.customer_id || !h.warehouse_id || lines.length === 0} onClick={save} />}
                </div>
            </div>
            {invoicing && doc && <InvoiceDialog order={doc} onClose={() => setInvoicing(false)} onDone={async () => {
                setInvoicing(false);
                const { data } = await api.get(`/sales-documents/${doc.id}`);
                onChanged(data.data);
            }} />}
        </Popup>
    );
}

function InvoiceDialog({ order, onClose, onDone }: { order: Doc; onClose: () => void; onDone: () => void }) {
    const { config } = useAppConfig();
    const branches = useSession((s) => s.branches);
    const [rows, setRows] = useState(() => (order.lines ?? [])
        .map((l) => ({ line_id: l.id as string, name: l.product?.name ?? '', track_serial: !!l.product?.track_serial,
            remaining: Number(l.quantity) - Number(l.invoiced_quantity ?? 0), quantity: Number(l.quantity) - Number(l.invoiced_quantity ?? 0), serials: '' }))
        .filter((r) => r.remaining > 0));
    const [paidNow, setPaidNow] = useState(0);
    const [paidBy, setPaidBy] = useState('cash');
    const [busy, setBusy] = useState(false);
    const [key] = useState(newIdempotencyKey);

    const submit = async () => {
        setBusy(true);
        try {
            const { data } = await api.post(`/sales-documents/${order.id}/invoice`, {
                lines: rows.filter((r) => r.quantity > 0).map((r) => ({
                    line_id: r.line_id, quantity: r.quantity,
                    serials: r.track_serial ? r.serials.split(/[\s,]+/).filter(Boolean) : undefined,
                })),
                ...(paidNow > 0 ? { paid_now: paidNow, paid_by: paidBy } : {}),
            }, idempotent(key));
            const sale = data.data;
            if (sale.status !== 'pending_approval') {
                notifySuccess(`Invoice ${sale.sale_no} created.`);
                printInvoice(sale, order, config, branches.find((b) => b.id === order.branch_id)?.name);
            }
            onDone();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title={`Invoice ${order.doc_no}`} width={760} height="auto" maxHeight="90vh" onHiding={onClose} showCloseButton>
            <DataGrid dataSource={rows} keyExpr="line_id" showBorders onRowUpdated={() => setRows([...rows])}>
                <Editing mode="cell" allowUpdating />
                <Column dataField="name" caption="Item" allowEditing={false} />
                <Column dataField="remaining" caption="Open qty" width={90} allowEditing={false} />
                <Column dataField="quantity" caption="Invoice qty" dataType="number" width={110} />
                <Column dataField="serials" caption="Serials / IMEI (if tracked)" cellRender={({ data, value }) => (data.track_serial ? (value || <span className="erp-muted">required</span>) : <span className="erp-muted">—</span>)} />
            </DataGrid>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginTop: 10 }}>
                <Field label="Received now (rest goes on account)"><NumberBox min={0} format="#,##0.00" value={paidNow} onValueChanged={(e) => setPaidNow(e.value ?? 0)} /></Field>
                <Field label="Received by">
                    <SelectBox dataSource={[{ id: 'cash', name: 'Cash' }, { id: 'bank_transfer', name: 'Bank transfer' }, { id: 'card', name: 'Card' }, { id: 'wallet', name: 'Wallet' }]}
                        valueExpr="id" displayExpr="name" value={paidBy} disabled={!paidNow} onValueChanged={(e) => setPaidBy(e.value)} />
                </Field>
            </div>
            <div className="erp-actions">
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Invoicing…' : 'Create invoice & print'} type="default" disabled={busy || !rows.some((r) => r.quantity > 0)} onClick={submit} />
            </div>
        </Popup>
    );
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function printInvoice(sale: any, order: Doc | null, config: any, branch?: string) {
    const customer = order?.customer ?? sale.customer ?? {};
    printDocument({
        title: 'Sales tax invoice', number: sale.sale_no, company: config?.company, branch, currency: sale.currency,
        party: { name: customer.name ?? 'Walk-in customer', code: customer.customer_code, phone: customer.phone, address: customer.address, tax_no: customer.tax_no },
        meta: [['Invoice date', String(sale.sold_at ?? '').slice(0, 10)], ['Due date', sale.due_date], ['Order', order?.doc_no], ['Your reference', sale.reference]],
        lines: (sale.items ?? []).map((i: any) => ({ description: i.product_name, sku: i.sku, quantity: i.quantity, unit_price: i.unit_price,
            discount: i.discount_amount, tax: i.tax_amount, total: i.line_total })),
        totals: [['Subtotal', sale.subtotal], ['Discount', sale.discount_total], ['Sales tax', sale.tax_total], ['Invoice total', sale.grand_total, true],
            ['Received', Number(sale.grand_total) - Number(sale.balance_due ?? 0)], ['Balance due', sale.balance_due ?? 0]],
        notes: sale.notes, terms: config?.print?.invoice_terms,
    });
}

/** Terms printed on quotations and invoices. */
function PrintSettings() {
    const { config, refetch } = useAppConfig();
    const [quote, setQuote] = useState<string>(config?.print?.quotation_terms ?? '');
    const [invoice, setInvoice] = useState<string>(config?.print?.invoice_terms ?? '');
    const save = async () => {
        try {
            await api.put('/settings', { group: 'sales', key: 'quotation_terms', value: quote });
            await api.put('/settings', { group: 'sales', key: 'invoice_terms', value: invoice });
            notifySuccess('Print settings saved.');
            refetch();
        } catch (e) {
            notifyError(e);
        }
    };
    return (
        <div className="erp-card" style={{ maxWidth: 820 }}>
            <Field label="Quotation terms & conditions"><TextArea height={110} value={quote} onValueChanged={(e) => setQuote(e.value)} /></Field>
            <Field label="Invoice terms & conditions"><TextArea height={110} value={invoice} onValueChanged={(e) => setInvoice(e.value)} /></Field>
            <div className="erp-muted">The company name and NTN on documents come from Administration → Business & branches.</div>
            <div className="erp-actions"><Button text="Save" type="default" onClick={save} /></div>
        </div>
    );
}
