import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import CheckBox from 'devextreme-react/check-box';
import DataGrid, { Column, FilterRow, MasterDetail, SearchPanel } from 'devextreme-react/data-grid';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import TagBox from 'devextreme-react/tag-box';
import TextArea from 'devextreme-react/text-area';
import TextBox from 'devextreme-react/text-box';
import Page, { Field } from '../components/Page';
import { statusCell } from '../components/Status';
import { api, getList, getOne, notifyError, notifySuccess } from '../lib/api';
import { useSession } from '../lib/store';

/** FBR, WhatsApp / SMS / email, webhooks, API keys and data import. */
export default function IntegrationsPage() {
    return (
        <Page title="Integrations" subtitle="Connect the ERP to FBR, messaging, your website and other systems.">
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="FBR"><Fbr /></Item>
                <Item title="WhatsApp, SMS & email"><Messaging /></Item>
                <Item title="Webhooks"><Webhooks /></Item>
                <Item title="API keys"><ApiKeys /></Item>
                <Item title="Online store"><OnlineStore /></Item>
                <Item title="Import data"><Import /></Item>
            </TabPanel>
        </Page>
    );
}

// ── FBR ───────────────────────────────────────────────────────

function Fbr() {
    const branches = useSession((s) => s.branches);
    const [branchId, setBranchId] = useState<string | null>(branches[0]?.id ?? null);
    const [s, setS] = useState<any>(null);
    const subs = useQuery({ queryKey: ['fbr-subs'], queryFn: () => getList<any>('/integrations/fbr/submissions'), refetchInterval: 30000 });
    const client = useQueryClient();

    useEffect(() => { if (branchId) getOne<any>('/integrations/fbr/settings', { branch_id: branchId }).then(setS).catch(notifyError); }, [branchId]);
    const set = (p: any) => setS((x: any) => ({ ...x, ...p }));
    const save = async () => {
        try { await api.put('/integrations/fbr/settings', { ...s, branch_id: branchId }); notifySuccess('FBR settings saved for this branch.'); } catch (e) { notifyError(e); }
    };
    const retry = async (id: string) => {
        try { await api.post(`/integrations/fbr/submissions/${id}/retry`); client.invalidateQueries({ queryKey: ['fbr-subs'] }); } catch (e) { notifyError(e); }
    };

    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'minmax(360px, 1fr) 1.4fr', gap: 16 }}>
            <div className="erp-card">
                <Field label="Branch"><SelectBox dataSource={branches} valueExpr="id" displayExpr="name" value={branchId} onValueChanged={(e) => setBranchId(e.value)} /></Field>
                {s && (
                    <>
                        <h3 style={{ fontWeight: 600 }}>POS integration (Tier-1 retail)</h3>
                        <CheckBox text="Report every till sale and return to FBR" value={!!s.pos_enabled} onValueChanged={(e) => set({ pos_enabled: e.value })} />
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginTop: 8 }}>
                            <Field label="Environment"><SelectBox dataSource={['sandbox', 'live']} value={s.pos_environment ?? 'sandbox'} onValueChanged={(e) => set({ pos_environment: e.value })} /></Field>
                            <Field label="POS ID (from FBR)"><TextBox value={s.pos_id ?? ''} onValueChanged={(e) => set({ pos_id: e.value })} /></Field>
                        </div>
                        <Field label="Access token"><TextBox mode="password" value={s.pos_token ?? ''} placeholder="Paste the token from FBR / PRAL" onValueChanged={(e) => set({ pos_token: e.value })} /></Field>
                        <Field label="Endpoint (leave empty for FBR's default)"><TextBox value={s.pos_url ?? ''} placeholder={s.default_urls?.pos?.[s.pos_environment ?? 'sandbox']} onValueChanged={(e) => set({ pos_url: e.value })} /></Field>

                        <h3 style={{ fontWeight: 600, marginTop: 16 }}>Digital Invoicing (sales tax invoices)</h3>
                        <CheckBox text="Report back-office invoices through FBR Digital Invoicing" value={!!s.di_enabled} onValueChanged={(e) => set({ di_enabled: e.value })} />
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginTop: 8 }}>
                            <Field label="Environment"><SelectBox dataSource={['sandbox', 'live']} value={s.di_environment ?? 'sandbox'} onValueChanged={(e) => set({ di_environment: e.value })} /></Field>
                            <Field label="Sandbox scenario ID"><TextBox value={s.di_scenario_id ?? ''} placeholder="SN001" onValueChanged={(e) => set({ di_scenario_id: e.value })} /></Field>
                            <Field label="Seller NTN / CNIC"><TextBox value={s.seller_ntn ?? ''} onValueChanged={(e) => set({ seller_ntn: e.value })} /></Field>
                            <Field label="Seller province"><SelectBox dataSource={['Punjab', 'Sindh', 'Khyber Pakhtunkhwa', 'Balochistan', 'Islamabad Capital Territory', 'Gilgit-Baltistan', 'Azad Jammu and Kashmir']} value={s.seller_province ?? null} onValueChanged={(e) => set({ seller_province: e.value })} /></Field>
                        </div>
                        <Field label="Registered business name"><TextBox value={s.seller_name ?? ''} onValueChanged={(e) => set({ seller_name: e.value })} /></Field>
                        <Field label="Business address"><TextBox value={s.seller_address ?? ''} onValueChanged={(e) => set({ seller_address: e.value })} /></Field>
                        <Field label="PRAL token"><TextBox mode="password" value={s.di_token ?? ''} onValueChanged={(e) => set({ di_token: e.value })} /></Field>
                        <p className="erp-muted">Test in sandbox first. Products need their HS / PCT code (on the product) and customers their NTN and province for Digital Invoicing.</p>
                        <div className="erp-actions"><Button text="Save" type="default" onClick={save} /></div>
                    </>
                )}
            </div>
            <div className="erp-card">
                <b>Submissions</b>
                <DataGrid dataSource={subs.data ?? []} keyExpr="id" showRowLines columnAutoWidth height="calc(100vh - 330px)">
                    <FilterRow visible />
                    <Column dataField="created_at" caption="When" dataType="datetime" width={150} sortOrder="desc" />
                    <Column dataField="document_no" caption="Document" />
                    <Column dataField="system" width={90} />
                    <Column dataField="fiscal_invoice_no" caption="FBR no." />
                    <Column dataField="attempts" width={80} />
                    <Column dataField="last_error" caption="Last error" />
                    <Column dataField="status" width={100} cellRender={statusCell} />
                    <Column caption="" width={80} cellRender={({ data }) => data.status !== 'sent' && <Button text="Retry" stylingMode="text" onClick={() => retry(data.id)} />} />
                </DataGrid>
            </div>
        </div>
    );
}

