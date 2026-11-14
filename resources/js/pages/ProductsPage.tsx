import { useQuery } from '@tanstack/react-query';
import { Column, Lookup, RequiredRule } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import Page from '../components/Page';
import { getList } from '../lib/api';
import { numberColumn } from '../lib/format';
import { useCan } from '../lib/store';

const TYPES = [
    { id: 'goods', name: 'Goods (stocked)' },
    { id: 'service', name: 'Service' },
    { id: 'non_stock', name: 'Non-stock item' },
    { id: 'composite', name: 'Composite' },
];
const STATUSES = ['active', 'inactive', 'discontinued'];

export default function ProductsPage() {
    const canManage = useCan('catalog.product.manage');
    const units = useQuery({ queryKey: ['units'], queryFn: () => getList('/units') });
    const categories = useQuery({ queryKey: ['categories'], queryFn: () => getList('/categories') });
    const taxCodes = useQuery({ queryKey: ['tax-codes'], queryFn: () => getList('/tax-codes') });

    return (
        <Page title="Products" subtitle="SKU, type and base unit are fixed after creation">
            <CrudGrid
                resource="/products"
                canAdd={canManage}
                canUpdate={canManage}
                editMode="popup"
                mapIn={(row) => ({ ...row, barcode: row.barcodes?.[0]?.barcode ?? '' })}
                mapOut={({ barcode, ...values }) => (barcode === undefined ? values : { ...values, barcodes: barcode ? [barcode] : [] })}
            >
                <Column dataField="sku" caption="SKU" width={120}><RequiredRule /></Column>
                <Column dataField="name"><RequiredRule /></Column>
                <Column dataField="barcode" width={150} />
                <Column dataField="product_type" caption="Type" width={150}>
                    <Lookup dataSource={TYPES} valueExpr="id" displayExpr="name" />
                    <RequiredRule />
                </Column>
                <Column dataField="base_unit_id" caption="Unit" width={100}>
                    <Lookup dataSource={units.data ?? []} valueExpr="id" displayExpr="abbreviation" />
                    <RequiredRule />
                </Column>
                <Column dataField="category_id" caption="Category">
                    <Lookup dataSource={categories.data ?? []} valueExpr="id" displayExpr="name" allowClearing />
                </Column>
                <Column dataField="tax_code_id" caption="Tax code" width={120}>
                    <Lookup dataSource={taxCodes.data ?? []} valueExpr="id" displayExpr="code" allowClearing />
                </Column>
                <Column {...numberColumn('cost_price')} caption="Cost" />
                <Column {...numberColumn('sale_price')} caption="Sale price" />
                <Column {...numberColumn('reorder_level', 0)} caption="Reorder at" />
                <Column dataField="status" width={120}>
                    <Lookup dataSource={STATUSES} />
                </Column>
            </CrudGrid>
        </Page>
    );
}
