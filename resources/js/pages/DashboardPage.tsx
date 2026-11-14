import { useQuery } from '@tanstack/react-query';
import { Chart, ArgumentAxis, CommonSeriesSettings, Legend, Series, Tooltip, ValueAxis } from 'devextreme-react/chart';
import PieChart, { Connector, Label, Legend as PieLegend, Series as PieSeries, Tooltip as PieTooltip } from 'devextreme-react/pie-chart';
import Page from '../components/Page';
import { errorMessage, getOne } from '../lib/api';
import { money, qty } from '../lib/format';
import { useCan, useSession } from '../lib/store';

const METHOD_NAMES: Record<string, string> = { cash: 'Cash', card: 'Card', bank_transfer: 'Bank transfer', wallet: 'Wallet', credit: 'On account' };

export default function DashboardPage() {
    const allowed = useCan('reporting.dashboard.view');
    const branchId = useSession((s) => s.activeBranchId);
    const userName = useSession((s) => s.user?.name ?? '');
    const { data, error, isLoading } = useQuery({
        queryKey: ['dashboard', branchId],
        queryFn: () => getOne('/dashboard/summary'),
        enabled: allowed,
        refetchInterval: 60_000,
    });

    if (!allowed) {
        return <Page title={`Welcome, ${userName}`}><div className="erp-card">Use the menu to open the areas you have access to.</div></Page>;
    }

    const show = (value: unknown) => (isLoading ? '…' : money(value));
    const daily = (data?.sales_last_14_days ?? []).map((d: any) => ({ day: String(d.day).slice(5, 10), total: Number(d.total), count: Number(d.n) }));
    const mix = (data?.payment_mix_30_days ?? []).map((m: any) => ({ method: METHOD_NAMES[m.method] ?? m.method, total: Number(m.total) }));

    return (
        <Page title="Dashboard" subtitle="Live figures for the selected branch scope · refreshes every minute">
            {error && <div className="erp-card erp-error" style={{ marginBottom: 16 }}>{errorMessage(error)}</div>}

            <div className="erp-kpi-grid">
                <Kpi icon="money" tone="blue" label="Sales today" value={show(data?.sales_today.total)} note={`${data?.sales_today.count ?? 0} invoices`} />
                <Kpi icon="chart" tone="green" label="Gross margin today" value={show(data?.sales_today.gross_margin)} note="Net of tax, less cost" />
                <Kpi icon="event" tone="violet" label="Sales this month" value={show(data?.sales_this_month.total)} note={`${data?.sales_this_month.count ?? 0} invoices`} />
                <Kpi icon="box" tone="amber" label="Stock value" value={show(data?.stock.value)} note={`${data?.stock.low_stock_items ?? 0} items need reordering`} />
                <Kpi icon="group" tone="slate" label="Receivables" value={show(data?.receivables)} note="Owed by customers" />
                <Kpi icon="orderedlist" tone="rose" label="Payables" value={show(data?.payables)} note={`${data?.open_purchase_orders ?? 0} open purchase orders`} />
            </div>

            <div className="erp-grid-2">
                <div className="erp-card">
                    <div className="erp-card-title">Sales — last 14 days</div>
                    <Chart dataSource={daily} height={320}>
                        <CommonSeriesSettings argumentField="day" />
                        <ArgumentAxis />
                        <ValueAxis />
                        <Series valueField="total" type="bar" name="Sales" color="#2563eb" cornerRadius={4} />
                        <Legend visible={false} />
                        <Tooltip enabled customizeTooltip={(p: any) => ({ text: `${p.argumentText}: ${money(p.value)}` })} />
                    </Chart>
                </div>
                <div className="erp-card" style={{ marginTop: 0 }}>
                    <div className="erp-card-title">Payment mix — 30 days</div>
                    {mix.length === 0 ? <div className="erp-muted">No sales yet.</div> : (
                        <PieChart dataSource={mix} type="doughnut" innerRadius={0.62} height={320} palette="Soft Blue">
                            <PieSeries argumentField="method" valueField="total">
                                <Label visible format="fixedPoint" customizeText={(p: any) => `${p.percentText}`}>
                                    <Connector visible />
                                </Label>
                            </PieSeries>
                            <PieLegend horizontalAlignment="center" verticalAlignment="bottom" />
                            <PieTooltip enabled customizeTooltip={(p: any) => ({ text: `${p.argumentText}: ${money(p.value)}` })} />
                        </PieChart>
                    )}
                </div>
            </div>

            <div className="erp-grid-3" style={{ marginTop: 16 }}>
                <ListCard title="Top products — 30 days" empty="No sales yet." rows={(data?.top_products_30_days ?? []).map((p: any) => ({
                    key: p.name, left: p.name, sub: `${qty(p.quantity)} sold`, right: money(p.total),
                }))} />
                <ListCard title="Low stock" empty="Nothing is at or below its reorder level." rows={(data?.low_stock ?? []).map((s: any) => ({
                    key: `${s.sku}-${s.warehouse}`, left: s.name, sub: `${s.sku} · ${s.warehouse}`, right: `${qty(s.on_hand)} left`, warn: true,
                }))} />
                <ListCard title="Recent sales" empty="No sales yet." rows={(data?.recent_sales ?? []).map((s: any) => ({
                    key: s.id, left: s.sale_no, sub: new Date(s.sold_at).toLocaleString(), right: money(s.grand_total),
                }))} />
            </div>
        </Page>
    );
}

function Kpi({ icon, tone, label, value, note }: { icon: string; tone: string; label: string; value: string; note: string }) {
    return (
        <div className="erp-kpi">
            <div className={`erp-kpi-icon ${tone}`}><i className={`dx-icon dx-icon-${icon}`} aria-hidden="true" /></div>
            <div>
                <div className="erp-kpi-label">{label}</div>
                <div className="erp-kpi-value">{value}</div>
                <div className="erp-kpi-note">{note}</div>
            </div>
        </div>
    );
}

interface ListRow { key: string; left: string; sub: string; right: string; warn?: boolean }

function ListCard({ title, rows, empty }: { title: string; rows: ListRow[]; empty: string }) {
    return (
        <div className="erp-card" style={{ marginTop: 0 }}>
            <div className="erp-card-title">{title}</div>
            {rows.length === 0 && <div className="erp-muted">{empty}</div>}
            {rows.map((row) => (
                <div className="erp-list-row" key={row.key}>
                    <div>
                        <div style={{ fontWeight: 600 }}>{row.left}</div>
                        <div className="erp-muted">{row.sub}</div>
                    </div>
                    <div className="erp-num" style={{ fontWeight: 600, color: row.warn ? 'var(--erp-warning)' : undefined }}>{row.right}</div>
                </div>
            ))}
        </div>
    );
}
