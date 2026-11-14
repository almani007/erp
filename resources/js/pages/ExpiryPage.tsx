import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import SelectBox from 'devextreme-react/select-box';
import { Column } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import Page, { Field } from '../components/Page';
import { getList } from '../lib/api';
import { numberColumn } from '../lib/format';

const WINDOWS = [
    { id: 'all', name: 'All batches in stock' },
    { id: '0', name: 'Already expired' },
    { id: '30', name: 'Expiring within 30 days' },
    { id: '90', name: 'Expiring within 90 days' },
    { id: '180', name: 'Expiring within 6 months' },
];

/** Batches in stock by expiry date. Sales take the earliest unexpired batch automatically. */
export default function ExpiryPage() {
    const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: () => getList('/warehouses') });
    const [warehouseId, setWarehouseId] = useState<string | null>(null);
    const [range, setRange] = useState('90');

    const expiryCell = ({ data }: any) => {
        if (data.days_left === null || data.days_left === undefined) {
            return <span className="erp-muted">No expiry</span>;
        }
        const days = Number(data.days_left);
        const tone = days < 0 ? 'bad' : days <= 30 ? 'warn' : 'good';
        return <span className={`erp-badge ${tone}`}>{days < 0 ? `Expired ${-days} days ago` : days === 0 ? 'Expires today' : `${days} days left`}</span>;
    };

    return (
        <Page title="Batches & expiry" subtitle="Expired stock cannot be sold. Write it off with a stock adjustment (reason: Expiry).">
            <div className="erp-row">
                <Field label="Show">
                    <SelectBox width={260} dataSource={WINDOWS} valueExpr="id" displayExpr="name" value={range} onValueChanged={(e) => setRange(e.value)} />
                </Field>
                <Field label="Warehouse">
                    <SelectBox width={240} dataSource={warehouses.data ?? []} valueExpr="id" displayExpr="name" value={warehouseId} showClearButton placeholder="All warehouses" onValueChanged={(e) => setWarehouseId(e.value ?? null)} />
                </Field>
            </div>
            <CrudGrid
                resource="/inventory/lots" name="stock-lots" height="calc(100vh - 270px)"
                params={{ warehouse_id: warehouseId ?? undefined, within_days: range === 'all' ? undefined : range }}
            >
                <Column dataField="sku" caption="SKU" width={150} />
                <Column dataField="product_name" caption="Product" />
                <Column dataField="warehouse_name" caption="Warehouse" />
                <Column dataField="batch_no" caption="Batch" width={150} />
                <Column dataField="expiry_date" caption="Expires" dataType="date" width={130} />
                <Column caption="Status" width={190} cellRender={expiryCell} allowFiltering={false} calculateSortValue="days_left" />
                <Column {...numberColumn('quantity', 3)} caption="Quantity" />
            </CrudGrid>
        </Page>
    );
}
