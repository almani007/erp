import { Column, Lookup, RequiredRule } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import Page from '../components/Page';
import { useCan, useCompanyId } from '../lib/store';

const TYPES = [
    { id: 'asset', name: 'Asset' },
    { id: 'liability', name: 'Liability' },
    { id: 'equity', name: 'Equity' },
    { id: 'revenue', name: 'Revenue' },
    { id: 'expense', name: 'Expense' },
];

export default function AccountsPage() {
    const canManage = useCan('finance.account.manage');
    const companyId = useCompanyId();

    if (!companyId) {
        return <Page title="Chart of accounts"><div className="erp-card">No company is available for your account.</div></Page>;
    }

    return (
        <Page title="Chart of accounts" subtitle="Code and type are fixed once an account exists, so posted history keeps its classification">
            <CrudGrid resource="/accounts" params={{ company_id: companyId }} createDefaults={{ company_id: companyId }} canAdd={canManage} canUpdate={canManage}>
                <Column dataField="code" width={120}><RequiredRule /></Column>
                <Column dataField="name"><RequiredRule /></Column>
                <Column dataField="account_type" caption="Type" width={160}>
                    <Lookup dataSource={TYPES} valueExpr="id" displayExpr="name" />
                    <RequiredRule />
                </Column>
                <Column dataField="is_control_account" caption="Control account" dataType="boolean" width={150} />
                <Column dataField="status" width={130}><Lookup dataSource={['active', 'inactive']} /></Column>
            </CrudGrid>
        </Page>
    );
}
