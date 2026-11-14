const TONES: Record<string, string> = {
    active: 'good', completed: 'good', posted: 'good', received: 'good', open: 'good', paid: 'good',
    draft: 'warn', pending: 'warn', in_transit: 'warn', partially_received: 'warn', inactive: 'warn',
    approved: 'info', processing: 'info',
    voided: 'bad', cancelled: 'bad', reversed: 'bad', disabled: 'bad', closed: 'bad', discontinued: 'bad',
};

export function StatusBadge({ value }: { value?: string | null }) {
    if (!value) {
        return null;
    }
    return <span className={`erp-badge ${TONES[value] ?? ''}`}>{value.replace(/_/g, ' ')}</span>;
}

/** `cellRender` for a DataGrid status column. */
export const statusCell = ({ value }: { value?: string }) => <StatusBadge value={value} />;
