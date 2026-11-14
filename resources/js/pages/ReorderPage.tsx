import { useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import DataGrid, { Column, Editing, Selection } from 'devextreme-react/data-grid';
import SelectBox from 'devextreme-react/select-box';
import Page, { Field } from '../components/Page';
import { api, errorMessage, getList, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { numberColumn, today } from '../lib/format';
import { useCan } from '../lib/store';

/** What is running low, and a one-click draft purchase order for it. */
export default function ReorderPage() {
    const canOrder = useCan('purchasing.order.create');
    const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: () => getList('/warehouses') });
    const suppliers = useQuery({ queryKey: ['suppliers'], queryFn: () => getList('/suppliers') });
    const [warehouseId, setWarehouseId] = useState<string | null>(null);
    const [supplierId, setSupplierId] = useState<string | null>(null);
    const [selected, setSelected] = useState<any[]>([]);
    const [busy, setBusy] = useState(false);
    const key = useRef(newIdempotencyKey());

    const suggestions = useQuery({
        queryKey: ['reorder', warehouseId],
        queryFn: async () => (await getList('/purchasing/reorder-suggestions', { warehouse_id: warehouseId }))
            .map((row: any) => ({ ...row, on_hand: Number(row.on_hand), on_order: Number(row.on_order), reorder_level: Number(row.reorder_level), cost_price: Number(row.cost_price), order_qty: Number(row.suggested_qty) })),
        enabled: !!warehouseId,
    });

    const createOrder = async () => {
        const lines = selected.filter((r) => r.order_qty > 0).map((r) => ({ product_id: r.id, quantity: r.order_qty, unit_cost: r.cost_price }));
        if (lines.length === 0) {
            notifyError(new Error('Tick at least one product with a quantity to order.'));
            return;
        }
        setBusy(true);
        try {
            const { data } = await api.post('/purchase-orders', { warehouse_id: warehouseId, supplier_id: supplierId, order_date: today(), lines }, idempotent(key.current));
            key.current = newIdempotencyKey();
            notifySuccess(`Draft purchase order ${data.data.po_no} created — review and approve it under Purchase orders`);
            setSelected([]);
            suggestions.refetch();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Page title="Reorder suggestions" subtitle="Products at or below their reorder level, after counting what is already on order">
            <div className="erp-row">
                <Field label="Warehouse">
                    <SelectBox width={240} dataSource={warehouses.data ?? []} valueExpr="id" displayExpr="name" value={warehouseId} placeholder="Choose a warehouse" onValueChanged={(e) => { setWarehouseId(e.value); setSelected([]); }} />
                </Field>
                {canOrder && (
                    <>
                        <Field label="Order from supplier">
                            <SelectBox width={240} dataSource={(suppliers.data ?? []).filter((s: any) => s.status === 'active')} valueExpr="id" displayExpr="name" searchEnabled value={supplierId} onValueChanged={(e) => setSupplierId(e.value)} />
                        </Field>
                        <Button text={busy ? 'Creating…' : `Create draft PO (${selected.length})`} type="default" disabled={busy || !supplierId || selected.length === 0} onClick={createOrder} />
                    </>
                )}
            </div>
            {suggestions.error && <div className="erp-card erp-error">{errorMessage(suggestions.error)}</div>}
            <div className="erp-card">
                <DataGrid
                    dataSource={suggestions.data ?? []} keyExpr="id" height="calc(100vh - 290px)" showRowLines showColumnLines={false} showBorders={false}
                    noDataText={warehouseId ? 'Nothing needs reordering in this warehouse.' : 'Choose a warehouse to see what is running low.'}
                    selectedRowKeys={selected.map((r) => r.id)} onSelectionChanged={(e) => setSelected(e.selectedRowsData)}
                >
                    <Selection mode="multiple" showCheckBoxesMode="always" />
                    <Editing mode="cell" allowUpdating />
                    <Column dataField="sku" caption="SKU" width={140} allowEditing={false} />
                    <Column dataField="name" caption="Product" allowEditing={false} />
                    <Column dataField="on_hand" caption="On hand" dataType="number" width={110} allowEditing={false} />
                    <Column dataField="on_order" caption="On order" dataType="number" width={110} allowEditing={false} />
                    <Column dataField="reorder_level" caption="Reorder at" dataType="number" width={120} allowEditing={false} />
                    <Column dataField="order_qty" caption="Order qty (editable)" dataType="number" width={180} />
                    <Column {...numberColumn('cost_price')} caption="Unit cost" allowEditing={false} />
                </DataGrid>
            </div>
        </Page>
    );
}
