import { useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TextBox from 'devextreme-react/text-box';
import { Column, Lookup, RequiredRule } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import LinesGrid, { cleanLines } from '../components/LinesGrid';
import { statusCell } from '../components/Status';
import Page, { Field } from '../components/Page';
import { api, getList, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { useCan } from '../lib/store';

export default function StockTransfersPage() {
    const canManage = useCan('inventory.transfer.manage');
    const [open, setOpen] = useState(false);
    const [version, setVersion] = useState(0);
    const reload = () => setVersion((v) => v + 1);

    const receive = async (id: string) => {
        try {
            await api.post(`/stock-transfers/${id}/receive`);
            notifySuccess('Transfer received into the destination warehouse');
            reload();
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <Page
            title="Stock transfers"
            subtitle="Dispatched stock is in transit until the destination confirms receipt"
            actions={canManage && <Button text="New transfer" icon="plus" type="default" onClick={() => setOpen(true)} />}
        >
            <CrudGrid key={version} resource="/stock-transfers">
                <Column dataField="transfer_no" caption="No." width={140} />
                <Column dataField="source.name" caption="From" />
                <Column dataField="destination.name" caption="To" />
                <Column dataField="status" width={130} cellRender={statusCell} />
                <Column dataField="dispatched_at" caption="Dispatched" dataType="datetime" width={180} />
                <Column dataField="received_at" caption="Received" dataType="datetime" width={180} />
                <Column caption="Items" width={90} calculateCellValue={(row: any) => row.lines?.length ?? 0} alignment="right" />
                <Column
                    caption="" width={130} allowSorting={false} allowFiltering={false}
                    cellRender={({ data }: any) => (data.status === 'in_transit' && canManage
                        ? <Button text="Receive" stylingMode="outlined" onClick={() => receive(data.id)} />
                        : null)}
                />
            </CrudGrid>
            {open && <TransferForm onClose={(saved) => { setOpen(false); if (saved) reload(); }} />}
        </Page>
    );
}

function TransferForm({ onClose }: { onClose: (saved: boolean) => void }) {
    const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: () => getList('/warehouses') });
    const products = useQuery({ queryKey: ['products'], queryFn: () => getList('/products', { limit: 1000 }) });
    const [sourceId, setSourceId] = useState<string | null>(null);
    const [destinationId, setDestinationId] = useState<string | null>(null);
    const [notes, setNotes] = useState('');
    const [busy, setBusy] = useState(false);
    const lines = useRef<any[]>([]);
    const key = useRef(newIdempotencyKey());

    const save = async () => {
        const payload = cleanLines(lines.current).filter((l) => l.product_id && Number(l.quantity) > 0);
        if (!sourceId || !destinationId || payload.length === 0) {
            notifyError(new Error('Choose both warehouses and add at least one line.'));
            return;
        }
        setBusy(true);
        try {
            await api.post('/stock-transfers', {
                source_warehouse_id: sourceId, destination_warehouse_id: destinationId, notes: notes || null, lines: payload,
            }, idempotent(key.current));
            notifySuccess('Transfer dispatched');
            onClose(true);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title="New stock transfer" width={820} height="auto" onHiding={() => onClose(false)} showCloseButton>
            <div className="erp-row">
                <Field label="From warehouse">
                    <SelectBox width={230} dataSource={warehouses.data ?? []} valueExpr="id" displayExpr="name" value={sourceId} onValueChanged={(e) => setSourceId(e.value)} />
                </Field>
                <Field label="To warehouse">
                    <SelectBox width={230} dataSource={warehouses.data ?? []} valueExpr="id" displayExpr="name" value={destinationId} onValueChanged={(e) => setDestinationId(e.value)} />
                </Field>
                <Field label="Notes">
                    <TextBox width={260} value={notes} onValueChanged={(e) => setNotes(e.value)} />
                </Field>
            </div>
            <LinesGrid linesRef={lines}>
                <Column dataField="product_id" caption="Product">
                    <Lookup dataSource={products.data ?? []} valueExpr="id" displayExpr={(p: any) => (p ? `${p.sku} — ${p.name}` : '')} />
                    <RequiredRule />
                </Column>
                <Column dataField="quantity" dataType="number" width={160}><RequiredRule /></Column>
            </LinesGrid>
            <div className="erp-actions">
                <Button text="Cancel" onClick={() => onClose(false)} />
                <Button text={busy ? 'Dispatching…' : 'Dispatch'} type="default" disabled={busy} onClick={save} />
            </div>
        </Popup>
    );
}
