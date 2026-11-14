import TabPanel, { Item } from 'devextreme-react/tab-panel';
import { Column, Lookup, RequiredRule } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import Page from '../components/Page';
import { numberColumn } from '../lib/format';
import { useCan } from '../lib/store';

const ACTIVE = ['active', 'inactive'];
const TAX_TYPES = [{ id: 'exclusive', name: 'Added on top of price' }, { id: 'inclusive', name: 'Included in price' }];
const GRID_HEIGHT = 'calc(100vh - 250px)';

export default function MasterDataPage() {
    const canManage = useCan('catalog.master.manage');

    return (
        <Page title="Categories, units & tax" subtitle="Reference lists used by products">
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="Categories">
                    <CrudGrid resource="/categories" canAdd={canManage} canUpdate={canManage} height={GRID_HEIGHT}>
                        <Column dataField="name"><RequiredRule /></Column>
                        <Column dataField="sort_order" dataType="number" width={120} />
                        <Column dataField="status" width={140}><Lookup dataSource={ACTIVE} /></Column>
                    </CrudGrid>
                </Item>
                <Item title="Brands">
                    <CrudGrid resource="/brands" canAdd={canManage} canUpdate={canManage} height={GRID_HEIGHT}>
                        <Column dataField="name"><RequiredRule /></Column>
                        <Column dataField="status" width={140}><Lookup dataSource={ACTIVE} /></Column>
                    </CrudGrid>
                </Item>
                <Item title="Units">
                    <CrudGrid resource="/units" canAdd={canManage} height={GRID_HEIGHT}>
                        <Column dataField="name"><RequiredRule /></Column>
                        <Column dataField="abbreviation" width={160}><RequiredRule /></Column>
                    </CrudGrid>
                </Item>
                <Item title="Tax codes">
                    <div className="erp-muted" style={{ padding: '12px 4px' }}>
                        Rate and type cannot be edited once saved — add a new code for a new rate and deactivate the old one.
                    </div>
                    <CrudGrid resource="/tax-codes" canAdd={canManage} canUpdate={canManage} height={GRID_HEIGHT}>
                        <Column dataField="code" width={140}><RequiredRule /></Column>
                        <Column dataField="name"><RequiredRule /></Column>
                        <Column {...numberColumn('rate')} caption="Rate %"><RequiredRule /></Column>
                        <Column dataField="type" width={220}>
                            <Lookup dataSource={TAX_TYPES} valueExpr="id" displayExpr="name" />
                            <RequiredRule />
                        </Column>
                        <Column dataField="status" width={140}><Lookup dataSource={ACTIVE} /></Column>
                    </CrudGrid>
                </Item>
            </TabPanel>
        </Page>
    );
}
