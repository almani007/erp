import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import Popup from 'devextreme-react/popup';
import TagBox from 'devextreme-react/tag-box';
import { Column, Lookup, RequiredRule } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import { customColumns, useCustomFields } from '../components/CustomColumns';
import Page from '../components/Page';
import { api, getList, notifyError, notifySuccess } from '../lib/api';
import { useAppConfig } from '../lib/config';
import { numberColumn } from '../lib/format';
import { useCan } from '../lib/store';

const TYPES = [
    { id: 'goods', name: 'Goods (stocked)' },
    { id: 'service', name: 'Service' },
    { id: 'non_stock', name: 'Non-stock item' },
    { id: 'composite', name: 'Composite' },
];
const PRICING = [{ id: 'fixed', name: 'Fixed price' }, { id: 'rate', name: 'From market rate' }];
const STATUSES = ['active', 'inactive', 'discontinued'];

export default function ProductsPage() {
    const canManage = useCan('catalog.product.manage');
    const { features } = useAppConfig();
    const units = useQuery({ queryKey: ['units'], queryFn: () => getList('/units') });
    const categories = useQuery({ queryKey: ['categories'], queryFn: () => getList('/categories') });
    const taxCodes = useQuery({ queryKey: ['tax-codes'], queryFn: () => getList('/tax-codes') });
    const rates = useQuery({ queryKey: ['market-rates'], queryFn: () => getList('/market-rates'), enabled: features.rates });
    const customFields = useCustomFields('product');
    const [packsFor, setPacksFor] = useState<any | null>(null);
    const [variantsFor, setVariantsFor] = useState<any | null>(null);
    const [version, setVersion] = useState(0);

    const hasActions = canManage && (features.packs || features.variants);

    return (
        <Page title="Products" subtitle="SKU, type and base unit are fixed after creation. Fields shown depend on your business profile.">
            <CrudGrid
                key={version}
                resource="/products"
                canAdd={canManage}
                canUpdate={canManage}
                editMode="popup"
                mapIn={(row) => ({ ...row, barcode: row.barcodes?.[0]?.barcode ?? '' })}
                mapOut={({ barcode, ...values }) => (barcode === undefined ? values : { ...values, barcodes: barcode ? [barcode] : [] })}
            >
                <Column dataField="sku" caption="SKU" width={150}><RequiredRule /></Column>
                <Column dataField="name"><RequiredRule /></Column>
                <Column dataField="barcode" width={150} />
                <Column dataField="product_type" caption="Type" width={150} visible={false}>
                    <Lookup dataSource={TYPES} valueExpr="id" displayExpr="name" />
                    <RequiredRule />
                </Column>
                <Column dataField="base_unit_id" caption="Unit" width={90}>
                    <Lookup dataSource={units.data ?? []} valueExpr="id" displayExpr="abbreviation" />
                    <RequiredRule />
                </Column>
                <Column dataField="category_id" caption="Category">
                    <Lookup dataSource={categories.data ?? []} valueExpr="id" displayExpr="name" allowClearing />
                </Column>
                <Column dataField="tax_code_id" caption="Tax code" width={110}>
                    <Lookup dataSource={taxCodes.data ?? []} valueExpr="id" displayExpr="code" allowClearing />
                </Column>
                <Column {...numberColumn('cost_price')} caption="Cost" />
                <Column {...numberColumn('sale_price')} caption="Retail price" />
                {features.wholesale && <Column {...numberColumn('wholesale_price')} caption="Wholesale price" />}
                {features.wholesale && <Column {...numberColumn('wholesale_min_qty', 0)} caption="Wholesale from qty" />}
                {features.rates && (
                    <Column dataField="pricing_mode" caption="Pricing" width={150}>
                        <Lookup dataSource={PRICING} valueExpr="id" displayExpr="name" />
                    </Column>
                )}
                {features.rates && (
                    <Column dataField="rate_code" caption="Market rate" width={130}>
                        <Lookup dataSource={rates.data ?? []} valueExpr="code" displayExpr={(r: any) => (r ? `${r.name} (per ${r.per_unit})` : '')} allowClearing />
                    </Column>
                )}
                {features.rates && <Column {...numberColumn('weight', 3)} caption="Weight / piece" visible={false} />}
                {features.rates && <Column dataField="purity" width={100} visible={false} />}
                {features.rates && <Column {...numberColumn('wastage_percent')} caption="Wastage %" visible={false} />}
                {features.rates && <Column {...numberColumn('making_charge')} caption="Making charge" visible={false} />}
                {features.expiry && <Column dataField="track_batch" caption="Batch tracked" dataType="boolean" width={120} />}
                {features.expiry && <Column dataField="track_expiry" caption="Expiry tracked" dataType="boolean" width={125} />}
                {features.serials && <Column dataField="track_serial" caption="Serial / IMEI" dataType="boolean" width={115} />}
                {features.serials && <Column dataField="warranty_months" caption="Warranty (months)" dataType="number" width={150} />}
                {customColumns(customFields)}
                <Column {...numberColumn('reorder_level', 0)} caption="Reorder at" />
                <Column dataField="status" width={120}>
                    <Lookup dataSource={STATUSES} />
                </Column>
                {hasActions && (
                    <Column
                        caption="" width={features.packs && features.variants ? 190 : 110} allowSorting={false} allowFiltering={false} allowEditing={false} allowExporting={false}
                        formItem={{ visible: false }}
                        cellRender={({ data }: any) => (
                            <span style={{ display: 'flex', gap: 6 }}>
                                {features.packs && <Button text="Packs" stylingMode="outlined" onClick={() => setPacksFor(data)} />}
                                {features.variants && !data.parent_id && <Button text="Variants" stylingMode="outlined" onClick={() => setVariantsFor(data)} />}
                            </span>
                        )}
                    />
                )}
            </CrudGrid>
            {packsFor && <PacksPopup product={packsFor} wholesale={features.wholesale} onClose={() => setPacksFor(null)} />}
            {variantsFor && <VariantsPopup product={variantsFor} onClose={(created) => { setVariantsFor(null); if (created) setVersion((v) => v + 1); }} />}
        </Page>
    );
}

