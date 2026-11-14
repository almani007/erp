import { useQuery } from '@tanstack/react-query';
import { Chart, ArgumentAxis, Series, Tooltip, Legend, ValueAxis } from 'devextreme-react/chart';
import DataGrid, { Column } from 'devextreme-react/data-grid';
import Page from '../components/Page';
import { errorMessage, getOne } from '../lib/api';
import { money, numberColumn } from '../lib/format';
import { useCan, useSession } from '../lib/store';

export default function DashboardPage() {
    const allowed = useCan('reporting.dashboard.view');
    const branchId = useSession((s) => s.activeBranchId);
    const { data, error, isLoading } = useQuery({
        queryKey: ['dashboard', branchId],
        queryFn: () => getOne('/dashboard/summary'),
        enabled: allowed,
    });

    if (!allowed) {
        return <Page title="Welcome"><div className="erp-card">Use the menu to open the areas you have access to.</div></Page>;
    }

    const daily = (data?.sales_last_14_days ?? []).map((d: any) => ({ day: String(d.day).slice(5, 10), total: Number(d.total) }));

    return (
        <Page title="Dashboard" subtitle="Live figures for the selected branch scope">
            {error && <div className="erp-card erp-error">{errorMessage(error)}</div>}
            <div className="erp-kpi-grid">
                <Kpi label="Sales today" value={isLoading ? '…' : money(data?.sales_today.total)} note={`${data?.sales_today.count ?? 0} invoices`} />
                <Kpi label="Gross margin today" value={isLoading ? '…' : money(data?.sales_today.gross_margin)} note="Sales net of tax, less cost" />
                <Kpi label="Stock value" value={isLoading ? '…' : money(data?.stock.value)} note={`${data?.stock.low_stock_items ?? 0} items at or below reorder level`} />
                <Kpi label="Customer receivables" value={isLoading ? '…' : money(data?.receivables)} note="All customers" />
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 16 }}>
                <div className="erp-card">
                    <Chart dataSource={daily} title="Sales — last 14 days" height={340}>
                        <ArgumentAxis />
                        <ValueAxis />
                        <Series valueField="total" argumentField="day" type="bar" name="Sales" />
                        <Legend visible={false} />
                        <Tooltip enabled customizeTooltip={(p: any) => ({ text: money(p.value) })} />
                    </Chart>
                </div>
                <div className="erp-card">
                    <h3 style={{ marginBottom: 12, fontWeight: 500 }}>Top products — 30 days</h3>
                    <DataGrid dataSource={data?.top_products_30_days ?? []} showBorders noDataText="No sales yet">
                        <Column dataField="name" caption="Product" />
                        <Column {...numberColumn('total')} caption="Sales" />
                    </DataGrid>
                </div>
            </div>
        </Page>
    );
}

function Kpi({ label, value, note }: { label: string; value: string; note: string }) {
    return (
        <div className="erp-kpi-card">
            <span className="erp-kpi-label">{label}</span>
            <span className="erp-kpi-value">{value}</span>
            <span className="erp-muted">{note}</span>
        </div>
    );
}
