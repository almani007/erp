import { useState } from 'react';
import Button from 'devextreme-react/button';
import { Column, Lookup, RequiredRule } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import PaymentPopup from '../components/PaymentPopup';
import Page from '../components/Page';
import { useAppConfig } from '../lib/config';
import { numberColumn } from '../lib/format';
import { useCan, useCompanyId } from '../lib/store';

export default function CustomersPage() {
    const canManage = useCan('sales.customer.manage');
    const canReceive = useCan('sales.payment.receive');
    const companyId = useCompanyId();
    const { features } = useAppConfig();
    const [selected, setSelected] = useState<string | null>(null);
    const [version, setVersion] = useState(0);

    return (
        <Page title="Customers" subtitle="Balance is what the customer owes from credit sales, less returns and payments">
            <CrudGrid key={version} resource="/customers" canAdd={canManage} canUpdate={canManage}>
                <Column dataField="customer_code" caption="Code" width={130} allowEditing={false} />
                <Column dataField="name"><RequiredRule /></Column>
                <Column dataField="phone" width={150} />
                <Column dataField="email" />
                <Column dataField="tax_no" caption="Tax no." width={150} />
                {features.wholesale && (
                    <Column dataField="price_tier" caption="Price tier" width={130}>
                        <Lookup dataSource={[{ id: 'retail', name: 'Retail' }, { id: 'wholesale', name: 'Wholesale' }]} valueExpr="id" displayExpr="name" />
                    </Column>
                )}
                <Column {...numberColumn('credit_limit')} caption="Credit limit" />
                <Column dataField="loyalty_points" caption="Points" dataType="number" width={100} allowEditing={false} />
                <Column {...numberColumn('balance')} allowEditing={false} />
                <Column dataField="status" width={120}><Lookup dataSource={['active', 'inactive']} /></Column>
                <Column
                    caption="" width={130} allowSorting={false} allowFiltering={false} allowEditing={false} allowExporting={false}
                    cellRender={({ data }: any) => <Button text="Account" stylingMode="outlined" onClick={() => setSelected(data.id)} />}
                />
            </CrudGrid>
            {selected && companyId && (
                <PaymentPopup party="customers" partyId={selected} companyId={companyId} canPay={canReceive}
                    onClose={(changed) => { setSelected(null); if (changed) setVersion((v) => v + 1); }} />
            )}
        </Page>
    );
}
