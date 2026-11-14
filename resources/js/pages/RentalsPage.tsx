import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import DataGrid, { Column, FilterRow, SearchPanel, Summary, TotalItem } from 'devextreme-react/data-grid';
import DateBox from 'devextreme-react/date-box';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TextBox from 'devextreme-react/text-box';
import Page, { Field } from '../components/Page';
import { statusCell } from '../components/Status';
import { api, getList, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { money, numberColumn, toDateString, today } from '../lib/format';

/** Rental and hire contracts: deposits, automatic rent invoices, returns. */
export default function RentalsPage() {
    const client = useQueryClient();
    const rows = useQuery({ queryKey: ['rentals'], queryFn: () => getList<any>('/rental-contracts') });
    const [adding, setAdding] = useState(false);
    const [returning, setReturning] = useState<any | null>(null);
    const refresh = () => client.invalidateQueries({ queryKey: ['rentals'] });

    const billNow = async () => {
        try {
            const { data } = await api.post('/rental-contracts/bill-due');
            notifySuccess(`Raised ${data.data.invoiced} rent invoices${data.data.failed ? `; ${data.data.failed} failed — see the contract` : ''}.`);
            refresh();
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <Page title="Rentals" subtitle="Rent is invoiced to the customer's account automatically every night when due."
            actions={<><Button text="Invoice what is due now" icon="refresh" onClick={billNow} /><Button text="New rental" icon="add" type="default" onClick={() => setAdding(true)} /></>}>
            <div className="erp-card">
                <DataGrid dataSource={rows.data ?? []} keyExpr="id" showRowLines columnAutoWidth height="calc(100vh - 240px)">
                    <SearchPanel visible width={260} />
                    <FilterRow visible />
                    <Column dataField="contract_no" width={120} />
                    <Column dataField="customer_name" caption="Customer" />
                    <Column dataField="item_description" caption="Item" />
                    <Column dataField="serial_no" caption="Serial" width={120} />
                    <Column dataField="start_date" dataType="date" width={110} />
                    <Column dataField="billing_frequency" caption="Billed" width={90} />
                    <Column {...numberColumn('rent_amount')} caption="Rent" />
                    <Column dataField="next_bill_date" caption="Next invoice" dataType="date" width={120} />
                    <Column {...numberColumn('deposit_amount')} caption="Deposit" />
                    <Column {...numberColumn('billed')} caption="Billed so far" />
                    <Column {...numberColumn('outstanding')} caption="Unpaid" cssClass="erp-strong" />
                    <Column dataField="last_error" caption="Problem" />
                    <Column dataField="status" width={100} cellRender={statusCell} />
                    <Column caption="" width={100} cellRender={({ data }) => data.status === 'active' && <Button text="Return" stylingMode="text" onClick={() => setReturning(data)} />} />
                    <Summary><TotalItem column="outstanding" summaryType="sum" valueFormat="#,##0.00" displayFormat="{0}" /></Summary>
                </DataGrid>
            </div>
            {adding && <NewRental onClose={() => setAdding(false)} onDone={() => { setAdding(false); refresh(); }} />}
            {returning && <ReturnRental contract={returning} onClose={() => setReturning(null)} onDone={() => { setReturning(null); refresh(); }} />}
        </Page>
    );
}

function NewRental({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
    const customers = useQuery({ queryKey: ['customers'], queryFn: () => getList<any>('/customers') }).data ?? [];
    const products = useQuery({ queryKey: ['products'], queryFn: () => getList<any>('/products', { limit: 1000 }) }).data ?? [];
    const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: () => getList<any>('/warehouses') }).data ?? [];
    const [f, setF] = useState<any>({ start_date: today(), billing_frequency: 'monthly', deposit_amount: 0, deposit_method: 'cash', quantity: 1 });
    const [key] = useState(newIdempotencyKey);
    const set = (p: any) => setF((x: any) => ({ ...x, ...p }));

    const save = async () => {
        try {
            await api.post('/rental-contracts', f, idempotent(key));
            notifySuccess('Rental started. The first invoice is raised on the start date.');
            onDone();
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <Popup visible title="New rental" width={820} height="auto" maxHeight="94vh" onHiding={onClose} showCloseButton>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12 }}>
                <Field label="Customer"><SelectBox dataSource={customers} valueExpr="id" displayExpr="name" searchEnabled value={f.customer_id ?? null} onValueChanged={(e) => set({ customer_id: e.value })} /></Field>
                <Field label="From warehouse"><SelectBox dataSource={warehouses} valueExpr="id" displayExpr="name" value={f.warehouse_id ?? null} onValueChanged={(e) => set({ warehouse_id: e.value })} /></Field>
                <Field label="Rent invoiced as (service item)"><SelectBox dataSource={products.filter((p: any) => p.product_type !== 'goods')} valueExpr="id" displayExpr="name" searchEnabled value={f.rent_product_id ?? null} onValueChanged={(e) => set({ rent_product_id: e.value })} /></Field>
                <Field label="Item handed over (stocked, optional)"><SelectBox dataSource={products.filter((p: any) => p.product_type === 'goods')} valueExpr="id" displayExpr="name" searchEnabled showClearButton value={f.item_product_id ?? null}
                    onValueChanged={(e) => { const p = products.find((x: any) => x.id === e.value); set({ item_product_id: e.value, item_description: f.item_description || p?.name }); }} /></Field>
                <Field label="Item description"><TextBox value={f.item_description ?? ''} onValueChanged={(e) => set({ item_description: e.value })} /></Field>
                <Field label="Serial / registration"><TextBox value={f.serial_no ?? ''} onValueChanged={(e) => set({ serial_no: e.value })} /></Field>
                <Field label="Starts"><DateBox type="date" value={f.start_date} onValueChanged={(e) => e.value && set({ start_date: toDateString(new Date(e.value)) })} /></Field>
                <Field label="Ends (empty = until returned)"><DateBox type="date" value={f.end_date ?? null} showClearButton onValueChanged={(e) => set({ end_date: e.value ? toDateString(new Date(e.value)) : null })} /></Field>
                <Field label="Rent billed"><SelectBox dataSource={[{ id: 'daily', name: 'Daily' }, { id: 'weekly', name: 'Weekly' }, { id: 'monthly', name: 'Monthly' }]} valueExpr="id" displayExpr="name" value={f.billing_frequency} onValueChanged={(e) => set({ billing_frequency: e.value })} /></Field>
                <Field label="Rent per period (before tax)"><NumberBox min={0} format="#,##0.00" value={f.rent_amount ?? 0} onValueChanged={(e) => set({ rent_amount: e.value })} /></Field>
                <Field label="Security deposit"><NumberBox min={0} format="#,##0.00" value={f.deposit_amount} onValueChanged={(e) => set({ deposit_amount: e.value })} /></Field>
                <Field label="Deposit received by"><SelectBox dataSource={[{ id: 'cash', name: 'Cash' }, { id: 'bank', name: 'Bank' }]} valueExpr="id" displayExpr="name" value={f.deposit_method} onValueChanged={(e) => set({ deposit_method: e.value })} /></Field>
            </div>
            <div className="erp-actions"><Button text="Cancel" onClick={onClose} />
                <Button text="Start rental" type="default" disabled={!f.customer_id || !f.warehouse_id || !f.rent_product_id || !f.item_description || !(f.rent_amount > 0)} onClick={save} /></div>
        </Popup>
    );
}

