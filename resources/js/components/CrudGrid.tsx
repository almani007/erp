import { ReactNode, useMemo } from 'react';
import CustomStore from 'devextreme/data/custom_store';
import DataGrid, { Editing, FilterRow, HeaderFilter, Pager, Paging, SearchPanel } from 'devextreme-react/data-grid';
import { api, errorMessage } from '../lib/api';

interface CrudGridProps {
    /** List endpoint, e.g. "/products". POST creates; PUT `${resource}/${id}` updates. */
    resource: string;
    /** <Column> elements. */
    children: ReactNode;
    /** Extra query parameters for the list request. */
    params?: Record<string, unknown>;
    /** Extra fields merged into the body of create requests (e.g. company_id). */
    createDefaults?: Record<string, unknown>;
    canAdd?: boolean;
    canUpdate?: boolean;
    editMode?: 'row' | 'popup';
    /** Reshape rows after loading / values before saving. */
    mapIn?: (row: any) => any;
    mapOut?: (values: any) => any;
    height?: number | string;
    onRowClick?: (row: any) => void;
}

/** DevExtreme DataGrid bound to a REST resource that uses the `{ data }` envelope. */
export default function CrudGrid({
    resource, children, params, createDefaults, canAdd = false, canUpdate = false, editMode = 'row',
    mapIn, mapOut, height = 'calc(100vh - 190px)', onRowClick,
}: CrudGridProps) {
    const paramsKey = JSON.stringify(params ?? {});

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
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [resource, paramsKey]);

    return (
        <div className="erp-card">
            <DataGrid
                dataSource={store}
                height={height}
                showBorders
                rowAlternationEnabled
                columnAutoWidth
                allowColumnResizing
                hoverStateEnabled={!!onRowClick}
                onRowClick={onRowClick ? (e) => e.rowType === 'data' && onRowClick(e.data) : undefined}
                // Reload after a save so server-computed fields are shown.
                onRowInserted={(e) => e.component.refresh()}
                onRowUpdated={(e) => e.component.refresh()}
            >
                <SearchPanel visible width={260} />
                <FilterRow visible />
                <HeaderFilter visible />
                <Paging defaultPageSize={25} />
                <Pager showPageSizeSelector allowedPageSizes={[25, 50, 100]} showInfo />
                <Editing mode={editMode} allowAdding={canAdd} allowUpdating={canUpdate} useIcons />
                {children}
            </DataGrid>
        </div>
    );
}
