import { useQuery } from '@tanstack/react-query';
import { Column, Lookup } from 'devextreme-react/data-grid';
import { getList } from '../lib/api';

export interface CustomField {
    id: string;
    entity: string;
    key: string;
    label: string;
    type: 'text' | 'number' | 'date' | 'boolean' | 'select';
    options: string[] | null;
    status: string;
}

/** Active tenant-defined fields for products, customers or suppliers. */
export function useCustomFields(entity: 'product' | 'customer' | 'supplier'): CustomField[] {
    const query = useQuery({ queryKey: ['custom-fields', entity], queryFn: () => getList<CustomField>('/custom-fields', { entity }) });

    return (query.data ?? []).filter((field) => field.status === 'active');
}

const DATA_TYPES = { text: 'string', select: 'string', number: 'number', date: 'date', boolean: 'boolean' } as const;

/** DataGrid columns for custom fields; values live under `custom.<key>` on each row. */
export function customColumns(fields: CustomField[]) {
    return fields.map((field) => (
        <Column key={field.key} dataField={`custom.${field.key}`} caption={field.label} dataType={DATA_TYPES[field.type]}>
            {field.type === 'select' && <Lookup dataSource={field.options ?? []} allowClearing />}
        </Column>
    ));
}
