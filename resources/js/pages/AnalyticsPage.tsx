import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Chart, { CommonSeriesSettings, Legend, Size, Tooltip } from 'devextreme-react/chart';
import DateBox from 'devextreme-react/date-box';
import PivotGrid, { Export, FieldChooser, FieldPanel } from 'devextreme-react/pivot-grid';
import PivotGridDataSource from 'devextreme/ui/pivot_grid/data_source';
import Page, { Field } from '../components/Page';
import { errorMessage, getList } from '../lib/api';
import { startOfMonth, today } from '../lib/format';

const money = { type: 'fixedPoint' as const, precision: 2 };

/**
 * Drag-and-drop sales analysis: any combination of branch, cashier, category,
 * product, customer, price tier, day and hour against sales, margin and quantity.
 */
export default function AnalyticsPage() {
    const [from, setFrom] = useState(startOfMonth());
    const [to, setTo] = useState(today());
    const pivot = useRef<any>(null);
    const chart = useRef<any>(null);

    const lines = useQuery({
        queryKey: ['analytics-sales', from, to],
        queryFn: async () => (await getList('/analytics/sales-lines', { from, to })).map((row: any) => ({
            ...row,
            quantity: Number(row.quantity), net_sales: Number(row.net_sales), tax: Number(row.tax),
            discount: Number(row.discount), cost: Number(row.cost), margin: Number(row.margin),
        })),
    });

    const dataSource = useMemo(() => new PivotGridDataSource({
        store: lines.data ?? [],
        fields: [
            { caption: 'Category', dataField: 'category', area: 'row', expanded: false },
            { caption: 'Product', dataField: 'product', area: 'row' },
            { caption: 'Branch', dataField: 'branch', area: 'column' },
            { caption: 'Date', dataField: 'sale_date', area: 'filter' },
            { caption: 'Hour of day', dataField: 'hour', area: 'filter' },
            { caption: 'Cashier', dataField: 'cashier', area: 'filter' },
            { caption: 'Customer', dataField: 'customer', area: 'filter' },
            { caption: 'Price tier', dataField: 'price_tier', area: 'filter' },
            { caption: 'Net sales', dataField: 'net_sales', dataType: 'number', summaryType: 'sum', format: money, area: 'data' },
            { caption: 'Margin', dataField: 'margin', dataType: 'number', summaryType: 'sum', format: money, area: 'data' },
            { caption: 'Quantity', dataField: 'quantity', dataType: 'number', summaryType: 'sum', area: 'data' },
            { caption: 'Discount', dataField: 'discount', dataType: 'number', summaryType: 'sum', format: money },
            { caption: 'Tax', dataField: 'tax', dataType: 'number', summaryType: 'sum', format: money },
            { caption: 'Cost', dataField: 'cost', dataType: 'number', summaryType: 'sum', format: money },
        ],
    }), [lines.data]);

    // The chart follows whatever the grid currently shows.
    useEffect(() => {
        if (pivot.current && chart.current) {
            pivot.current.bindChart(chart.current, { dataFieldsDisplayMode: 'splitPanes', alternateDataFields: false });
        }
    }, [dataSource]);

    const count = lines.data?.length ?? 0;

    return (
        <Page title="Sales analysis" subtitle="Drag fields between rows, columns and filters. Right-click a header for the field chooser.">
            <div className="erp-row">
                <Field label="From"><DateBox type="date" value={from} dateSerializationFormat="yyyy-MM-dd" onValueChanged={(e) => setFrom(e.value)} /></Field>
                <Field label="To"><DateBox type="date" value={to} dateSerializationFormat="yyyy-MM-dd" onValueChanged={(e) => setTo(e.value)} /></Field>
                <span className="erp-muted">
                    {lines.isLoading ? 'Loading…' : `${count.toLocaleString()} sale lines`}
                    {count >= 50000 && ' — the limit was reached; shorten the date range for complete figures'}
                </span>
            </div>
            {lines.error && <div className="erp-card erp-error">{errorMessage(lines.error)}</div>}
            <div className="erp-card">
                <Chart onInitialized={(e) => { chart.current = e.component; }}>
                    <Size height={260} />
                    <CommonSeriesSettings type="bar" />
                    <Legend verticalAlignment="bottom" horizontalAlignment="center" />
                    <Tooltip enabled />
                </Chart>
            </div>
            <div className="erp-card">
                <PivotGrid
                    dataSource={dataSource} onInitialized={(e) => { pivot.current = e.component; }}
                    allowSorting allowFiltering allowSortingBySummary showBorders showColumnGrandTotals showRowGrandTotals height={560}
                >
                    <FieldPanel visible showFilterFields allowFieldDragging />
                    <FieldChooser enabled height={480} />
                    <Export enabled />
                </PivotGrid>
            </div>
        </Page>
    );
}
