const moneyFormat = new Intl.NumberFormat('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qtyFormat = new Intl.NumberFormat('en-PK', { maximumFractionDigits: 3 });

export function money(value: unknown): string {
    const n = Number(value ?? 0);
    return Number.isFinite(n) ? moneyFormat.format(n) : '0.00';
}

export function qty(value: unknown): string {
    const n = Number(value ?? 0);
    return Number.isFinite(n) ? qtyFormat.format(n) : '0';
}

export function today(): string {
    return toDateString(new Date());
}

export function toDateString(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function startOfMonth(): string {
    const d = new Date();
    return toDateString(new Date(d.getFullYear(), d.getMonth(), 1));
}

/** DataGrid column formats (numbers arrive from the API as decimal strings). */
export const moneyColumn = { dataType: 'number' as const, format: { type: 'fixedPoint' as const, precision: 2 }, alignment: 'right' as const };
export const qtyColumn = { dataType: 'number' as const, format: { type: 'fixedPoint' as const, precision: 3 }, alignment: 'right' as const };

/** Props for a numeric DataGrid column whose API value is a decimal string. */
export function numberColumn(dataField: string, precision = 2) {
    return {
        dataField,
        dataType: 'number' as const,
        alignment: 'right' as const,
        format: { type: 'fixedPoint' as const, precision },
        calculateCellValue: (row: Record<string, unknown>) => (row[dataField] == null ? null : Number(row[dataField])),
    };
}
