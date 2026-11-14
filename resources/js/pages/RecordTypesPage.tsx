import { useCallback, useEffect, useState } from 'react';
import Button from 'devextreme-react/button';
import DataGrid, { Column } from 'devextreme-react/data-grid';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import Page from '../components/Page';
import RecordTypeDesigner, { RecordTypeDraft } from '../components/RecordTypeDesigner';
import { statusCell } from '../components/Status';
import { api, getList, notifyError, notifySuccess } from '../lib/api';
import { can } from '../lib/store';

interface RecordType {
    id: string; key: string; name: string; plural: string; number_prefix: string;
    fields: { key: string; label: string; type: string; options: string[] | null; required: boolean }[];
    statuses: string[] | null; link_customer: boolean; status: string;
}

interface Template { code: string; name: string; description: string | null; record_types: { name: string }[] | null; custom_fields: { label: string }[] | null }

const changed = () => window.dispatchEvent(new Event('erp-record-types-changed'));

/**
 * The business designs its own record kinds — job cards, appointments,
 * reservations, rental agreements, production batches — each with its own
 * fields, numbering and statuses. Each appears in the menu with a list and form.
 */
export default function RecordTypesPage() {
    const [types, setTypes] = useState<RecordType[]>([]);
    const [editing, setEditing] = useState<RecordType | 'new' | null>(null);
    const [busy, setBusy] = useState(false);
    const [templatesOpen, setTemplatesOpen] = useState(false);
    const canManage = can('organization.settings.manage');

    const load = useCallback(() => getList<RecordType>('/record-types').then(setTypes).catch(notifyError), []);
    useEffect(() => { load(); }, [load]);

    const save = async (draft: RecordTypeDraft) => {
        setBusy(true);
        const body = {
            name: draft.name, plural: draft.plural || null, number_prefix: draft.prefix || null,
            statuses: Array.isArray(draft.statuses) ? draft.statuses.join(',') : draft.statuses || null,
            link_customer: !!draft.link_customer,
            fields: draft.fields.filter((f) => f.label?.trim()),
        };
        try {
            if (draft.id) await api.put(`/record-types/${draft.id}`, body);
            else await api.post('/record-types', body);
            notifySuccess('Saved. It is in the menu under Records.');
            setEditing(null);
            await load();
            changed();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    const toggle = async (t: RecordType) => {
        try {
            await api.put(`/record-types/${t.id}`, { status: t.status === 'active' ? 'inactive' : 'active' });
            await load();
            changed();
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <Page
            title="Custom records"
            subtitle="Define the records your business keeps — with your own fields, numbering and statuses."
            actions={canManage && <>
                <Button text="Start from a template" icon="copy" onClick={() => setTemplatesOpen(true)} />
                <Button text="New record type" icon="add" type="default" onClick={() => setEditing('new')} />
            </>}
        >
            <DataGrid dataSource={types} keyExpr="id" showBorders hoverStateEnabled onRowClick={(e) => canManage && setEditing(e.data)}>
                <Column dataField="plural" caption="Record type" />
                <Column dataField="number_prefix" caption="Numbering" width={110} />
                <Column caption="Fields" width={90} calculateCellValue={(r: RecordType) => r.fields.length} />
                <Column caption="Statuses" calculateCellValue={(r: RecordType) => (r.statuses ?? []).join(' → ')} />
                <Column dataField="link_customer" caption="Customer" dataType="boolean" width={95} />
                <Column dataField="status" width={110} cellRender={statusCell} />
                {canManage && <Column caption="" width={120} cellRender={({ data }) => (
                    <Button text={data.status === 'active' ? 'Switch off' : 'Switch on'} stylingMode="text"
                        onClick={(e) => { e.event?.stopPropagation(); toggle(data); }} />
                )} />}
            </DataGrid>

            {editing && (
                <RecordTypeDesigner
                    title={editing === 'new' ? 'New record type' : `Edit ${editing.name}`}
                    busy={busy}
                    value={editing === 'new' ? null : {
                        id: editing.id, name: editing.name, plural: editing.plural, prefix: editing.number_prefix,
                        statuses: editing.statuses, link_customer: editing.link_customer, fields: editing.fields,
                    }}
                    onSave={save}
                    onClose={() => setEditing(null)}
                />
            )}
            {templatesOpen && <ApplyTemplate onClose={() => setTemplatesOpen(false)} onApplied={() => { load(); changed(); }} />}
        </Page>
    );
}

function ApplyTemplate({ onClose, onApplied }: { onClose: () => void; onApplied: () => void }) {
    const [templates, setTemplates] = useState<Template[]>([]);
    const [code, setCode] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    useEffect(() => { getList<Template>('/business-templates').then(setTemplates).catch(notifyError); }, []);
    const chosen = templates.find((t) => t.code === code);

    const apply = async () => {
        setBusy(true);
        try {
            const { data } = await api.post(`/business-templates/${code}/apply`);
            notifySuccess(`Added ${data.data.record_types_added} record types and ${data.data.custom_fields_added} fields.`);
            onApplied();
            onClose();
            // Business type and feature switches changed too.
            setTimeout(() => window.location.reload(), 600);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title="Start from a business template" width={560} height="auto" onHiding={onClose} showCloseButton>
            <SelectBox dataSource={templates} valueExpr="code" displayExpr="name" value={code} onValueChanged={(e) => setCode(e.value)} placeholder="Choose your kind of business" />
            {chosen && (
                <div style={{ marginTop: 12 }}>
                    {chosen.description && <p className="erp-muted">{chosen.description}</p>}
                    <p><b>Record types:</b> {(chosen.record_types ?? []).map((r) => r.name).join(', ') || 'none'}</p>
                    <p><b>Extra fields:</b> {(chosen.custom_fields ?? []).map((f) => f.label).join(', ') || 'none'}</p>
                    <p className="erp-muted">Sets your business type and feature switches. Adds what is missing; nothing you already have is changed or removed.</p>
                </div>
            )}
            <div className="erp-actions">
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Applying…' : 'Apply'} type="default" disabled={!code || busy} onClick={apply} />
            </div>
        </Popup>
    );
}