function ReturnRental({ contract, onClose, onDone }: { contract: any; onClose: () => void; onDone: () => void }) {
    const [f, setF] = useState<any>({ date: today(), deduction: 0, refund_method: 'cash', notes: '' });
    const [key] = useState(newIdempotencyKey);
    const save = async () => {
        try {
            await api.post(`/rental-contracts/${contract.id}/return`, f, idempotent(key));
            notifySuccess('Returned. Rent is billed up to today and the deposit is settled.');
            onDone();
        } catch (e) {
            notifyError(e);
        }
    };
    return (
        <Popup visible title={`Return ${contract.contract_no} — ${contract.item_description}`} width={560} height="auto" onHiding={onClose} showCloseButton>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                <Field label="Returned on"><DateBox type="date" max={new Date()} value={f.date} onValueChanged={(e) => e.value && setF({ ...f, date: toDateString(new Date(e.value)) })} /></Field>
                <Field label={`Kept from deposit of ${money(contract.deposit_amount)}`}><NumberBox min={0} max={Number(contract.deposit_amount)} format="#,##0.00" value={f.deduction} onValueChanged={(e) => setF({ ...f, deduction: e.value ?? 0 })} /></Field>
                <Field label="Deposit refunded by"><SelectBox dataSource={[{ id: 'cash', name: 'Cash' }, { id: 'bank', name: 'Bank' }]} valueExpr="id" displayExpr="name" value={f.refund_method} onValueChanged={(e) => setF({ ...f, refund_method: e.value })} /></Field>
                <Field label="Condition / reason for deduction"><TextBox value={f.notes} onValueChanged={(e) => setF({ ...f, notes: e.value })} /></Field>
            </div>
            <p>Refund to customer: <b>{money(Number(contract.deposit_amount) - f.deduction)}</b></p>
            <div className="erp-actions"><Button text="Cancel" onClick={onClose} /><Button text="Confirm return" type="default" onClick={save} /></div>
        </Popup>
    );
}