// ── Messaging ─────────────────────────────────────────────────

function Messaging() {
    const [s, setS] = useState<any>(null);
    const log = useQuery({ queryKey: ['message-log'], queryFn: () => getList<any>('/integrations/messages') });
    const [test, setTest] = useState({ channel: 'whatsapp', to: '', body: 'Test message from the ERP' });
    useEffect(() => { getOne<any>('/integrations/messaging/settings').then(setS).catch(notifyError); }, []);
    const set = (p: any) => setS((x: any) => ({ ...x, ...p }));
    const save = async () => { try { await api.put('/integrations/messaging/settings', s); notifySuccess('Saved.'); } catch (e) { notifyError(e); } };
    const send = async () => {
        try { await api.post('/integrations/messages', test); notifySuccess('Sent.'); } catch (e) { notifyError(e); } finally { log.refetch(); }
    };

    if (!s) return null;
    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'minmax(360px, 1fr) 1.4fr', gap: 16 }}>
            <div className="erp-card">
                <h3 style={{ fontWeight: 600, marginTop: 0 }}>WhatsApp Business (Cloud API)</h3>
                <CheckBox text="Send by WhatsApp" value={!!s.whatsapp_enabled} onValueChanged={(e) => set({ whatsapp_enabled: e.value })} />
                <Field label="Phone number ID"><TextBox value={s.whatsapp_phone_number_id ?? ''} onValueChanged={(e) => set({ whatsapp_phone_number_id: e.value })} /></Field>
                <Field label="Permanent access token"><TextBox mode="password" value={s.whatsapp_token ?? ''} onValueChanged={(e) => set({ whatsapp_token: e.value })} /></Field>
                <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 10 }}>
                    <Field label="Approved template (one text parameter)"><TextBox value={s.whatsapp_template ?? ''} placeholder="erp_notice" onValueChanged={(e) => set({ whatsapp_template: e.value })} /></Field>
                    <Field label="Language"><TextBox value={s.whatsapp_template_language ?? 'en'} onValueChanged={(e) => set({ whatsapp_template_language: e.value })} /></Field>
                </div>
                <h3 style={{ fontWeight: 600 }}>SMS gateway</h3>
                <CheckBox text="Send by SMS (used when WhatsApp is off)" value={!!s.sms_enabled} onValueChanged={(e) => set({ sms_enabled: e.value })} />
                <Field label="Gateway URL — {to} and {message} are filled in"><TextBox value={s.sms_url ?? ''} placeholder="https://sms.example.pk/send?key=…&to={to}&text={message}" onValueChanged={(e) => set({ sms_url: e.value })} /></Field>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 2fr', gap: 10 }}>
                    <Field label="Method"><SelectBox dataSource={['GET', 'POST']} value={s.sms_method ?? 'GET'} onValueChanged={(e) => set({ sms_method: e.value })} /></Field>
                    <Field label="POST body (JSON)"><TextBox value={s.sms_body ?? ''} placeholder='{"to":"{to}","text":"{message}"}' onValueChanged={(e) => set({ sms_body: e.value })} /></Field>
                </div>
                <p className="erp-muted">Email uses the mail settings in the server's .env {s.mail_configured ? '(configured).' : '(not configured yet).'}</p>
                <h3 style={{ fontWeight: 600 }}>Automatic messages</h3>
                <CheckBox text="Receipt / invoice link after each sale to a known customer" value={!!s.receipt_after_sale} onValueChanged={(e) => set({ receipt_after_sale: e.value })} />
                <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginTop: 6 }}>
                    <CheckBox text="Overdue invoice reminders, days after due:" value={!!s.overdue_reminders} onValueChanged={(e) => set({ overdue_reminders: e.value })} />
                    <TextBox width={110} value={s.overdue_reminder_days ?? '1,7,30'} onValueChanged={(e) => set({ overdue_reminder_days: e.value })} />
                </div>
                <div style={{ marginTop: 6 }}><CheckBox text="Appointment reminders the evening before" value={!!s.appointment_reminders} onValueChanged={(e) => set({ appointment_reminders: e.value })} /></div>
                <div className="erp-actions"><Button text="Save" type="default" onClick={save} /></div>
                <h3 style={{ fontWeight: 600 }}>Send a test</h3>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.4fr', gap: 10 }}>
                    <SelectBox dataSource={['whatsapp', 'sms', 'email']} value={test.channel} onValueChanged={(e) => setTest({ ...test, channel: e.value })} />
                    <TextBox placeholder="03xx… or email" value={test.to} onValueChanged={(e) => setTest({ ...test, to: e.value })} />
                </div>
                <TextArea height={60} value={test.body} onValueChanged={(e) => setTest({ ...test, body: e.value })} />
                <div className="erp-actions"><Button text="Send test" disabled={!test.to} onClick={send} /></div>
            </div>
            <div className="erp-card">
                <b>Messages sent</b>
                <DataGrid dataSource={log.data ?? []} keyExpr="id" showRowLines columnAutoWidth height="calc(100vh - 330px)">
                    <SearchPanel visible width={220} />
                    <Column dataField="created_at" caption="When" dataType="datetime" width={150} sortOrder="desc" />
                    <Column dataField="channel" width={90} />
                    <Column dataField="recipient" caption="To" />
                    <Column dataField="body" caption="Message" />
                    <Column dataField="error" />
                    <Column dataField="status" width={90} cellRender={statusCell} />
                </DataGrid>
            </div>
        </div>
    );
}

