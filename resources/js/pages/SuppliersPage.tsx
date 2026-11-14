import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import { Column, Lookup, RequiredRule } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import { customColumns, useCustomFields } from '../components/CustomColumns';
import PaymentPopup from '../components/PaymentPopup';
import Page from '../components/Page';
import { getOne } from '../lib/api';
import { numberColumn } from '../lib/format';
import { useCan, useCompanyId } from '../lib/store';

export default function SuppliersPage() {
    const canManage = useCan('purchasing.supplier.manage');
    const canPay = useCan('purchasing.payment.create');
    const companyId = useCompanyId();
    const customFields = useCustomFields('supplier');
    const [selected, setSelected] = useState<string | null>(null);
    const [version, setVersion] = useState(0);
    const currencies = useQuery({ queryKey: ['currencies'], queryFn: () => getOne<any>('/currencies'), staleTime: 60000, retry: false }).data?.currencies ?? [];

    return (
        <Page title="Suppliers" subtitle="Balance is what you owe for goods received, less payments">
            <CrudGrid key={version} resource="/suppliers" canAdd={canManage} canUpdate={canManage}>
                <Column dataField="supplier_code" caption="Code" width={130} allowEditing={false} />
                <Column dataField="name"><RequiredRule /></Column>
                <Column dataField="contact_person" caption="Contact" />
                <Column dataField="phone" width={150} />
                <Column dataField="email" />
                <Column dataField="address" visible={false} />
                <Column dataField="tax_no" caption="Tax no." width={140} />
                <Column dataField="payment_terms_days" caption="Terms (days)" dataType="number" width={130} />
                {currencies.length > 0 && (
                    <Column dataField="currency" caption="Bills in" width={100}>
                        <Lookup dataSource={currencies.map((c: any) => c.code)} allowClearing />
                    </Column>
                )}
                <Column {...numberColumn('balance')} caption="We owe" allowEditing={false} />
                {customColumns(customFields)}
                <Column dataField="status" width={120}><Lookup dataSource={['active', 'inactive']} /></Column>
                <Column
                    caption="" width={130} allowSorting={false} allowFiltering={false} allowEditing={false} allowExporting={false}
                    cellRender={({ data }: any) => <Button text="Account" stylingMode="outlined" onClick={() => setSelected(data.id)} />}
                />
            </CrudGrid>
            {selected && companyId && (
                <PaymentPopup party="suppliers" partyId={selected} companyId={companyId} canPay={canPay}
                    onClose={(changed) => { setSelected(null); if (changed) setVersion((v) => v + 1); }} />
            )}
        </Page>
    );
}
