import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import DataGrid, { Column, Editing, Lookup, RequiredRule } from 'devextreme-react/data-grid';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import TagBox from 'devextreme-react/tag-box';
import TextBox from 'devextreme-react/text-box';
import CrudGrid from '../components/CrudGrid';
import Page, { Field } from '../components/Page';
import { api, getList, notifyError, notifySuccess } from '../lib/api';
import { useSession } from '../lib/store';

/** Tables and modifier groups (size, spice level, add-ons) for the restaurant screens. */
export default function RestaurantSetupPage() {
    const branches = useSession((s) => s.branches);
    return (
        <Page title="Restaurant setup" subtitle="Tables by area, and the choices guests can make on menu items. Set each item's kitchen station on the product.">
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="Tables">
                    <CrudGrid resource="/restaurant/tables" canAdd canUpdate height="calc(100vh - 260px)">
                        <Column dataField="branch_id" caption="Branch"><Lookup dataSource={branches} valueExpr="id" displayExpr="name" /></Column>
                        <Column dataField="area" caption="Area / floor" />
                        <Column dataField="name" caption="Table"><RequiredRule /></Column>
                        <Column dataField="seats" dataType="number" width={90} />
                        <Column dataField="sort_order" caption="Order" dataType="number" width={90} />
                        <Column dataField="status" width={110}><Lookup dataSource={['active', 'inactive']} /></Column>
                    </CrudGrid>
                </Item>
                <Item title="Modifiers"><Modifiers /></Item>
            </TabPanel>
        </Page>
    );
}

function Modifiers() {
    const client = useQueryClient();
    const groups = useQuery({ queryKey: ['modifier-groups'], queryFn: () => getList<any>('/restaurant/modifier-groups') });
    const products = useQuery({ queryKey: ['products'], queryFn: () => getList<{ id: string; sku: string; name: string }>('/products', { limit: 1000 }) }).data ?? [];
    const [editing, setEditing] = useState<any | null>(null);

    const save = async () => {
        try {
            const body = { ...editing, options: editing.options.filter((o: any) => o.name) };
            if (editing.id) await api.put(`/restaurant/modifier-groups/${editing.id}`, body); else await api.post('/restaurant/modifier-groups', body);
            notifySuccess('Saved.');
            setEditing(null);
            client.invalidateQueries({ queryKey: ['modifier-groups'] });
            client.invalidateQueries({ queryKey: ['restaurant-menu'] });
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                <Button text="New modifier group" icon="add" type="default" onClick={() => setEditing({ name: '', min_select: 0, max_select: 1, options: [], product_ids: [] })} />
            </div>
            <DataGrid dataSource={groups.data ?? []} keyExpr="id" showRowLines hoverStateEnabled onRowClick={(e) => e.rowType === 'data' && setEditing(JSON.parse(JSON.stringify(e.data)))}>
                <Column dataField="name" />
                <Column caption="Choose" calculateCellValue={(g: any) => (g.min_select ? `${g.min_select}–${g.max_select} required` : `up to ${g.max_select}`)} />
                <Column caption="Options" calculateCellValue={(g: any) => g.options.map((o: any) => o.name).join(', ')} />
                <Column caption="Items" calculateCellValue={(g: any) => g.product_ids.length} width={80} />
            </DataGrid>
            {editing && (
                <Popup visible title={editing.id ? editing.name : 'New modifier group'} width={760} height="auto" maxHeight="92vh" onHiding={() => setEditing(null)} showCloseButton>
                    <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr', gap: 12 }}>
                        <Field label="Name (shown to staff)"><TextBox value={editing.name} placeholder="Spice level" onValueChanged={(e) => setEditing({ ...editing, name: e.value })} /></Field>
                        <Field label="At least"><NumberBox min={0} max={20} value={editing.min_select} onValueChanged={(e) => setEditing({ ...editing, min_select: e.value ?? 0 })} /></Field>
                        <Field label="At most"><NumberBox min={1} max={20} value={editing.max_select} onValueChanged={(e) => setEditing({ ...editing, max_select: e.value ?? 1 })} /></Field>
                    </div>
                    <DataGrid dataSource={editing.options} showBorders height={220}
                        onRowInserted={() => setEditing({ ...editing })} onRowUpdated={() => setEditing({ ...editing })} onRowRemoved={() => setEditing({ ...editing })}>
                        <Editing mode="cell" allowAdding allowUpdating allowDeleting newRowPosition="last" />
                        <Column dataField="name" caption="Option"><RequiredRule /></Column>
                        <Column dataField="price" caption="Price difference" dataType="number" format="#,##0.00" width={160} />
                    </DataGrid>
                    <Field label="Menu items that offer this choice">
                        <TagBox dataSource={products} valueExpr="id" displayExpr={(p: any) => (p ? `${p.name}` : '')} searchEnabled showSelectionControls
                            value={editing.product_ids} onValueChanged={(e) => setEditing({ ...editing, product_ids: e.value })} />
                    </Field>
                    <div className="erp-actions"><Button text="Cancel" onClick={() => setEditing(null)} /><Button text="Save" type="default" disabled={!editing.name || editing.options.length === 0} onClick={save} /></div>
                </Popup>
            )}
        </div>
    );
}