// ── Webhooks ──────────────────────────────────────────────────

function Webhooks() {
    const client = useQueryClient();
    const hooks = useQuery({ queryKey: ['webhooks'], queryFn: () => getList<any>('/integrations/webhooks') });
    const types = useQuery({ queryKey: ['event-types'], queryFn: () => getList<string>('/integrations/webhooks/event-types') }).data ?? [];
    const [f, setF] = useState<any | null>(null);
    const [secret, setSecret] = useState<string | null>(null);

    const save = async () => {
        try {
            if (f.id) { await api.put(`/integrations/webhooks/${f.id}`, f); setF(null); } else { const { data } = await api.post('/integrations/webhooks', f); setSecret(data.data.secret); setF(null); }
            client.invalidateQueries({ queryKey: ['webhooks'] });
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                <Button text="Add webhook" icon="add" type="default" onClick={() => setF({ name: '', url: '', events: ['sales.sale.completed'] })} />
                <span className="erp-muted">Each POST is signed: header X-ERP-Signature = sha256=HMAC-SHA256(body, secret). Failed deliveries are retried for about a day.</span>
            </div>
            <DataGrid dataSource={hooks.data ?? []} keyExpr="id" showRowLines columnAutoWidth onRowClick={(e) => e.rowType === 'data' && setF({ ...e.data })}>
                <Column dataField="name" />
                <Column dataField="url" />
                <Column caption="Events" calculateCellValue={(r: any) => r.events.join(', ')} />
                <Column caption="Last 7 days" calculateCellValue={(r: any) => Object.entries(r.last_7_days ?? {}).map(([k, v]) => `${v} ${k}`).join(', ')} />
                <Column dataField="status" width={100} cellRender={statusCell} />
                <MasterDetail enabled render={({ data }) => <Deliveries id={data.id} />} />
            </DataGrid>
            {f && (
                <Popup visible title={f.id ? f.name : 'New webhook'} width={620} height="auto" onHiding={() => setF(null)} showCloseButton>
                    <Field label="Name"><TextBox value={f.name} onValueChanged={(e) => setF({ ...f, name: e.value })} /></Field>
                    <Field label="URL (https)"><TextBox value={f.url} onValueChanged={(e) => setF({ ...f, url: e.value })} /></Field>
                    <Field label="Events (* = all)"><TagBox dataSource={['*', ...types]} value={f.events} acceptCustomValue onValueChanged={(e) => setF({ ...f, events: e.value })} /></Field>
                    {f.id && <CheckBox text="Paused" value={f.status === 'paused'} onValueChanged={(e) => setF({ ...f, status: e.value ? 'paused' : 'active' })} />}
                    <div className="erp-actions"><Button text="Cancel" onClick={() => setF(null)} /><Button text="Save" type="default" disabled={!f.name || !f.url || !f.events?.length} onClick={save} /></div>
                </Popup>
            )}
            {secret && (
                <Popup visible title="Signing secret — shown once" width={560} height="auto" onHiding={() => setSecret(null)} showCloseButton>
                    <p>Copy this into the receiving system now. It cannot be shown again.</p>
                    <TextBox value={secret} readOnly />
                    <div className="erp-actions"><Button text="Copy" onClick={() => navigator.clipboard?.writeText(secret)} /><Button text="Done" type="default" onClick={() => setSecret(null)} /></div>
                </Popup>
            )}
        </div>
    );
}

function Deliveries({ id }: { id: string }) {
    const q = useQuery({ queryKey: ['deliveries', id], queryFn: () => getList<any>(`/integrations/webhooks/${id}/deliveries`) });
    return (
        <DataGrid dataSource={q.data ?? []} showBorders columnAutoWidth>
            <Column dataField="created_at" dataType="datetime" />
            <Column dataField="event_type" />
            <Column dataField="attempts" />
            <Column dataField="response_code" caption="HTTP" />
            <Column dataField="response_body" caption="Response" />
            <Column dataField="status" cellRender={statusCell} />
        </DataGrid>
    );
}

// ── API keys ──────────────────────────────────────────────────

function ApiKeys() {
    const client = useQueryClient();
    const rows = useQuery({ queryKey: ['api-clients'], queryFn: () => getList<any>('/integrations/api-clients') });
    const roles = useQuery({ queryKey: ['roles'], queryFn: () => getList<any>('/roles') }).data ?? [];
    const [f, setF] = useState<any | null>(null);
    const [key, setKey] = useState<string | null>(null);

    const create = async () => {
        try { const { data } = await api.post('/integrations/api-clients', f); setKey(data.data.api_key); setF(null); client.invalidateQueries({ queryKey: ['api-clients'] }); } catch (e) { notifyError(e); }
    };
    const revoke = async (id: string) => {
        if (!window.confirm('Revoke this key? The other system stops working until it gets a new key.')) return;
        try { await api.post(`/integrations/api-clients/${id}/revoke`); client.invalidateQueries({ queryKey: ['api-clients'] }); } catch (e) { notifyError(e); }
    };

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                <Button text="New API key" icon="add" type="default" onClick={() => setF({ name: '', role: 'cashier' })} />
                <span className="erp-muted">Other systems call /api/v1 with header "Authorization: Bearer &lt;key&gt;" and the access of the role chosen. See README → API.</span>
            </div>
            <DataGrid dataSource={rows.data ?? []} keyExpr="id" showRowLines columnAutoWidth>
                <Column dataField="name" />
                <Column caption="Role" calculateCellValue={(r: any) => r.roles.join(', ')} />
                <Column dataField="last_used_at" caption="Last used" dataType="datetime" />
                <Column dataField="active_keys" caption="Keys" width={80} />
                <Column dataField="status" width={100} cellRender={statusCell} />
                <Column caption="" width={100} cellRender={({ data }) => data.status === 'active' && <Button text="Revoke" stylingMode="text" type="danger" onClick={() => revoke(data.id)} />} />
            </DataGrid>
            {f && (
                <Popup visible title="New API key" width={480} height="auto" onHiding={() => setF(null)} showCloseButton>
                    <Field label="Used by (system name)"><TextBox value={f.name} placeholder="Website, Daraz sync, BI dashboard" onValueChanged={(e) => setF({ ...f, name: e.value })} /></Field>
                    <Field label="Access as role"><SelectBox dataSource={roles.filter((r: any) => r.name !== 'tenant_owner')} valueExpr="name" displayExpr="name" value={f.role} onValueChanged={(e) => setF({ ...f, role: e.value })} /></Field>
                    <div className="erp-actions"><Button text="Cancel" onClick={() => setF(null)} /><Button text="Create" type="default" disabled={!f.name || !f.role} onClick={create} /></div>
                </Popup>
            )}
            {key && (
                <Popup visible title="API key — shown once" width={620} height="auto" onHiding={() => setKey(null)} showCloseButton>
                    <p>Copy it into the other system now. It cannot be shown again; if lost, revoke and create a new one.</p>
                    <TextBox value={key} readOnly />
                    <div className="erp-actions"><Button text="Copy" onClick={() => navigator.clipboard?.writeText(key)} /><Button text="Done" type="default" onClick={() => setKey(null)} /></div>
                </Popup>
            )}
        </div>
    );
}

