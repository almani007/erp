import { useCallback, useEffect, useState } from 'react';
import Button from 'devextreme-react/button';
import CheckBox from 'devextreme-react/check-box';
import DataGrid, { Column, Editing, Lookup, RequiredRule } from 'devextreme-react/data-grid';
import Popup from 'devextreme-react/popup';
import TextArea from 'devextreme-react/text-area';
import TextBox from 'devextreme-react/text-box';
import Page, { Field } from '../../components/Page';
import RecordTypeDesigner, { RecordTypeDraft } from '../../components/RecordTypeDesigner';
import { statusCell } from '../../components/Status';
import { api, getList, notifyError, notifySuccess } from '../../lib/api';
import { FEATURE_LABELS } from '../../lib/config';

interface CustomFieldDef { entity: string; label: string; type: string; options?: string | string[] | null }
interface Template {
    id?: string; code: string; name: string; description: string | null; status?: string;
    features: Record<string, boolean>; custom_fields: CustomFieldDef[] | null; record_types: RecordTypeDraft[] | null;
}

const ENTITIES = [{ id: 'product', name: 'Product' }, { id: 'customer', name: 'Customer' }, { id: 'supplier', name: 'Supplier' }];
const CF_TYPES = [{ id: 'text', name: 'Text' }, { id: 'number', name: 'Number' }, { id: 'date', name: 'Date' }, { id: 'boolean', name: 'Yes / no' }, { id: 'select', name: 'List of choices' }];

/**
 * Platform operator: what each kind of business starts with — feature
 * switches, extra fields on products/customers/suppliers, and record types.
 */
export default function SaasTemplatesPage() {
    const [rows, setRows] = useState<Template[]>([]);
    const [editing, setEditing] = useState<Template | null>(null);
    const load = useCallback(() => getList<Template>('/saas/templates').then(setRows).catch(notifyError), []);
    useEffect(() => { load(); }, [load]);

    const blank: Template = { code: '', name: '', description: '', features: Object.fromEntries(FEATURE_LABELS.map((f) => [f.key, false])), custom_fields: [], record_types: [] };

    return (
        <Page title="Business templates" subtitle="Starting shapes for each kind of business. Applying one adds what is missing and never removes anything."
            actions={<Button text="New template" icon="add" type="default" onClick={() => setEditing(blank)} />}>
            <div className="erp-card">
                <DataGrid dataSource={rows} keyExpr="id" showRowLines hoverStateEnabled columnAutoWidth onRowClick={(e) => setEditing(e.data)}>
                    <Column dataField="name" />
                    <Column dataField="code" width={130} />
                    <Column caption="Features on" calculateCellValue={(t: Template) => FEATURE_LABELS.filter((f) => t.features?.[f.key]).map((f) => f.key).join(', ')} />
                    <Column caption="Record types" calculateCellValue={(t: Template) => (t.record_types ?? []).map((r) => r.name).join(', ')} />
                    <Column caption="Extra fields" width={110} calculateCellValue={(t: Template) => (t.custom_fields ?? []).length} />
                    <Column dataField="status" width={110} cellRender={statusCell} />
                </DataGrid>
            </div>
            {editing && <TemplateEditor value={editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load(); }} />}
        </Page>
    );
}

