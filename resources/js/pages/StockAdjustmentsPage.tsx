import { useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TextBox from 'devextreme-react/text-box';
import { Column, Lookup, RequiredRule } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import LinesGrid, { cleanLines } from '../components/LinesGrid';
import Page, { Field } from '../components/Page';
import { api, getList, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { numberColumn } from '../lib/format';
import { useCan } from '../lib/store';

const REASONS = [
    { id: 'opening_balance', name: 'Opening balance' },
    { id: 'count_variance', name: 'Stock count variance' },
    { id: 'damage', name: 'Damage' },
    { id: 'expiry', name: 'Expiry' },
    { id: 'other', name: 'Other' },
];

export default function StockAdjustmentsPage() {
    const canCreate = useCan('inventory.adjustment.create');
    const [open, setOpen] = useState(false);
    const [version, setVersion] = useState(0);

    return (
        <Page
            title="Stock adjustments"
            subtitle="Each adjustment moves stock and posts its journal in one step; it cannot be edited afterwards"
            actions={canCreate && <Button text="New adjustment" icon="plus" type="default" onClick={() => setOpen(true)} />}
        >
            <CrudGrid key={version} resource="/stock-adjustments">
                <Column dataField="adjustment_no" caption="No." width={140} />
                <Column dataField="posted_at" caption="Posted" dataType="datetime" width={180} />
                <Column dataField="warehouse.name" caption="Warehouse" />
                <Column dataField="reason_code" caption="Reason"><Lookup dataSource={REASONS} valueExpr="id" displayExpr="name" /></Column>
                <Column {...numberColumn('total_value')} caption="Net value" />
                <Column dataField="notes" />
            </CrudGrid>
            {open && <AdjustmentForm onClose={(saved) => { setOpen(false); if (saved) setVersion((v) => v + 1); }} />}
        </Page>
    );
}

function AdjustmentForm({ onClose }: { onClose: (saved: boolean) => void }) {
    const queryClient = useQueryClient();
    const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: () => getList('/warehouses') });
    const products = useQuery({ queryKey: ['products'], queryFn: () => getList('/products', { limit: 1000 }) });
    const [warehouseId, setWarehouseId] = useState<string | null>(null);
    const [reason, setReason] = useState('count_variance');
    const [notes, setNotes] = useState('');
    const [busy, setBusy] = useState(false);
    const lines = useRef<any[]>([]);
    const key = useRef(newIdempotencyKey()); // one key per form: a retry cannot post twice

    const save = async () => {
        const payload = cleanLines(lines.current).filter((l) => l.product_id && Number(l.qty_delta));
        if (!warehouseId || payload.length === 0) {
            notifyError(new Error('Choose a warehouse and add at least one line with a quantity.'));
            return;
        }
        setBusy(true);
        try {
            await api.post('/stock-adjustments', { warehouse_id: warehouseId, reason_code: reason, notes: notes || null, lines: payload }, idempotent(key.current));
            notifySuccess('Adjustment posted');
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            onClose(true);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title="New stock adjustment" width={860} height="auto" onHiding={() => onClose(false)} showCloseButton>
            <div className="erp-row">
                <Field label="Warehouse">
                    <SelectBox width={240} dataSource={warehouses.data ?? []} valueExpr="id" displayExpr="name" value={warehouseId} onValueChanged={(e) => setWarehouseId(e.value)} />
                </Field>
                <Field label="Reason">
                    <SelectBox width={220} dataSource={REASONS} valueExpr="id" displayExpr="name" value={reason} onValueChanged={(e) => setReason(e.value)} />
                </Field>
                <Field label="Notes">
                    <TextBox width={300} value={notes} onValueChanged={(e) => setNotes(e.value)} />
                </Field>
            </div>
            <div className="erp-muted" style={{ marginBottom: 8 }}>
                Positive quantity adds stock (unit cost defaults to the product's cost price); negative removes it at the current average cost.
            </div>
            <LinesGrid linesRef={lines}>
                <Column dataField="product_id" caption="Product">
                    <Lookup dataSource={products.data ?? []} valueExpr="id" displayExpr={(p: any) => (p ? `${p.sku} — ${p.name}` : '')} />
                    <RequiredRule />
                </Column>
                <Column dataField="qty_delta" caption="Quantity (+/−)" dataType="number" width={160}><RequiredRule /></Column>
                <Column dataField="unit_cost" caption="Unit cost (increases)" dataType="number" width={180} />
            </LinesGrid>
            <div className="erp-actions">
                <Button text="Cancel" onClick={() => onClose(false)} />
                <Button text={busy ? 'Posting…' : 'Post adjustment'} type="default" disabled={busy} onClick={save} />
            </div>
        </Popup>
    );
}