// ── Import ────────────────────────────────────────────────────

function Import() {
    const [entity, setEntity] = useState('products');
    const [file, setFile] = useState<File | null>(null);
    const [result, setResult] = useState<any>(null);
    const [busy, setBusy] = useState(false);

    const run = async (dryRun: boolean) => {
        if (!file) return;
        setBusy(true);
        const form = new FormData();
        form.append('file', file);
        form.append('dry_run', dryRun ? '1' : '0');
        try {
            const { data } = await api.post(`/integrations/import/${entity}`, form, { headers: { 'Content-Type': 'multipart/form-data' } });
            setResult({ ...data.data, dryRun });
            if (!dryRun) notifySuccess(`Imported ${data.data.saved} rows.`);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };
    const template = async () => {
        const { data } = await api.get(`/integrations/import/${entity}/template`, { responseType: 'blob' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(data);
        a.download = `${entity}-template.csv`;
        a.click();
    };

    return (
        <div className="erp-card" style={{ maxWidth: 900 }}>
            <p>Move over from another system or a spreadsheet. Save the sheet as CSV with the column names in the first row. Check first — nothing is saved — then import.</p>
            <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                <SelectBox width={180} dataSource={[{ id: 'products', name: 'Products' }, { id: 'customers', name: 'Customers' }, { id: 'suppliers', name: 'Suppliers' }]} valueExpr="id" displayExpr="name" value={entity} onValueChanged={(e) => { setEntity(e.value); setResult(null); }} />
                <Button text="Download template" icon="download" onClick={template} />
                <input type="file" accept=".csv,text/csv" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setResult(null); }} />
            </div>
            <div className="erp-actions" style={{ justifyContent: 'flex-start' }}>
                <Button text="Check file" disabled={!file || busy} onClick={() => run(true)} />
                <Button text="Import" type="default" disabled={!file || busy || !result?.dryRun || result.valid === 0} onClick={() => run(false)} />
            </div>
            {result && (
                <div>
                    <p>{result.rows} rows · {result.dryRun ? `${result.valid} ready to import` : `${result.saved} imported`} · {result.errors.length} with problems
                        {entity === 'products' ? ' · an existing SKU is updated' : entity === 'customers' ? ' · an existing phone number is updated' : ' · an existing name is updated'}</p>
                    {result.errors.length > 0 && (
                        <DataGrid dataSource={result.errors} showBorders height={260}>
                            <Column dataField="row" width={80} />
                            <Column dataField="message" caption="Problem" />
                        </DataGrid>
                    )}
                </div>
            )}
        </div>
    );
}

// ── Online store ──────────────────────────────────────────────

function OnlineStore() {
    const branches = useSession((s) => s.branches);
    const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: () => getList<any>('/warehouses') }).data ?? [];
    const [s, setS] = useState<any>(null);
    useEffect(() => { getOne<any>('/storefront/settings').then(setS).catch(notifyError); }, []);
    const set = (p: any) => setS((x: any) => ({ ...x, ...p }));
    const save = async () => { try { await api.put('/storefront/settings', s); notifySuccess('Saved.'); } catch (e) { notifyError(e); } };
    if (!s) return null;
    const url = `${window.location.origin}/store/${s.slug}`;

    return (
        <div className="erp-card" style={{ maxWidth: 760 }}>
            <p>Customers browse your products, order for delivery or pick-up and book appointments at <a href={url} target="_blank" rel="noreferrer">{url}</a>.
                Orders arrive as sales orders marked "online" for you to confirm and invoice; bookings go on the calendar. Hide a product from the store by turning off "Online" on it.</p>
            <CheckBox text="Store is open" value={!!s.enabled} onValueChanged={(e) => set({ enabled: e.value })} />
            <div style={{ marginTop: 6 }}><CheckBox text="Take appointment bookings" value={!!s.booking_enabled} onValueChanged={(e) => set({ booking_enabled: e.value })} /></div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginTop: 10 }}>
                <Field label="Store name"><TextBox value={s.title ?? ''} onValueChanged={(e) => set({ title: e.value })} /></Field>
                <Field label="WhatsApp number"><TextBox value={s.whatsapp ?? ''} onValueChanged={(e) => set({ whatsapp: e.value })} /></Field>
                <Field label="Orders served by branch"><SelectBox dataSource={branches} valueExpr="id" displayExpr="name" value={s.branch_id ?? null} onValueChanged={(e) => set({ branch_id: e.value })} /></Field>
                <Field label="Stock from warehouse"><SelectBox dataSource={warehouses} valueExpr="id" displayExpr="name" value={s.warehouse_id ?? null} onValueChanged={(e) => set({ warehouse_id: e.value })} /></Field>
                <Field label="Minimum order (Rs)"><TextBox value={s.min_order ?? ''} onValueChanged={(e) => set({ min_order: e.value || null })} /></Field>
                <Field label="Delivery charges note"><TextBox value={s.delivery_fee_note ?? ''} placeholder="Free delivery above Rs 3,000 in Gulshan" onValueChanged={(e) => set({ delivery_fee_note: e.value })} /></Field>
            </div>
            <Field label="About the store"><TextArea height={70} value={s.about ?? ''} onValueChanged={(e) => set({ about: e.value })} /></Field>
            <div className="erp-actions"><Button text="Open store" icon="globe" onClick={() => window.open(url, '_blank')} /><Button text="Save" type="default" onClick={save} /></div>
        </div>
    );
}
