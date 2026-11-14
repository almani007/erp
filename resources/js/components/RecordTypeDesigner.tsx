import { useState } from 'react';
import Button from 'devextreme-react/button';
import CheckBox from 'devextreme-react/check-box';
import DataGrid, { Column, Editing, Lookup, RequiredRule, RowDragging } from 'devextreme-react/data-grid';
import Popup from 'devextreme-react/popup';
import TextBox from 'devextreme-react/text-box';
import { Field } from './Page';

export interface FieldDef {
    key?: string;
    label: string;
    type: string;
    /** Choices for a list field, comma separated in the designer. */
    options?: string | string[] | null;
    required?: boolean;
}

export interface RecordTypeDraft {
    id?: string;
    name: string;
    plural?: string;
    prefix?: string;
    /** Comma separated, first one is the starting status. */
    statuses?: string | string[] | null;
    link_customer?: boolean;
    fields: FieldDef[];
}

export const FIELD_TYPES = [
    { id: 'text', name: 'Text' },
    { id: 'longtext', name: 'Long text / notes' },
    { id: 'number', name: 'Number' },
    { id: 'money', name: 'Amount (money)' },
    { id: 'date', name: 'Date' },
    { id: 'datetime', name: 'Date and time' },
    { id: 'boolean', name: 'Yes / no' },
    { id: 'select', name: 'List of choices' },
];

const csv = (v: string | string[] | null | undefined) => (Array.isArray(v) ? v.join(', ') : (v ?? ''));

/**
 * Designs a record type: its name, numbering, status list and fields.
 * Used by a business for its own records and by the platform operator
 * inside business templates. Fields already in use keep their key and type.
 */
export default function RecordTypeDesigner({ value, title, busy, onSave, onClose }: {
    value: RecordTypeDraft | null;
    title: string;
    busy?: boolean;
    onSave: (draft: RecordTypeDraft) => void;
    onClose: () => void;
}) {
    const [draft, setDraft] = useState<RecordTypeDraft>(() => ({
        name: value?.name ?? '',
        plural: value?.plural ?? '',
        prefix: value?.prefix ?? '',
        statuses: csv(value?.statuses),
        link_customer: value?.link_customer ?? false,
        id: value?.id,
        // Copies, so cancelling leaves the original untouched.
        fields: (value?.fields ?? []).map((f) => ({ ...f, options: csv(f.options), required: !!f.required })),
    }));
    const existingKeys = new Set((value?.fields ?? []).map((f) => f.key).filter(Boolean));
    const set = (patch: Partial<RecordTypeDraft>) => setDraft((d) => ({ ...d, ...patch }));

    return (
        <Popup visible title={title} width={860} height="auto" maxHeight="92vh" onHiding={onClose} showCloseButton>
            <div className="erp-form-grid" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 140px', gap: 12 }}>
                <Field label="Name (one record)">
                    <TextBox value={draft.name} placeholder="Job card" valueChangeEvent="input" onValueChanged={(e) => set({ name: e.value })} />
                </Field>
                <Field label="Menu name (many)">
                    <TextBox value={draft.plural ?? ''} placeholder="Job cards" valueChangeEvent="input" onValueChanged={(e) => set({ plural: e.value })} />
                </Field>
                <Field label="Number prefix">
                    <TextBox value={draft.prefix ?? ''} placeholder="JOB" maxLength={10} disabled={!!draft.id} onValueChanged={(e) => set({ prefix: e.value })} />
                </Field>
            </div>
            <Field label="Statuses, in order (comma separated — leave empty for none)">
                <TextBox value={csv(draft.statuses)} placeholder="Open, In progress, Done" valueChangeEvent="input" onValueChanged={(e) => set({ statuses: e.value })} />
            </Field>
            <div style={{ margin: '8px 0 12px' }}>
                <CheckBox text="Each record belongs to a customer" value={!!draft.link_customer} onValueChanged={(e) => set({ link_customer: e.value })} />
            </div>

            <div className="erp-muted" style={{ marginBottom: 6 }}>Fields — drag to reorder. Removing a field hides it; values already saved are kept.</div>
            <DataGrid
                dataSource={draft.fields}
                showBorders
                height={320}
                onRowInserted={() => set({ fields: [...draft.fields] })}
                onRowUpdated={() => set({ fields: [...draft.fields] })}
                onRowRemoved={() => set({ fields: [...draft.fields] })}
            >
                <Editing mode="cell" allowAdding allowUpdating allowDeleting newRowPosition="last" />
                <RowDragging allowReordering showDragIcons onReorder={(e: any) => {
                    const fields = [...draft.fields];
                    const [moved] = fields.splice(e.fromIndex, 1);
                    fields.splice(e.toIndex, 0, moved);
                    set({ fields });
                }} />
                <Column dataField="label" caption="Label"><RequiredRule /></Column>
                <Column dataField="type" caption="Type" width={170}>
                    <Lookup dataSource={FIELD_TYPES} valueExpr="id" displayExpr="name" />
                </Column>
                <Column dataField="options" caption="Choices (for lists)" />
                <Column dataField="required" caption="Required" dataType="boolean" width={90} />
                <Column caption="" width={80} cellRender={({ data }) => (data.key && existingKeys.has(data.key) ? <span className="erp-muted">in use</span> : null)} />
            </DataGrid>

            <div className="erp-actions">
                <Button text="Cancel" onClick={onClose} />
                <Button
                    text={busy ? 'Saving…' : 'Save'}
                    type="default"
                    disabled={busy || !draft.name.trim() || draft.fields.filter((f) => f.label?.trim()).length === 0}
                    onClick={() => onSave({ ...draft, fields: draft.fields.map((f) => ({ ...f, type: f.type || 'text' })) })}
                />
            </div>
        </Popup>
    );
}
