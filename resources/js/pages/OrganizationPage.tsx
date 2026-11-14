import { useQuery, useQueryClient } from '@tanstack/react-query';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import { Column, Lookup, RequiredRule } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import Page from '../components/Page';
import { useState } from 'react';
import Button from 'devextreme-react/button';
import SelectBox from 'devextreme-react/select-box';
import Switch from 'devextreme-react/switch';
import { api, getList, notifyError, notifySuccess } from '../lib/api';
import { BUSINESS_TYPES, FEATURE_LABELS, Features, presetFeatures, useAppConfig } from '../lib/config';
import { useCan, useCompanyId } from '../lib/store';

const GRID_HEIGHT = 'calc(100vh - 250px)';

export default function OrganizationPage() {
    const canBranches = useCan('organization.branch.manage');
    const canWarehouses = useCan('organization.warehouse.manage');
    const canTerminals = useCan('sales.shift.manage');
    const canSell = useCan('sales.sale.create');
    const companyId = useCompanyId();
    const branches = useQuery({ queryKey: ['branches'], queryFn: () => getList('/branches') });
    const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: () => getList('/warehouses') });

    return (
        <Page title="Branches & warehouses" subtitle="Codes are fixed after creation. New branches are added to the company of the selected branch.">
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="Business profile">
                    <BusinessProfile />
                </Item>
                <Item title="Custom fields">
                    <CustomFields />
                </Item>
                <Item title="Branches">
                    <CrudGrid
                        resource="/branches" height={GRID_HEIGHT} canAdd={canBranches && !!companyId} canUpdate={canBranches}
                        createDefaults={{ company_id: companyId, timezone: 'Asia/Karachi' }}
                    >
                        <Column dataField="code" width={130}><RequiredRule /></Column>
                        <Column dataField="name"><RequiredRule /></Column>
                        <Column dataField="timezone" width={200} />
                        <Column dataField="phone" width={160} />
                        <Column dataField="status" width={130}><Lookup dataSource={['active', 'inactive', 'closed']} /></Column>
                    </CrudGrid>
                </Item>
                <Item title="Warehouses">
                    <CrudGrid resource="/warehouses" height={GRID_HEIGHT} canAdd={canWarehouses} canUpdate={canWarehouses}>
                        <Column dataField="branch_id" caption="Branch">
                            <Lookup dataSource={branches.data ?? []} valueExpr="id" displayExpr="name" />
                            <RequiredRule />
                        </Column>
                        <Column dataField="code" width={150}><RequiredRule /></Column>
                        <Column dataField="name"><RequiredRule /></Column>
                        <Column dataField="status" width={130}><Lookup dataSource={['active', 'inactive']} /></Column>
                    </CrudGrid>
                </Item>
                {canSell && (
                    <Item title="POS terminals">
                        <CrudGrid resource="/pos/terminals" height={GRID_HEIGHT} canAdd={canTerminals}>
                            <Column dataField="code" width={150}><RequiredRule /></Column>
                            <Column dataField="name"><RequiredRule /></Column>
                            <Column dataField="warehouse_id" caption="Sells from warehouse">
                                <Lookup dataSource={warehouses.data ?? []} valueExpr="id" displayExpr="name" />
                                <RequiredRule />
                            </Column>
                            <Column dataField="branch.name" caption="Branch" allowEditing={false} />
                        </CrudGrid>
                    </Item>
                )}
            </TabPanel>
        </Page>
    );
}

