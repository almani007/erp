import { ReactNode, useMemo, useRef } from 'react';
import CustomStore from 'devextreme/data/custom_store';
import { exportDataGrid } from 'devextreme/excel_exporter';
import DataGrid, {
    ColumnChooser, ColumnFixing, Editing, Export, FilterRow, GroupPanel, HeaderFilter, Item, Pager, Paging,
    SearchPanel, StateStoring, Toolbar,
} from 'devextreme-react/data-grid';
import { api, errorMessage, notifyError } from '../lib/api';

interface CrudGridProps {
    /** List endpoint, e.g. "/products". POST creates; PUT `${resource}/${id}` updates; DELETE removes. */
    resource: string;
    /** <Column> elements (and optionally <Summary>). */
    children: ReactNode;
    /** Extra query parameters for the list request. */
    params?: Record<string, unknown>;
    /** Extra fields merged into the body of create requests (e.g. company_id). */
    createDefaults?: Record<string, unknown>;
    canAdd?: boolean;
    canUpdate?: boolean;
    canDelete?: boolean;
    editMode?: 'row' | 'popup';
    /** Reshape rows after loading / values before saving. */
    mapIn?: (row: any) => any;
    mapOut?: (values: any) => any;
    height?: number | string;
    onRowClick?: (row: any) => void;
    /** Name for the Excel export and for remembering the grid layout. */
    name?: string;
}

/**
 * DevExtreme DataGrid bound to a REST resource that uses the `{ data }` envelope.
 * Every grid gets search, filters, grouping, a column chooser, Excel export and a
 * remembered layout (column order, widths, sorting) per browser.
 */
export default function CrudGrid({
    resource, children, params, createDefaults, canAdd = false, canUpdate = false, canDelete = false, editMode = 'row',
    mapIn, mapOut, height = 'calc(100vh - 200px)', onRowClick, name,
}: CrudGridProps) {
    const instance = useRef<any>(null);
    const paramsKey = JSON.stringify(params ?? {});
    const gridName = name ?? resource.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '');

    const store = useMemo(() => {
        // The grid shows a rejected promise's message in its error row.
        const run = async <T,>(request: Promise<{ data: { data: T } }>): Promise<T> => {
            try {
                return (await request).data.data;
            } catch (error) {
                throw new Error(errorMessage(error));
            }
        };

        return new CustomStore({
            key: 'id',
            loadMode: 'raw',
            load: async () => {
                const rows = await run<any[]>(api.get(resource, { params }));
                return mapIn ? rows.map(mapIn) : rows;
            },
            insert: (values) => run(api.post(resource, { ...createDefaults, ...(mapOut ? mapOut(values) : values) })),
            update: (key, values) => run(api.put(`${resource}/${key}`, mapOut ? mapOut(values) : values)),
            remove: (key) => run(api.delete(`${resource}/${key}`)),
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [resource, paramsKey]);

    const exportToExcel = async (e: any) => {
        try {
            const exceljs: any = await import('exceljs');
            const Workbook = exceljs.Workbook ?? exceljs.default.Workbook;
            const workbook = new Workbook();
            await exportDataGrid({ component: e.component, worksheet: workbook.addWorksheet(gridName.slice(0, 30) || 'Export'), autoFilterEnabled: true });
            const buffer = await workbook.xlsx.writeBuffer();
            const url = URL.createObjectURL(new Blob([buffer], { type: 'application/octet-stream' }));
            const link = document.createElement('a');
            link.href = url;
            link.download = `${gridName || 'export'}.xlsx`;
            link.click();
            URL.revokeObjectURL(url);
        } catch (error) {
            notifyError(error);
        }
    };

    return (
        <div className="erp-card">
            <DataGrid
                dataSource={store}
                onInitialized={(e) => { instance.current = e.component; }}
                height={height}
                showBorders={false}
                showRowLines
                showColumnLines={false}
                rowAlternationEnabled
                columnAutoWidth
                allowColumnResizing
                allowColumnReordering
                columnResizingMode="widget"
                hoverStateEnabled={!!onRowClick}
                onRowClick={onRowClick ? (e) => e.rowType === 'data' && onRowClick(e.data) : undefined}
                // Reload after a save so server-computed fields are shown.
                onRowInserted={(e) => e.component.refresh()}
                onRowUpdated={(e) => e.component.refresh()}
                onExporting={exportToExcel}
            >
                <StateStoring enabled type="localStorage" storageKey={`erp-grid-${gridName}`} savingTimeout={500} />
                <SearchPanel visible width={240} placeholder="Search…" />
                <FilterRow visible />
                <HeaderFilter visible />
                <GroupPanel visible emptyPanelText="Drag a column header here to group" />
                <ColumnChooser enabled mode="select" />
                <ColumnFixing enabled />
                <Export enabled />
                <Paging defaultPageSize={25} />
                <Pager visible showPageSizeSelector allowedPageSizes={[25, 50, 100]} showInfo showNavigationButtons />
                <Editing mode={editMode} allowAdding={canAdd} allowUpdating={canUpdate} allowDeleting={canDelete} useIcons />
                <Toolbar>
                    <Item name="groupPanel" />
                    <Item name="addRowButton" showText="always" />
                    <Item location="after" widget="dxButton" options={{ icon: 'refresh', hint: 'Reload', onClick: () => instance.current?.refresh() }} />
                    <Item name="exportButton" />
                    <Item name="columnChooserButton" />
                    <Item name="searchPanel" />
                </Toolbar>
                {children}
            </DataGrid>
        </div>
    );
}
