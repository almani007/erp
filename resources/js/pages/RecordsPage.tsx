import { useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Column, FormItem, Lookup, RequiredRule } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import Page from '../components/Page';
import { statusCell } from '../components/Status';
import { getList, notifyError } from '../lib/api';
import { can } from '../lib/store';

interface FieldDef { key: string; label: string; type: string; options: string[] | null; required: boolean }
interface RecordType { id: string; key: string; name: string; plural: string; fields: FieldDef[]; statuses: string[] | null; link_customer: boolean }

/** Field values live under `data`; the grid sees them as flat `f__<key>` columns. */
const col = (key: string) => `f__${key}`;

const pad = (n: number) => String(n).padStart(2, '0');
function serialise(value: unknown, type: string): unknown {
    if (!(value instanceof Date)) return value;
    const d = `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`;
    return type === 'datetime' ? `${d} ${pad(value.getHours())}:${pad(value.getMinutes())}:00` : d;
}

/** One screen serves every record type a business defines. */
export default function RecordsPage() {
    const { typeKey = '' } = useParams();
    const [type, setType] = useState<RecordType | null>(null);
    const [customers, setCustomers] = useState<{ id: string; name: string }[]>([]);
    const canManage = can('records.manage');

    useEffect(() => {
        setType(null);
        getList<RecordType>('/record-types')
            .then((types) => {
                const found = types.find((t) => t.key === typeKey) ?? null;
                setType(found);
                if (found?.link_customer) getList('/customers').then(setCustomers).catch(() => setCustomers([]));
            })
            .catch(notifyError);
    }, [typeKey]);

    const mapIn = useMemo(() => (row: any) => {
        const flat: any = { ...row };
        for (const f of type?.fields ?? []) flat[col(f.key)] = row.data?.[f.key] ?? null;
        return flat;
    }, [type]);

    const mapOut = useMemo(() => (values: any) => {
        const out: any = {};
        const data: any = {};
        for (const [k, v] of Object.entries(values)) {
            if (k.startsWith('f__')) {
                const field = type?.fields.find((f) => col(f.key) === k);
                if (field) data[field.key] = serialise(v, field.type);
            } else if (k === 'status' || k === 'customer_id') {
                out[k] = v;
            }
        }
        if (Object.keys(data).length > 0) out.data = data;
        return out;
    }, [type]);

    if (!type) {
        return <Page title="Records"><div className="erp-muted">Loading…</div></Page>;
    }

    return (
        <Page title={type.plural} subtitle={`Your ${type.name.toLowerCase()} records. Change fields in Administration → Custom records.`}>
            <CrudGrid
                key={type.id}
                name={`records-${type.key}`}
                resource={`/records/${type.key}`}
                canAdd={canManage}
                canUpdate={canManage}
                editMode="popup"
                mapIn={mapIn}
                mapOut={mapOut}
            >
                <Column dataField="record_no" caption="No." width={130} allowEditing={false}><FormItem visible={false} /></Column>
                {type.statuses && (
                    <Column dataField="status" width={140} cellRender={statusCell}>
                        <Lookup dataSource={type.statuses} />
                    </Column>
                )}
                {type.link_customer && (
                    <Column dataField="customer_id" caption="Customer">
                        <Lookup dataSource={customers} valueExpr="id" displayExpr="name" allowClearing />
                    </Column>
                )}
                {type.fields.map((f) => (
                    <Column
                        key={f.key}
                        dataField={col(f.key)}
                        caption={f.label}
                        dataType={f.type === 'number' || f.type === 'money' ? 'number'
                            : f.type === 'date' ? 'date' : f.type === 'datetime' ? 'datetime' : f.type === 'boolean' ? 'boolean' : 'string'}
                        format={f.type === 'money' ? '#,##0.00' : undefined}
                    >
                        {f.type === 'select' && <Lookup dataSource={f.options ?? []} />}
                        {f.required && <RequiredRule />}
                        {f.type === 'longtext' && <FormItem editorType="dxTextArea" colSpan={2} editorOptions={{ height: 90 }} />}
                    </Column>
                ))}
                <Column dataField="created_at" caption="Created" dataType="datetime" width={160} allowEditing={false} sortOrder="desc"><FormItem visible={false} /></Column>
            </CrudGrid>
        </Page>
    );
}