function BusinessProfile() {
    const canEdit = useCan('organization.settings.manage');
    const { config, features, refetch } = useAppConfig();
    const [type, setType] = useState<string | null>(null);
    const [switches, setSwitches] = useState<Partial<Features> | null>(null);
    const [busy, setBusy] = useState(false);

    const currentType = type ?? config?.business_type ?? 'general';
    // Picking a different type starts again from that type's defaults.
    const current: Features = type !== null ? { ...presetFeatures(type), ...(switches ?? {}) } : { ...features, ...(switches ?? {}) };
    const dirty = type !== null || switches !== null;

    const save = async () => {
        setBusy(true);
        try {
            await api.put('/settings', { group: 'business', key: 'type', value: currentType });
            await api.put('/settings', { group: 'business', key: 'features', value: current });
            notifySuccess('Business profile saved');
            setType(null);
            setSwitches(null);
            refetch();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="erp-card" style={{ maxWidth: 760 }}>
            <div className="erp-card-title">What kind of business is this?</div>
            <div className="erp-muted" style={{ marginBottom: 12 }}>
                Pick the closest type, then switch individual features on or off. This only tidies the screens — it decides which fields,
                tabs and menu items are shown. Retail and wholesale both work in every profile, and changing it never affects your data.
            </div>
            <div className="erp-row">
                <SelectBox
                    width={380} dataSource={BUSINESS_TYPES} valueExpr="id" displayExpr="name" value={currentType} readOnly={!canEdit}
                    onValueChanged={(e) => { if (e.event) { setType(e.value); setSwitches(null); } }}
                />
                {canEdit && <Button text={busy ? 'Saving…' : 'Save'} type="default" disabled={busy || !dirty} onClick={save} />}
            </div>
            {FEATURE_LABELS.map(({ key, label }) => (
                <div className="erp-list-row" key={key} style={{ alignItems: 'center' }}>
                    <span>{label}</span>
                    <Switch value={current[key]} readOnly={!canEdit} onValueChanged={(e) => { if (e.event) setSwitches((s) => ({ ...(s ?? {}), [key]: !!e.value })); }} />
                </div>
            ))}
            <div className="erp-muted" style={{ marginTop: 12 }}>
                Need to record something that is not here? Add your own fields to products, customers and suppliers under the Custom fields tab.
                Restaurants (tables, kitchen tickets) and manufacturing (recipes, production orders) need modules that are not built yet.
            </div>
        </div>
    );
}

const ENTITIES = [{ id: 'product', name: 'Products' }, { id: 'customer', name: 'Customers' }, { id: 'supplier', name: 'Suppliers' }];
const FIELD_TYPES = [
    { id: 'text', name: 'Text' }, { id: 'number', name: 'Number' }, { id: 'date', name: 'Date' },
    { id: 'boolean', name: 'Yes / No' }, { id: 'select', name: 'Choice from a list' },
];

function CustomFields() {
    const canEdit = useCan('organization.settings.manage');
    const queryClient = useQueryClient();

    return (
        <>
            <div className="erp-muted" style={{ marginBottom: 10 }}>
                Extra fields for your own business — fabric, shelf number, CNIC, licence number. They appear as columns and form fields on the
                matching screen. The record type and field type cannot be changed once a field exists; set a field inactive to hide it.
            </div>
            <div onBlur={() => queryClient.invalidateQueries({ queryKey: ['custom-fields'] })}>
                <CrudGrid
                    resource="/custom-fields" height={GRID_HEIGHT} canAdd={canEdit} canUpdate={canEdit}
                    mapIn={(row) => ({ ...row, options: (row.options ?? []).join(', ') })}
                >
                    <Column dataField="entity" caption="On" width={150}><Lookup dataSource={ENTITIES} valueExpr="id" displayExpr="name" /><RequiredRule /></Column>
                    <Column dataField="label" caption="Field name"><RequiredRule /></Column>
                    <Column dataField="type" width={190}><Lookup dataSource={FIELD_TYPES} valueExpr="id" displayExpr="name" /><RequiredRule /></Column>
                    <Column dataField="options" caption="Choices (comma separated, for lists)" />
                    <Column dataField="sort_order" caption="Order" dataType="number" width={100} />
                    <Column dataField="status" width={130}><Lookup dataSource={['active', 'inactive']} /></Column>
                </CrudGrid>
            </div>
        </>
    );
}
