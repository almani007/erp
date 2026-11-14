import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import CheckBox from 'devextreme-react/check-box';
import DataGrid, { Column, Editing, Lookup, MasterDetail, RequiredRule, SearchPanel } from 'devextreme-react/data-grid';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import TextBox from 'devextreme-react/text-box';
import DateBox from 'devextreme-react/date-box';
import Page, { Field } from '../components/Page';
import { statusCell } from '../components/Status';
import { api, getList, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { money, qty, toDateString } from '../lib/format';
import { can } from '../lib/store';

interface ProductRef { id: string; sku: string; name: string; product_type?: string; cost_price?: string }
interface RecipeItem { product_id: string; quantity: string | number; wastage_percent: string | number; product?: ProductRef }
interface Recipe { id: string; product_id: string; product: ProductRef; yield_quantity: string; consume_on_sale: boolean; notes: string | null; items: RecipeItem[] }
interface OrderItem { product_id: string; product: ProductRef; required_quantity: string; issued_quantity: string | null; unit_cost: string | null }
interface Order {
    id: string; order_no: string; product: ProductRef; warehouse: { id: string; name: string }; planned_quantity: string; produced_quantity: string | null;
    status: string; material_cost: string; extra_cost: string; unit_cost: string | null; created_at: string; items: OrderItem[];
}

/**
 * Recipes and production. A recipe either makes stock (production order:
 * bakery, workshop, garment unit) or is used up when sold (restaurant dish,
 * café drink).
 */
export default function ProductionPage() {
    return (
        <Page title="Recipes & production" subtitle="Bills of materials, production orders, and dishes that use up ingredients when sold.">
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="Production orders"><Orders /></Item>
                <Item title="Recipes"><Recipes /></Item>
            </TabPanel>
        </Page>
    );
}

function useProducts() {
    return useQuery({ queryKey: ['products'], queryFn: () => getList<ProductRef>('/products', { limit: 1000 }) });
}

function Recipes() {
    const client = useQueryClient();
    const recipes = useQuery({ queryKey: ['recipes'], queryFn: () => getList<Recipe>('/recipes') });
    const [editing, setEditing] = useState<Recipe | 'new' | null>(null);
    const canManage = can('production.recipe.manage');

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                {canManage && <Button text="New recipe" icon="add" type="default" onClick={() => setEditing('new')} />}
            </div>
            <DataGrid dataSource={recipes.data ?? []} keyExpr="id" showRowLines hoverStateEnabled columnAutoWidth
                onRowClick={(e) => canManage && e.rowType === 'data' && setEditing(e.data)}>
                <SearchPanel visible width={240} />
                <Column caption="Product" calculateCellValue={(r: Recipe) => `${r.product.sku} — ${r.product.name}`} />
                <Column caption="Makes" dataField="yield_quantity" width={90} cellRender={({ value }) => qty(value)} />
                <Column caption="Use" width={200} calculateCellValue={(r: Recipe) => (r.consume_on_sale ? 'Used up when sold' : 'Production order')} />
                <Column caption="Ingredients" calculateCellValue={(r: Recipe) => r.items.map((i) => `${i.product?.name} × ${qty(i.quantity)}`).join(', ')} />
            </DataGrid>
            {editing && (
                <RecipeEditor recipe={editing === 'new' ? null : editing} onClose={() => setEditing(null)}
                    onSaved={() => { setEditing(null); client.invalidateQueries({ queryKey: ['recipes'] }); }} />
            )}
        </div>
    );
}

