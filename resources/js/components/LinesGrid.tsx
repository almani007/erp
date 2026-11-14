import { MutableRefObject, ReactNode, useRef } from 'react';
import DataGrid, { Editing } from 'devextreme-react/data-grid';

interface LinesGridProps {
    /** The grid edits this array in place; read `.current` when submitting. */
    linesRef: MutableRefObject<any[]>;
    children: ReactNode;
    height?: number;
}

/** In-memory editable line grid for documents (adjustments, transfers, journals). */
export default function LinesGrid({ linesRef, children, height = 320 }: LinesGridProps) {
    const nextId = useRef(1);

    return (
        <DataGrid
            dataSource={linesRef.current}
            keyExpr="_row"
            height={height}
            showBorders
            columnAutoWidth
            repaintChangesOnly
            onInitNewRow={(e) => {
                e.data._row = nextId.current++;
            }}
        >
            <Editing mode="cell" allowAdding allowUpdating allowDeleting useIcons newRowPosition="last" confirmDelete={false} />
            {children}
        </DataGrid>
    );
}

/** Rows the user actually filled in, without the grid's internal key. */
export function cleanLines(lines: any[]): any[] {
    return lines.map(({ _row, ...line }) => line);
}
