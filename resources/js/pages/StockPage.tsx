import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import SelectBox from 'devextreme-react/select-box';
import CheckBox from 'devextreme-react/check-box';
import { Column, Summary, TotalItem } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import Page, { Field } from '../components/Page';
import { getList } from '../lib/api';
import { numberColumn } from '../lib/format';

const GRID_HEIGHT = 'calc(100vh - 320px)';

export default function StockPage() {
    const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: () => getList('/warehouses') });
    const [warehouseId, setWarehouseId] = useState<string | null>(null);
    const [lowOnly, setLowOnly] = useState(false);

    const params = { warehouse_id: warehouseId ?? undefined, low_stock: lowOnly ? 1 : undefined };

    return (
        <Page title="Stock on hand" subtitle="Balances are a projection of the stock ledger; value uses weighted-average cost">
            <div className="erp-row">
                <Field label="Warehouse">
                    <SelectBox
                        width={260} dataSource={warehouses.data ?? []} valueExpr="id" displayExpr="name" value={warehouseId}
                        showClearButton placeholder="All warehouses" onValueChanged={(e) => setWarehouseId(e.value ?? null)}
                    />
                </Field>
                <CheckBox text="Only items at or below reorder level" value={lowOnly} onValueChanged={(e) => setLowOnly(!!e.value)} />
            </div>
            <TabPanel deferRendering animationEnabled={false}>
                <Item title="Balances">
                    <CrudGrid resource="/inventory/balances" params={params} height={GRID_HEIGHT}>
                        <Column dataField="sku" caption="SKU" width={120} />
                        <Column dataField="product_name" caption="Product" />
                        <Column dataField="warehouse_name" caption="Warehouse" />
                        <Column {...numberColumn('on_hand', 3)} caption="On hand" />
                        <Column {...numberColumn('reserved', 3)} />
                        <Column {...numberColumn('available', 3)} />
                        <Column {...numberColumn('avg_cost')} caption="Avg cost" />
                        <Column {...numberColumn('stock_value')} caption="Value" />
                        <Summary>
                            <TotalItem column="stock_value" summaryType="sum" valueFormat={{ type: 'fixedPoint', precision: 2 }} displayFormat="Total {0}" />
                        </Summary>
                    </CrudGrid>
                </Item>
                <Item title="Movements (ledger)">
                    <CrudGrid resource="/inventory/movements" params={{ warehouse_id: warehouseId ?? undefined, limit: 500 }} height={GRID_HEIGHT}>
                        <Column dataField="occurred_at" caption="When" dataType="datetime" width={170} />
                        <Column dataField="movement_type" caption="Type" width={150} />
                        <Column dataField="sku" caption="SKU" width={120} />
                        <Column dataField="product_name" caption="Product" />
                        <Column dataField="warehouse_name" caption="Warehouse" />
                        <Column {...numberColumn('qty_delta', 3)} caption="Change" />
                        <Column {...numberColumn('balance_after', 3)} caption="Balance after" />
                        <Column {...numberColumn('unit_cost')} caption="Unit cost" />
                        <Column dataField="source_type" caption="Source" width={150} />
                    </CrudGrid>
                </Item>
            </TabPanel>
        </Page>
    );
}