function PacksPopup({ product, wholesale, onClose }: { product: any; wholesale: boolean; onClose: () => void }) {
    return (
        <Popup visible title={`Packs — ${product.name}`} width={780} height="auto" onHiding={onClose} showCloseButton>
            <div className="erp-muted" style={{ marginBottom: 10 }}>
                Sell this product by the carton, dozen or set as well as singly. "Units in pack" converts each pack sold into stock;
                it cannot be changed later. Leave the price blank to charge units × the unit price.
            </div>
            <CrudGrid resource={`/products/${product.id}/units`} canAdd canUpdate canDelete height={320} name="product-packs">
                <Column dataField="name" caption="Pack name"><RequiredRule /></Column>
                <Column {...numberColumn('factor', 3)} caption="Units in pack"><RequiredRule /></Column>
                <Column {...numberColumn('price')} caption="Pack price" />
                {wholesale && <Column {...numberColumn('wholesale_price')} caption="Wholesale pack price" />}
                <Column dataField="barcode" caption="Pack barcode" />
            </CrudGrid>
        </Popup>
    );
}

function VariantsPopup({ product, onClose }: { product: any; onClose: (created: boolean) => void }) {
    const [sizes, setSizes] = useState<string[]>([]);
    const [colours, setColours] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const count = Math.max(sizes.length, 1) * Math.max(colours.length, 1);

    const generate = async () => {
        const options: Record<string, string[]> = {};
        if (sizes.length) options.Size = sizes;
        if (colours.length) options.Colour = colours;
        setBusy(true);
        try {
            const { data } = await api.post(`/products/${product.id}/variants`, { options });
            notifySuccess(`${data.data.created} variants created${data.data.skipped ? `, ${data.data.skipped} already existed` : ''}`);
            onClose(data.data.created > 0);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title={`Size / colour variants — ${product.name}`} width={560} height="auto" onHiding={() => onClose(false)} showCloseButton>
            <div className="erp-muted" style={{ marginBottom: 12 }}>
                Each combination becomes its own product with its own SKU, barcode and stock, copying this style's prices.
                The style itself is then set inactive so only real variants are sold.
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                <TagBox label="Sizes (type and press Enter)" acceptCustomValue items={['XS', 'S', 'M', 'L', 'XL', 'XXL']} value={sizes} onValueChanged={(e) => setSizes(e.value)} />
                <TagBox label="Colours (type and press Enter)" acceptCustomValue items={['Black', 'White', 'Navy', 'Red', 'Green', 'Beige']} value={colours} onValueChanged={(e) => setColours(e.value)} />
            </div>
            <div className="erp-actions">
                <Button text="Cancel" onClick={() => onClose(false)} />
                <Button text={busy ? 'Creating…' : `Create ${count} variants`} type="default" disabled={busy || (sizes.length === 0 && colours.length === 0)} onClick={generate} />
            </div>
        </Popup>
    );
}
