import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import Popup from 'devextreme-react/popup';
import TextBox from 'devextreme-react/text-box';
import DateBox from 'devextreme-react/date-box';
import DataGrid, { Column } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import Page, { Field } from '../components/Page';
import { api, getOne, notifyError, notifySuccess } from '../lib/api';
import { money, numberColumn, startOfMonth, today } from '../lib/format';
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
            <CrudGrid key={version} resource="/sales" params={{ from, to: `${to} 23:59:59` }} height="calc(100vh - 270px)" onRowClick={(row) => setSelected(row.id)}>
                <Column dataField="sale_no" caption="Invoice" width={140} />
                <Column dataField="sold_at" caption="Sold" dataType="datetime" width={180} />
                <Column dataField="customer.name" caption="Customer" />
                <Column dataField="status" width={120} />
                <Column {...numberColumn('discount_total')} caption="Discount" />
                <Column {...numberColumn('tax_total')} caption="Tax" />
                <Column {...numberColumn('grand_total')} caption="Total" />
            </CrudGrid>
            {selected && <SaleDetail saleId={selected} onClose={(changed) => { setSelected(null); if (changed) setVersion((v) => v + 1); }} />}
        </Page>
    );
}

function SaleDetail({ saleId, onClose }: { saleId: string; onClose: (changed: boolean) => void }) {
    const canVoid = useCan('sales.sale.void');
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

    return (
        <Popup visible title={data ? `Invoice ${data.sale_no}` : 'Invoice'} width={820} height="auto" onHiding={() => onClose(false)} showCloseButton>
            {data && (
                <>
                    <div className="erp-row">
                        <span className={`erp-status-badge ${data.status}`}>{data.status}</span>
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
                    {canVoid && data.status === 'completed' && (
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
