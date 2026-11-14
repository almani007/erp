import { useQuery } from '@tanstack/react-query';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import { Column, Lookup, RequiredRule } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import Page from '../components/Page';
import { getList } from '../lib/api';
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
            <TabPanel deferRendering animationEnabled={false}>
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