function TemplateEditor({ value, onClose, onSaved }: { value: Template; onClose: () => void; onSaved: () => void }) {
    const [t, setT] = useState<Template>(() => JSON.parse(JSON.stringify({ ...value, custom_fields: value.custom_fields ?? [], record_types: value.record_types ?? [] })));
    const [designing, setDesigning] = useState<number | 'new' | null>(null);
    const [busy, setBusy] = useState(false);
    const set = (patch: Partial<Template>) => setT((x) => ({ ...x, ...patch }));

    const save = async () => {
        setBusy(true);
        const body = {
            name: t.name, description: t.description, features: t.features,
            custom_fields: (t.custom_fields ?? []).filter((f) => f.label),
            record_types: t.record_types,
            ...(t.id ? { status: t.status } : { code: t.code }),
        };
        try {
            if (t.id) await api.put(`/saas/templates/${t.id}`, body);
            else await api.post('/saas/templates', body);
            notifySuccess('Template saved.');
            onSaved();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    const saveRecordType = (draft: RecordTypeDraft) => {
        const list = [...(t.record_types ?? [])];
        if (designing === 'new') list.push(draft);
        else if (typeof designing === 'number') list[designing] = draft;
        set({ record_types: list });
        setDesigning(null);
    };

    return (
        <Popup visible title={t.id ? `Template: ${t.name}` : 'New template'} width={900} height="auto" maxHeight="94vh" onHiding={onClose} showCloseButton>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 200px', gap: 12 }}>
                <Field label="Name"><TextBox value={t.name} valueChangeEvent="input" onValueChanged={(e) => set({ name: e.value })} /></Field>
                <Field label="Code"><TextBox value={t.code} disabled={!!t.id} onValueChanged={(e) => set({ code: e.value })} /></Field>
            </div>
            <Field label="Description"><TextArea value={t.description ?? ''} height={56} onValueChanged={(e) => set({ description: e.value })} /></Field>

            <div className="erp-nav-section" style={{ padding: '8px 0 4px' }}>Features switched on</div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 6 }}>
                {FEATURE_LABELS.map((f) => (
                    <CheckBox key={f.key} text={f.label} value={!!t.features?.[f.key]} onValueChanged={(e) => set({ features: { ...t.features, [f.key]: e.value } })} />
                ))}
            </div>

            <div className="erp-nav-section" style={{ padding: '12px 0 4px' }}>Extra fields on products, customers and suppliers</div>
            <DataGrid dataSource={t.custom_fields ?? []} showBorders height={200}
                onRowInserted={() => set({ custom_fields: [...(t.custom_fields ?? [])] })}
                onRowUpdated={() => set({ custom_fields: [...(t.custom_fields ?? [])] })}
                onRowRemoved={() => set({ custom_fields: [...(t.custom_fields ?? [])] })}>
                <Editing mode="cell" allowAdding allowUpdating allowDeleting newRowPosition="last" />
                <Column dataField="entity" caption="On" width={130}><Lookup dataSource={ENTITIES} valueExpr="id" displayExpr="name" /><RequiredRule /></Column>
                <Column dataField="label"><RequiredRule /></Column>
                <Column dataField="type" width={150}><Lookup dataSource={CF_TYPES} valueExpr="id" displayExpr="name" /><RequiredRule /></Column>
                <Column dataField="options" caption="Choices (comma separated)" calculateDisplayValue={(r: CustomFieldDef) => (Array.isArray(r.options) ? r.options.join(', ') : r.options)} />
            </DataGrid>

            <div className="erp-nav-section" style={{ padding: '12px 0 4px', display: 'flex', alignItems: 'center', gap: 8 }}>
                Record types
                <Button text="Add" icon="add" stylingMode="text" onClick={() => setDesigning('new')} />
            </div>
            {(t.record_types ?? []).length === 0 && <div className="erp-muted">None.</div>}
            {(t.record_types ?? []).map((r, i) => (
                <div key={`${r.name}-${i}`} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '4px 0' }}>
                    <b style={{ minWidth: 200 }}>{r.name}</b>
                    <span className="erp-muted" style={{ flex: 1 }}>{r.fields.map((f) => f.label).join(', ')}</span>
                    <Button icon="edit" stylingMode="text" hint="Edit" onClick={() => setDesigning(i)} />
                    <Button icon="trash" stylingMode="text" hint="Remove from template"
                        onClick={() => set({ record_types: (t.record_types ?? []).filter((_, j) => j !== i) })} />
                </div>
            ))}

            <div className="erp-actions">
                {t.id && <CheckBox text="Offered to businesses" value={t.status !== 'inactive'} onValueChanged={(e) => set({ status: e.value ? 'active' : 'inactive' })} />}
                <div style={{ flex: 1 }} />
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Saving…' : 'Save template'} type="default" disabled={busy || !t.name || !t.code} onClick={save} />
            </div>
            {designing !== null && (
                <RecordTypeDesigner
                    title="Record type in template"
                    value={designing === 'new' ? null : (t.record_types ?? [])[designing]}
                    onSave={saveRecordType}
                    onClose={() => setDesigning(null)}
                />
            )}
        </Popup>
    );
}
