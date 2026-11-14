import { useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import Popup from 'devextreme-react/popup';
import TextBox from 'devextreme-react/text-box';
import CheckBox from 'devextreme-react/check-box';
import DateBox from 'devextreme-react/date-box';
import NumberBox from 'devextreme-react/number-box';
import SelectBox from 'devextreme-react/select-box';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import DataGrid, { Column } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import { StatusBadge, statusCell } from '../components/Status';
import Page, { Field } from '../components/Page';
import { api, getOne, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { money, numberColumn, qty, startOfMonth, today } from '../lib/format';
import { useCan } from '../lib/store';

export default function SalesPage() {
    const [from, setFrom] = useState(startOfMonth());
    const [to, setTo] = useState(today());
    const [selected, setSelected] = useState<string | null>(null);
    const [version, setVersion] = useState(0);

    return (
        <Page title="Sales history" subtitle="Click a sale to see its lines and payments">
            <div className="erp-row">
                <Field label="From"><DateBox type="date" value={from} dateSerializationFormat="yyyy-MM-dd" onValueChanged={(e) => setFrom(e.value)} /></Field>
                <Field label="To"><DateBox type="date" value={to} dateSerializationFormat="yyyy-MM-dd" onValueChanged={(e) => setTo(e.value)} /></Field>
            </div>
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="Sales">
            <CrudGrid key={version} resource="/sales" params={{ from, to: `${to} 23:59:59` }} height="calc(100vh - 340px)" onRowClick={(row) => setSelected(row.id)}>
                <Column dataField="sale_no" caption="Invoice" width={140} />
                <Column dataField="sold_at" caption="Sold" dataType="datetime" width={180} />
                <Column dataField="customer.name" caption="Customer" />
                <Column dataField="status" width={120} cellRender={statusCell} />
                <Column {...numberColumn('discount_total')} caption="Discount" />
                <Column {...numberColumn('tax_total')} caption="Tax" />
                <Column {...numberColumn('grand_total')} caption="Total" />
            </CrudGrid>
                </Item>
                <Item title="Returns">
                    <CrudGrid key={version} resource="/sales-returns" height="calc(100vh - 340px)">
                        <Column dataField="return_no" caption="Return" width={140} />
                        <Column dataField="returned_at" caption="When" dataType="datetime" width={180} />
                        <Column dataField="sale.sale_no" caption="Against invoice" width={160} />
                        <Column dataField="refund_method" caption="Refunded by" width={140} />
                        <Column dataField="reason" />
                        <Column {...numberColumn('grand_total')} caption="Refund" />
                    </CrudGrid>
                </Item>
            </TabPanel>
            {selected && <SaleDetail saleId={selected} onClose={(changed) => { setSelected(null); if (changed) setVersion((v) => v + 1); }} />}
        </Page>
    );
}

function SaleDetail({ saleId, onClose }: { saleId: string; onClose: (changed: boolean) => void }) {
    const canVoid = useCan('sales.sale.void');
    const canReturn = useCan('sales.return.create');
    const [returning, setReturning] = useState<Record<string, number>>({});
    const [restock, setRestock] = useState(true);
    const [refundMethod, setRefundMethod] = useState('cash');
    const [returnReason, setReturnReason] = useState('');
    const returnKey = useRef(newIdempotencyKey());
    const sale = useQuery({ queryKey: ['sale', saleId], queryFn: () => getOne(`/sales/${saleId}`) });
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const data = sale.data;

    const voidSale = async () => {
        setBusy(true);
        try {
            await api.post(`/sales/${saleId}/void`, { reason });
            notifySuccess('Sale voided: stock restored and journal reversed');
            onClose(true);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    const returnable = (item: any) => Number(item.quantity) - Number(item.returned_quantity ?? 0);
    const returnLines = Object.entries(returning).filter(([, q]) => q > 0).map(([sale_item_id, quantity]) => ({ sale_item_id, quantity, restock }));
    const hasReturns = (data?.returns?.length ?? 0) > 0;

    const submitReturn = async () => {
        setBusy(true);
        try {
            const { data: saved } = await api.post(`/sales/${saleId}/returns`, { reason: returnReason, refund_method: refundMethod, lines: returnLines }, idempotent(returnKey.current));
            notifySuccess(`Return ${saved.data.return_no} recorded — refund ${money(saved.data.grand_total)}`);
            onClose(true);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title={data ? `Invoice ${data.sale_no}` : 'Invoice'} width={820} height="auto" onHiding={() => onClose(false)} showCloseButton>
            {data && (
                <>
                    <div className="erp-row">
                        <StatusBadge value={data.status} />
                        <span className="erp-muted">{new Date(data.sold_at).toLocaleString()}</span>
                        <span className="erp-muted">{data.customer?.name ?? 'Walk-in customer'}</span>
                        {data.void_reason && <span className="erp-error">Void reason: {data.void_reason}</span>}
                    </div>
                    <DataGrid dataSource={data.items} showBorders columnAutoWidth>
                        <Column dataField="sku" caption="SKU" width={110} />
                        <Column dataField="product_name" caption="Product" />
                        <Column {...numberColumn('quantity', 3)} caption="Qty" />
                        <Column {...numberColumn('unit_price')} caption="Price" />
                        <Column {...numberColumn('discount_amount')} caption="Discount" />
                        <Column {...numberColumn('tax_amount')} caption="Tax" />
                        <Column {...numberColumn('line_total')} caption="Total" />
                    </DataGrid>
                    <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 16 }}>
                        <div>
                            {data.tenders.map((t: any) => (
                                <div key={t.id} className="erp-muted">{t.method.replace('_', ' ')}: {money(t.amount)}{t.external_reference ? ` (${t.external_reference})` : ''}</div>
                            ))}
                            {Number(data.change_total) > 0 && <div className="erp-muted">Change given: {money(data.change_total)}</div>}
                        </div>
                        <div style={{ fontSize: '1.25rem', fontWeight: 600 }}>Total {money(data.grand_total)} {data.currency}</div>
                    </div>
                    {(data.returns ?? []).map((r: any) => (
                        <div className="erp-list-row" key={r.id}>
                            <div><span style={{ fontWeight: 600 }}>{r.return_no}</span><span className="erp-muted"> · {new Date(r.returned_at).toLocaleString()} · {r.reason}</span></div>
                            <div className="erp-num">−{money(r.grand_total)}</div>
                        </div>
                    ))}
                    {canReturn && data.status === 'completed' && data.items.some((i: any) => returnable(i) > 0) && (
                        <div className="erp-card" style={{ marginTop: 20, boxShadow: 'none' }}>
                            <div className="erp-card-title">Return items</div>
                            {data.items.filter((i: any) => returnable(i) > 0).map((item: any) => (
                                <div className="erp-list-row" key={item.id} style={{ alignItems: 'center' }}>
                                    <div>{item.product_name} <span className="erp-muted">({qty(returnable(item))} can be returned)</span></div>
                                    <NumberBox width={120} value={returning[item.id] ?? 0} min={0} max={returnable(item)} onValueChanged={(e) => setReturning((r) => ({ ...r, [item.id]: e.value ?? 0 }))} />
                                </div>
                            ))}
                            <div className="erp-row" style={{ marginTop: 12, marginBottom: 0 }}>
                                <SelectBox
                                    label="Refund by" width={190} value={refundMethod} valueExpr="id" displayExpr="name" onValueChanged={(e) => setRefundMethod(e.value)}
                                    dataSource={[{ id: 'cash', name: 'Cash (from my shift)' }, { id: 'bank', name: 'Bank' }, ...(data.customer ? [{ id: 'account', name: 'Credit to account' }] : [])]}
                                />
                                <TextBox label="Reason (required)" width={260} value={returnReason} valueChangeEvent="input" onValueChanged={(e) => setReturnReason(e.value)} />
                                <CheckBox text="Put back into stock" value={restock} onValueChanged={(e) => setRestock(!!e.value)} />
                                <Button text={busy ? 'Posting…' : 'Record return'} type="default" disabled={busy || returnLines.length === 0 || returnReason.trim().length < 3} onClick={submitReturn} />
                            </div>
                        </div>
                    )}
                    {canVoid && data.status === 'completed' && !hasReturns && (
                        <div className="erp-row" style={{ marginTop: 24, marginBottom: 0 }}>
                            <Field label="Reason for voiding (required)">
                                <TextBox width={420} value={reason} valueChangeEvent="input" onValueChanged={(e) => setReason(e.value)} />
                            </Field>
                            <Button text={busy ? 'Voiding…' : 'Void sale'} type="danger" disabled={busy || reason.trim().length < 3} onClick={voidSale} />
                        </div>
                    )}
                </>
            )}
        </Popup>
    );
}
