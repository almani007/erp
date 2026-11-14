import { useQuery } from '@tanstack/react-query';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import { Column, Lookup, RequiredRule } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import Page from '../components/Page';
import { useState } from 'react';
import Button from 'devextreme-react/button';
import SelectBox from 'devextreme-react/select-box';
import { api, getList, notifyError, notifySuccess } from '../lib/api';
import { BUSINESS_TYPES, useAppConfig } from '../lib/config';
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
    const [busy, setBusy] = useState(false);
    const current = type ?? config?.business_type ?? 'general';

    const save = async () => {
        setBusy(true);
        try {
            await api.put('/settings', { group: 'business', key: 'type', value: current });
            notifySuccess('Business profile saved');
            setType(null);
            refetch();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    const rows: [string, boolean][] = [
        ['Wholesale prices, minimum quantities and wholesale customers', features.wholesale],
        ['Selling in packs — carton, dozen, set', features.packs],
        ['Size / colour variants of a style', features.variants],
        ['Prices from a daily market rate — gold, silver, steel by weight', features.rates],
    ];

    return (
        <div className="erp-card" style={{ maxWidth: 680 }}>
            <div className="erp-card-title">What kind of business is this?</div>
            <div className="erp-muted" style={{ marginBottom: 12 }}>
                This only tidies the screens: it decides which product fields and pricing tabs are shown. Retail and wholesale both work in every profile,
                and you can change it at any time without affecting data.
            </div>
            <div className="erp-row">
                <SelectBox width={360} dataSource={BUSINESS_TYPES} valueExpr="id" displayExpr="name" value={current} readOnly={!canEdit} onValueChanged={(e) => setType(e.value)} />
                {canEdit && <Button text={busy ? 'Saving…' : 'Save'} type="default" disabled={busy || type === null} onClick={save} />}
            </div>
            {rows.map(([label, on]) => (
                <div className="erp-list-row" key={label}>
                    <span>{label}</span>
                    <span className={`erp-badge ${on ? 'good' : ''}`}>{on ? 'shown' : 'hidden'}</span>
                </div>
            ))}
        </div>
    );
}
