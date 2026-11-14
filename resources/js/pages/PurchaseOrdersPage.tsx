import { useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import DateBox from 'devextreme-react/date-box';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TextBox from 'devextreme-react/text-box';
import { Column, Lookup, RequiredRule } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import LinesGrid, { cleanLines } from '../components/LinesGrid';
import { StatusBadge, statusCell } from '../components/Status';
import Page, { Field } from '../components/Page';
import { api, getList, getOne, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { money, numberColumn, qty, today } from '../lib/format';
import { useCan } from '../lib/store';

export default function PurchaseOrdersPage() {
    const canCreate = useCan('purchasing.order.create');
    const [creating, setCreating] = useState(false);
    const [selected, setSelected] = useState<string | null>(null);
    const [version, setVersion] = useState(0);
    const reload = () => setVersion((v) => v + 1);

    return (
        <Page
            title="Purchase orders"
            subtitle="Draft → approved → received. Receiving goods adds stock and records what you owe the supplier."
            actions={canCreate && <Button text="New purchase order" icon="plus" type="default" onClick={() => setCreating(true)} />}
        >
            <CrudGrid key={version} resource="/purchase-orders" onRowClick={(row) => setSelected(row.id)}>
                <Column dataField="po_no" caption="PO no." width={140} />
                <Column dataField="order_date" caption="Ordered" dataType="date" width={130} />
                <Column dataField="expected_date" caption="Expected" dataType="date" width={130} />
                <Column dataField="supplier.name" caption="Supplier" />
                <Column dataField="status" width={170} cellRender={statusCell} />
                <Column {...numberColumn('grand_total')} caption="Total" />
            </CrudGrid>
            {creating && <OrderForm onClose={(saved) => { setCreating(false); if (saved) reload(); }} />}
            {selected && <OrderDetail orderId={selected} onClose={(changed) => { setSelected(null); if (changed) reload(); }} />}
        </Page>
    );
}

function OrderForm({ onClose }: { onClose: (saved: boolean) => void }) {
    const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: () => getList('/warehouses') });
    const suppliers = useQuery({ queryKey: ['suppliers'], queryFn: () => getList('/suppliers') });
    const products = useQuery({ queryKey: ['products'], queryFn: () => getList('/products', { limit: 1000 }) });
    const [warehouseId, setWarehouseId] = useState<string | null>(null);
    const [supplierId, setSupplierId] = useState<string | null>(null);
    const [orderDate, setOrderDate] = useState(today());
    const [expectedDate, setExpectedDate] = useState<string | null>(null);
    const [notes, setNotes] = useState('');
    const [busy, setBusy] = useState(false);
    const lines = useRef<any[]>([]);
    const key = useRef(newIdempotencyKey());

    const stocked = (products.data ?? []).filter((p: any) => p.product_type === 'goods' || p.product_type === 'composite');

    const save = async () => {
        const payload = cleanLines(lines.current).filter((l) => l.product_id && Number(l.quantity) > 0)
            .map((l) => ({ product_id: l.product_id, quantity: l.quantity, unit_cost: l.unit_cost ?? 0, ...(l.tax_rate != null ? { tax_rate: l.tax_rate } : {}) }));
        if (!warehouseId || !supplierId || payload.length === 0) {
            notifyError(new Error('Choose a supplier and a warehouse, and add at least one line.'));
            return;
        }
        setBusy(true);
        try {
            await api.post('/purchase-orders', {
                warehouse_id: warehouseId, supplier_id: supplierId, order_date: orderDate, expected_date: expectedDate, notes: notes || null, lines: payload,
            }, idempotent(key.current));
            notifySuccess('Purchase order saved as a draft');
            onClose(true);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title="New purchase order" width={960} height="auto" onHiding={() => onClose(false)} showCloseButton>
            <div className="erp-row">
                <Field label="Supplier">
                    <SelectBox width={240} dataSource={(suppliers.data ?? []).filter((s: any) => s.status === 'active')} valueExpr="id" displayExpr="name" searchEnabled value={supplierId} onValueChanged={(e) => setSupplierId(e.value)} />
                </Field>
                <Field label="Deliver to warehouse">
                    <SelectBox width={220} dataSource={warehouses.data ?? []} valueExpr="id" displayExpr="name" value={warehouseId} onValueChanged={(e) => setWarehouseId(e.value)} />
                </Field>
                <Field label="Order date"><DateBox type="date" width={150} value={orderDate} dateSerializationFormat="yyyy-MM-dd" onValueChanged={(e) => setOrderDate(e.value)} /></Field>
                <Field label="Expected"><DateBox type="date" width={150} value={expectedDate} dateSerializationFormat="yyyy-MM-dd" showClearButton onValueChanged={(e) => setExpectedDate(e.value ?? null)} /></Field>
            </div>
            <LinesGrid linesRef={lines}>
                <Column dataField="product_id" caption="Product" setCellValue={(row: any, value: string) => {
                    // Picking a product pre-fills its usual cost.
                    row.product_id = value;
                    const product = stocked.find((p: any) => p.id === value);
                    if (product) {
                        row.unit_cost = Number(product.cost_price);
                    }
                }}>
                    <Lookup dataSource={stocked} valueExpr="id" displayExpr={(p: any) => (p ? `${p.sku} — ${p.name}` : '')} />
                    <RequiredRule />
                </Column>
                <Column dataField="quantity" dataType="number" width={130}><RequiredRule /></Column>
                <Column dataField="unit_cost" caption="Unit cost" dataType="number" width={140} format={{ type: 'fixedPoint', precision: 2 }}><RequiredRule /></Column>
                <Column dataField="tax_rate" caption="Tax % (blank = product's rate)" dataType="number" width={220} />
            </LinesGrid>
            <div style={{ marginTop: 12 }}>
                <TextBox label="Notes" value={notes} onValueChanged={(e) => setNotes(e.value)} />
            </div>
            <div className="erp-actions">
                <Button text="Cancel" onClick={() => onClose(false)} />
                <Button text={busy ? 'Saving…' : 'Save draft'} type="default" disabled={busy} onClick={save} />
            </div>
        </Popup>
    );
}

function OrderDetail({ orderId, onClose }: { orderId: string; onClose: (changed: boolean) => void }) {
    const canApprove = useCan('purchasing.order.approve');
    const canReceive = useCan('purchasing.receipt.create');
    const canCancel = useCan('purchasing.order.create');
    const order = useQuery({ queryKey: ['purchase-order', orderId], queryFn: () => getOne(`/purchase-orders/${orderId}`) });
    const [receiving, setReceiving] = useState<Record<string, number>>({});
    const [batches, setBatches] = useState<Record<string, { batch_no?: string; expiry_date?: string | null }>>({});
    const [invoiceNo, setInvoiceNo] = useState('');
    const [busy, setBusy] = useState(false);
    const [changed, setChanged] = useState(false);
    const key = useRef(newIdempotencyKey());
    const data = order.data;

    const run = async (action: () => Promise<unknown>, message: string) => {
        setBusy(true);
        try {
            await action();
            notifySuccess(message);
            setChanged(true);
            setReceiving({});
            setBatches({});
            setInvoiceNo('');
            key.current = newIdempotencyKey();
            await order.refetch();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    const approve = () => run(() => api.post(`/purchase-orders/${orderId}/approve`), 'Purchase order approved');
    const cancel = () => run(() => api.post(`/purchase-orders/${orderId}/cancel`, { reason: 'Cancelled from the purchase order screen' }), 'Purchase order cancelled');
    const receive = () => {
        const lines = Object.entries(receiving).filter(([, q]) => q > 0)
            .map(([purchase_order_item_id, quantity]) => ({ purchase_order_item_id, quantity, ...(batches[purchase_order_item_id] ?? {}) }));
        if (lines.length === 0) {
            notifyError(new Error('Enter the quantity received on at least one line.'));
            return;
        }
        return run(() => api.post(`/purchase-orders/${orderId}/receive`, { lines, supplier_invoice_no: invoiceNo || null }, idempotent(key.current)), 'Goods received into stock');
    };

    const receivable = data && ['approved', 'partially_received'].includes(data.status) && canReceive;
    const remaining = (item: any) => Number(item.quantity) - Number(item.received_quantity);
    const receiveAll = () => setReceiving(Object.fromEntries((data?.items ?? []).filter((i: any) => remaining(i) > 0).map((i: any) => [i.id, remaining(i)])));

    return (
        <Popup visible title={data ? `Purchase order ${data.po_no}` : 'Purchase order'} width={920} height="auto" onHiding={() => onClose(changed)} showCloseButton>
            {data && (
                <>
                    <div className="erp-row">
                        <StatusBadge value={data.status} />
                        <span style={{ fontWeight: 600 }}>{data.supplier?.name}</span>
                        <span className="erp-muted">Ordered {data.order_date}{data.expected_date ? ` · expected ${data.expected_date}` : ''}</span>
                        {data.notes && <span className="erp-muted">{data.notes}</span>}
                    </div>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.9rem' }}>
                        <thead>
                            <tr className="erp-muted" style={{ textAlign: 'left' }}>
                                <th style={{ padding: '6px 4px' }}>Product</th>
                                <th className="erp-num">Ordered</th>
                                <th className="erp-num">Received</th>
                                <th className="erp-num">Unit cost</th>
                                <th className="erp-num">Tax %</th>
                                {receivable && <th className="erp-num" style={{ width: 150 }}>Receive now</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {data.items.map((item: any) => (
                                <tr key={item.id} style={{ borderTop: '1px solid var(--erp-line)' }}>
                                    <td style={{ padding: '8px 4px' }}>{item.product?.sku} — {item.product?.name}</td>
                                    <td className="erp-num">{qty(item.quantity)}</td>
                                    <td className="erp-num">{qty(item.received_quantity)}</td>
                                    <td className="erp-num">{money(item.unit_cost)}</td>
                                    <td className="erp-num">{qty(item.tax_rate)}</td>
                                    {receivable && (
                                        <td style={{ paddingLeft: 12 }}>
                                            <NumberBox
                                                value={receiving[item.id] ?? 0} min={0} max={remaining(item)} disabled={remaining(item) <= 0}
                                                onValueChanged={(e) => setReceiving((r) => ({ ...r, [item.id]: e.value ?? 0 }))}
                                            />
                                            {(item.product?.track_batch || item.product?.track_expiry) && remaining(item) > 0 && (
                                                <div style={{ display: 'grid', gap: 4, marginTop: 4 }}>
                                                    <TextBox placeholder="Batch no." value={batches[item.id]?.batch_no ?? ''}
                                                        onValueChanged={(e) => setBatches((b) => ({ ...b, [item.id]: { ...b[item.id], batch_no: e.value } }))} />
                                                    <DateBox type="date" placeholder={item.product?.track_expiry ? 'Expiry (required)' : 'Expiry'} dateSerializationFormat="yyyy-MM-dd"
                                                        value={batches[item.id]?.expiry_date ?? null}
                                                        onValueChanged={(e) => setBatches((b) => ({ ...b, [item.id]: { ...b[item.id], expiry_date: e.value } }))} />
                                                </div>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <div className="pos-total-row" style={{ marginTop: 12 }}><span>Subtotal</span><span>{money(data.subtotal)}</span></div>
                    <div className="pos-total-row"><span>Tax</span><span>{money(data.tax_total)}</span></div>
                    <div className="pos-total-row" style={{ fontWeight: 700 }}><span>Order total</span><span>{money(data.grand_total)}</span></div>

                    {data.receipts.length > 0 && (
                        <>
                            <div className="erp-card-title" style={{ marginTop: 16 }}>Goods receipts</div>
                            {data.receipts.map((r: any) => (
                                <div className="erp-list-row" key={r.id}>
                                    <div>
                                        <span style={{ fontWeight: 600 }}>{r.grn_no}</span>
                                        <span className="erp-muted"> · {new Date(r.received_at).toLocaleString()}{r.supplier_invoice_no ? ` · invoice ${r.supplier_invoice_no}` : ''}</span>
                                    </div>
                                    <div className="erp-num">{money(r.grand_total)}</div>
                                </div>
                            ))}
                        </>
                    )}

                    <div className="erp-actions" style={{ alignItems: 'flex-end' }}>
                        {data.status === 'draft' && canCancel && <Button text="Cancel order" stylingMode="outlined" type="danger" disabled={busy} onClick={cancel} />}
                        {data.status === 'draft' && canApprove && <Button text="Approve" type="default" disabled={busy} onClick={approve} />}
                        {receivable && (
                            <>
                                <TextBox label="Supplier invoice no." width={200} value={invoiceNo} onValueChanged={(e) => setInvoiceNo(e.value)} />
                                <Button text="Fill remaining" stylingMode="outlined" onClick={receiveAll} />
                                <Button text={busy ? 'Posting…' : 'Receive goods'} type="success" disabled={busy} onClick={receive} />
                            </>
                        )}
                    </div>
                </>
            )}
        </Popup>
    );
}