function RecipeEditor({ recipe, onClose, onSaved }: { recipe: Recipe | null; onClose: () => void; onSaved: () => void }) {
    const products = useProducts();
    const [productId, setProductId] = useState<string | null>(recipe?.product_id ?? null);
    const [yieldQty, setYieldQty] = useState<number>(Number(recipe?.yield_quantity ?? 1));
    const [onSale, setOnSale] = useState(recipe?.consume_on_sale ?? false);
    const [notes, setNotes] = useState(recipe?.notes ?? '');
    const [items, setItems] = useState<RecipeItem[]>(() => (recipe?.items ?? []).map((i) => ({ product_id: i.product_id, quantity: Number(i.quantity), wastage_percent: Number(i.wastage_percent) })));
    const [busy, setBusy] = useState(false);
    const list = products.data ?? [];
    const label = (p: ProductRef) => (p ? `${p.sku} — ${p.name}` : '');

    // Rough cost from current cost prices, so the owner can price the item.
    const cost = items.reduce((sum, i) => {
        const p = list.find((x) => x.id === i.product_id);
        return sum + Number(i.quantity || 0) * (1 + Number(i.wastage_percent || 0) / 100) * Number(p?.cost_price ?? 0);
    }, 0) / (yieldQty || 1);

    const save = async () => {
        setBusy(true);
        const body = { product_id: productId, yield_quantity: yieldQty, consume_on_sale: onSale, notes: notes || null, items };
        try {
            if (recipe) await api.put(`/recipes/${recipe.id}`, body);
            else await api.post('/recipes', body);
            notifySuccess('Recipe saved.');
            onSaved();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    const remove = async () => {
        if (!recipe) return;
        try {
            await api.delete(`/recipes/${recipe.id}`);
            notifySuccess('Recipe switched off.');
            onSaved();
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <Popup visible title={recipe ? 'Edit recipe' : 'New recipe'} width={820} height="auto" maxHeight="92vh" onHiding={onClose} showCloseButton>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 140px', gap: 12 }}>
                <Field label="Product it makes">
                    <SelectBox dataSource={list} valueExpr="id" displayExpr={label} searchEnabled value={productId} disabled={!!recipe} onValueChanged={(e) => setProductId(e.value)} />
                </Field>
                <Field label="Quantity it makes">
                    <NumberBox value={yieldQty} min={0.000001} format="#,##0.######" onValueChanged={(e) => setYieldQty(e.value)} />
                </Field>
            </div>
            <div style={{ margin: '6px 0 10px' }}>
                <CheckBox value={onSale} onValueChanged={(e) => setOnSale(e.value)}
                    text="Take ingredients out when this item is sold (restaurant dish, café drink — the item must be non-stock)" />
            </div>
            <DataGrid dataSource={items} showBorders height={260}
                onRowInserted={() => setItems([...items])} onRowUpdated={() => setItems([...items])} onRowRemoved={() => setItems([...items])}>
                <Editing mode="cell" allowAdding allowUpdating allowDeleting newRowPosition="last" />
                <Column dataField="product_id" caption="Ingredient / material">
                    <Lookup dataSource={list} valueExpr="id" displayExpr={label} />
                    <RequiredRule />
                </Column>
                <Column dataField="quantity" caption="Quantity (base unit)" dataType="number" width={150}><RequiredRule /></Column>
                <Column dataField="wastage_percent" caption="Wastage %" dataType="number" width={110} />
            </DataGrid>
            <Field label="Notes / method"><TextBox value={notes} onValueChanged={(e) => setNotes(e.value)} /></Field>
            <div className="erp-actions">
                <span className="erp-muted">Estimated cost per unit: <b>{money(cost)}</b> (at current cost prices)</span>
                <div style={{ flex: 1 }} />
                {recipe && <Button text="Switch off" type="danger" stylingMode="outlined" onClick={remove} />}
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Saving…' : 'Save recipe'} type="default" disabled={busy || !productId || items.length === 0} onClick={save} />
            </div>
        </Popup>
    );
}

function Orders() {
    const client = useQueryClient();
    const orders = useQuery({ queryKey: ['production-orders'], queryFn: () => getList<Order>('/production-orders') });
    const [planning, setPlanning] = useState(false);
    const [completing, setCompleting] = useState<Order | null>(null);
    const canManage = can('production.order.manage');
    const refresh = () => client.invalidateQueries({ queryKey: ['production-orders'] });

    const cancel = async (order: Order) => {
        try {
            await api.post(`/production-orders/${order.id}/cancel`);
            refresh();
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                {canManage && <Button text="Plan production" icon="add" type="default" onClick={() => setPlanning(true)} />}
            </div>
            <DataGrid dataSource={orders.data ?? []} keyExpr="id" showRowLines columnAutoWidth>
                <SearchPanel visible width={240} />
                <Column dataField="order_no" caption="No." width={130} />
                <Column dataField="created_at" caption="Date" dataType="date" width={110} />
                <Column caption="Product" calculateCellValue={(o: Order) => o.product?.name} />
                <Column caption="Warehouse" calculateCellValue={(o: Order) => o.warehouse?.name} />
                <Column dataField="planned_quantity" caption="Planned" width={90} cellRender={({ value }) => qty(value)} />
                <Column dataField="produced_quantity" caption="Produced" width={90} cellRender={({ value }) => (value ? qty(value) : '')} />
                <Column dataField="unit_cost" caption="Unit cost" width={110} cellRender={({ value }) => (value ? money(value) : '')} />
                <Column dataField="status" width={110} cellRender={statusCell} />
                {canManage && <Column caption="" width={190} cellRender={({ data }: { data: Order }) => data.status === 'planned' && (
                    <>
                        <Button text="Complete" type="success" stylingMode="text" onClick={() => setCompleting(data)} />
                        <Button text="Cancel" stylingMode="text" onClick={() => cancel(data)} />
                    </>
                )} />}
                <MasterDetail enabled render={({ data }: { data: Order }) => (
                    <DataGrid dataSource={data.items} showBorders columnAutoWidth>
                        <Column caption="Material" calculateCellValue={(i: OrderItem) => `${i.product.sku} — ${i.product.name}`} />
                        <Column dataField="required_quantity" caption="Required" cellRender={({ value }) => qty(value)} />
                        <Column dataField="issued_quantity" caption="Used" cellRender={({ value }) => (value ? qty(value) : '')} />
                        <Column dataField="unit_cost" caption="Cost" cellRender={({ value }) => (value ? money(value) : '')} />
                    </DataGrid>
                )} />
            </DataGrid>
            {planning && <PlanOrder onClose={() => setPlanning(false)} onDone={() => { setPlanning(false); refresh(); }} />}
            {completing && <CompleteOrder order={completing} onClose={() => setCompleting(null)} onDone={() => { setCompleting(null); refresh(); }} />}
        </div>
    );
}

function PlanOrder({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
    const recipes = useQuery({ queryKey: ['recipes'], queryFn: () => getList<Recipe>('/recipes') });
    const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: () => getList('/warehouses') });
    const [recipeId, setRecipeId] = useState<string | null>(null);
    const [warehouseId, setWarehouseId] = useState<string | null>(null);
    const [quantity, setQuantity] = useState<number>(1);
    const [busy, setBusy] = useState(false);
    const [key] = useState(newIdempotencyKey);

    const save = async () => {
        setBusy(true);
        try {
            await api.post('/production-orders', { recipe_id: recipeId, warehouse_id: warehouseId, quantity }, idempotent(key));
            notifySuccess('Production planned.');
            onDone();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title="Plan production" width={520} height="auto" onHiding={onClose} showCloseButton>
            <Field label="What to make">
                <SelectBox dataSource={(recipes.data ?? []).filter((r) => !r.consume_on_sale)} valueExpr="id"
                    displayExpr={(r: Recipe) => (r ? `${r.product.name} (recipe makes ${qty(r.yield_quantity)})` : '')} value={recipeId} onValueChanged={(e) => setRecipeId(e.value)} />
            </Field>
            <Field label="Warehouse (materials out, product in)">
                <SelectBox dataSource={warehouses.data ?? []} valueExpr="id" displayExpr="name" value={warehouseId} onValueChanged={(e) => setWarehouseId(e.value)} />
            </Field>
            <Field label="Quantity to make"><NumberBox value={quantity} min={0.000001} onValueChanged={(e) => setQuantity(e.value)} /></Field>
            <div className="erp-actions">
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Saving…' : 'Plan'} type="default" disabled={busy || !recipeId || !warehouseId || !quantity} onClick={save} />
            </div>
        </Popup>
    );
}

function CompleteOrder({ order, onClose, onDone }: { order: Order; onClose: () => void; onDone: () => void }) {
    const [produced, setProduced] = useState<number>(Number(order.planned_quantity));
    const [extra, setExtra] = useState<number>(0);
    const [batch, setBatch] = useState('');
    const [expiry, setExpiry] = useState<Date | null>(null);
    const [used, setUsed] = useState(() => order.items.map((i) => ({ product_id: i.product_id, name: i.product.name, quantity: Number(i.required_quantity) })));
    const [busy, setBusy] = useState(false);
    const [key] = useState(newIdempotencyKey);

    const save = async () => {
        setBusy(true);
        try {
            await api.post(`/production-orders/${order.id}/complete`, {
                produced_quantity: produced, extra_cost: extra || 0, batch_no: batch || null,
                expiry_date: expiry ? toDateString(expiry) : null,
                used: used.map(({ product_id, quantity }) => ({ product_id, quantity })),
            }, idempotent(key));
            notifySuccess(`${order.order_no} completed. Stock updated.`);
            onDone();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title={`Complete ${order.order_no} — ${order.product.name}`} width={640} height="auto" onHiding={onClose} showCloseButton>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                <Field label="Quantity produced"><NumberBox value={produced} min={0.000001} onValueChanged={(e) => setProduced(e.value)} /></Field>
                <Field label="Labour & overheads (added to cost)"><NumberBox value={extra} min={0} format="#,##0.00" onValueChanged={(e) => setExtra(e.value)} /></Field>
                <Field label="Batch no (optional)"><TextBox value={batch} onValueChanged={(e) => setBatch(e.value)} /></Field>
                <Field label="Expiry (optional)"><DateBox value={expiry} type="date" showClearButton onValueChanged={(e) => setExpiry(e.value)} /></Field>
            </div>
            <div className="erp-muted" style={{ margin: '8px 0 4px' }}>Materials really used (change if different from the recipe)</div>
            <DataGrid dataSource={used} showBorders keyExpr="product_id" onRowUpdated={() => setUsed([...used])}>
                <Editing mode="cell" allowUpdating />
                <Column dataField="name" caption="Material" allowEditing={false} />
                <Column dataField="quantity" caption="Used" dataType="number" width={140} />
            </DataGrid>
            <div className="erp-actions">
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Completing…' : 'Complete production'} type="default" disabled={busy || !produced} onClick={save} />
            </div>
        </Popup>
    );
}
