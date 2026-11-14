import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import DateBox from 'devextreme-react/date-box';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import DataGrid, { Column, Export, Summary, TotalItem } from 'devextreme-react/data-grid';
import Page, { Field } from '../components/Page';
import { errorMessage, getOne } from '../lib/api';
import { money, numberColumn, startOfMonth, today } from '../lib/format';
import { useCompanyId, useSession } from '../lib/store';

const sumFormat = { type: 'fixedPoint' as const, precision: 2 };

export default function ReportsPage() {
    const companyId = useCompanyId();
    const branchId = useSession((s) => s.activeBranchId);
    const [from, setFrom] = useState(startOfMonth());
    const [to, setTo] = useState(today());
    const base = { company_id: companyId, branch_id: branchId ?? undefined };
    const enabled = !!companyId;

    const trial = useQuery({ queryKey: ['trial-balance', base, to], queryFn: () => getOne('/reports/trial-balance', { ...base, as_of: to }), enabled });
    const pnl = useQuery({ queryKey: ['profit-loss', base, from, to], queryFn: () => getOne('/reports/profit-loss', { ...base, from, to }), enabled });
    const sheet = useQuery({ queryKey: ['balance-sheet', base, to], queryFn: () => getOne('/reports/balance-sheet', { ...base, as_of: to }), enabled });
    const error = trial.error ?? pnl.error ?? sheet.error;

    return (
        <Page title="Financial reports" subtitle="Built from posted journals. Trial balance and balance sheet are as of the To date.">
            <div className="erp-row">
                <Field label="From"><DateBox type="date" value={from} dateSerializationFormat="yyyy-MM-dd" onValueChanged={(e) => setFrom(e.value)} /></Field>
                <Field label="To"><DateBox type="date" value={to} dateSerializationFormat="yyyy-MM-dd" onValueChanged={(e) => setTo(e.value)} /></Field>
            </div>
            {error && <div className="erp-card erp-error">{errorMessage(error)}</div>}
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="Trial balance">
                    <div className="erp-card">
                        <DataGrid dataSource={(trial.data as any) ?? []} showBorders columnAutoWidth>
                            <Export enabled />
                            <Column dataField="account_code" caption="Code" width={120} />
                            <Column dataField="account_name" caption="Account" />
                            <Column dataField="account_type" caption="Type" width={140} />
                            <Column {...numberColumn('debit_balance')} caption="Debit" />
                            <Column {...numberColumn('credit_balance')} caption="Credit" />
                            <Summary>
                                <TotalItem column="debit_balance" summaryType="sum" valueFormat={sumFormat} displayFormat="{0}" />
                                <TotalItem column="credit_balance" summaryType="sum" valueFormat={sumFormat} displayFormat="{0}" />
                            </Summary>
                        </DataGrid>
                    </div>
                </Item>
                <Item title="Profit & loss">
                    <div className="erp-card">
                        <Section title="Revenue" rows={pnl.data?.revenue} total={pnl.data?.total_revenue} />
                        <Section title="Expenses" rows={pnl.data?.expenses} total={pnl.data?.total_expenses} />
                        <Total label="Net income" value={pnl.data?.net_income} />
                    </div>
                </Item>
                <Item title="Balance sheet">
                    <div className="erp-card">
                        <Section title="Assets" rows={sheet.data?.assets} total={sheet.data?.total_assets} />
                        <Section title="Liabilities" rows={sheet.data?.liabilities} total={sheet.data?.total_liabilities} />
                        <Section title="Equity" rows={sheet.data?.equity} />
                        <Total label="Retained earnings (to date)" value={sheet.data?.retained_earnings} />
                        <Total label="Total equity" value={sheet.data?.total_equity} />
                        {sheet.data && !sheet.data.is_balanced && (
                            <div className="erp-error">Assets do not equal liabilities plus equity — review the ledger.</div>
                        )}
                    </div>
                </Item>
            </TabPanel>
        </Page>
    );
}

function Section({ title, rows, total }: { title: string; rows?: any[]; total?: string }) {
    return (
        <div style={{ marginBottom: 20 }}>
            <h3 style={{ fontWeight: 500, marginBottom: 8 }}>{title}</h3>
            <DataGrid dataSource={rows ?? []} showBorders showColumnHeaders={false} noDataText="No activity">
                <Column dataField="account_code" width={120} />
                <Column dataField="account_name" />
                <Column {...numberColumn('balance')} width={180} />
            </DataGrid>
            {total !== undefined && <Total label={`Total ${title.toLowerCase()}`} value={total} />}
        </div>
    );
}

function Total({ label, value }: { label: string; value?: string }) {
    return (
        <div className="pos-total-row" style={{ fontWeight: 600, padding: '8px 12px' }}>
            <span>{label}</span>
            <span>{money(value)}</span>
        </div>
    );
}
