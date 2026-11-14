import { Column, Lookup, RequiredRule } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import Page from '../components/Page';
import { numberColumn } from '../lib/format';
import { useCan } from '../lib/store';

export default function CustomersPage() {
    const canManage = useCan('sales.customer.manage');

    return (
        <Page title="Customers" subtitle="Balance is what the customer owes from credit sales">
            <CrudGrid resource="/customers" canAdd={canManage} canUpdate={canManage}>
                <Column dataField="customer_code" caption="Code" width={130} allowEditing={false} />
                <Column dataField="name"><RequiredRule /></Column>
                <Column dataField="phone" width={150} />
                <Column dataField="email" />
                <Column dataField="tax_no" caption="Tax no." width={150} />
                <Column {...numberColumn('credit_limit')} caption="Credit limit" />
                <Column {...numberColumn('balance')} allowEditing={false} />
                <Column dataField="status" width={120}><Lookup dataSource={['active', 'inactive']} /></Column>
            </CrudGrid>
        </Page>
    );
}
