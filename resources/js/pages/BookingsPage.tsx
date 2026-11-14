import { useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import DataGrid, { Column } from 'devextreme-react/data-grid';
import DateBox from 'devextreme-react/date-box';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import Scheduler, { Resource, View } from 'devextreme-react/scheduler';
import SelectBox from 'devextreme-react/select-box';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import TagBox from 'devextreme-react/tag-box';
import TextBox from 'devextreme-react/text-box';
import Page, { Field } from '../components/Page';
import { statusCell } from '../components/Status';
import { api, getList, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { money } from '../lib/format';
import { can, useSession } from '../lib/store';

interface Resource_ { id: string; name: string; kind: string; color: string; working_hours: Record<string, [string, string][]>; service_ids: string[] | null; status: string }
interface Appt { id: string; appointment_no: string; resource_id: string; product_id: string; customer_id: string | null; customer_name: string; customer_phone: string | null; starts_at: string; ends_at: string; status: string; price: string; notes: string | null; sale_id: string | null }

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** Appointment calendar for salons, clinics, workshops and studios. */
export default function BookingsPage() {
    return (
        <Page title="Appointments" subtitle="Book services against staff or rooms; clashes and closed hours are refused.">
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="Calendar"><Calendar /></Item>
                <Item title="Reminders"><Reminders /></Item>
                {can('bookings.setup') && <Item title="Staff & rooms"><Resources /></Item>}
            </TabPanel>
        </Page>
    );
}

function useServices() {
    return useQuery({ queryKey: ['services'], queryFn: () => getList<any>('/products', { limit: 1000 }) }).data?.filter((p: any) => ['service', 'non_stock'].includes(p.product_type) || p.duration_minutes) ?? [];
}

function Calendar() {
    const client = useQueryClient();
    const branchId = useSession((s) => s.activeBranchId);
    const [date, setDate] = useState(new Date());
    const from = new Date(date.getFullYear(), date.getMonth(), date.getDate() - 7).toISOString();
    const to = new Date(date.getFullYear(), date.getMonth(), date.getDate() + 35).toISOString();
    const resources = useQuery({ queryKey: ['booking-resources'], queryFn: () => getList<Resource_>('/booking-resources') }).data?.filter((r) => r.status === 'active') ?? [];
    const appts = useQuery({ queryKey: ['appointments', branchId, from], queryFn: () => getList<Appt>('/appointments', { from, to }), refetchInterval: 30000 });
    const [editing, setEditing] = useState<Partial<Appt> | null>(null);
    const services = useServices();
    const refresh = () => client.invalidateQueries({ queryKey: ['appointments'] });

    const data = useMemo(() => (appts.data ?? []).filter((a) => !['cancelled'].includes(a.status)).map((a) => ({
        ...a, text: `${a.customer_name} · ${services.find((s: any) => s.id === a.product_id)?.name ?? ''}`, startDate: a.starts_at, endDate: a.ends_at,
    })), [appts.data, services]);

    return (
        <div className="erp-card">
            <Scheduler dataSource={data} currentDate={date} onCurrentDateChange={(d) => setDate(new Date(d))} defaultCurrentView="day" height="calc(100vh - 280px)"
                groups={resources.length > 1 ? ['resource_id'] : undefined} startDayHour={8} endDayHour={22} cellDuration={15} showAllDayPanel={false}
                editing={{ allowAdding: true, allowUpdating: false, allowDeleting: false, allowDragging: false, allowResizing: false }}
                onAppointmentFormOpening={(e) => {
                    e.cancel = true;
                    const d: any = e.appointmentData;
                    setEditing(d?.id ? d : { starts_at: new Date(d.startDate).toISOString(), resource_id: d.resource_id ?? resources[0]?.id });
                }}>
                <View type="day" />
                <View type="week" />
                <View type="month" />
                <View type="agenda" />
                <Resource dataSource={resources.map((r) => ({ id: r.id, text: r.name, color: r.color }))} fieldExpr="resource_id" label="With" useColorAsDefault />
            </Scheduler>
            {editing && <AppointmentDialog value={editing} resources={resources} services={services} onClose={() => setEditing(null)} onChanged={() => { setEditing(null); refresh(); }} />}
        </div>
    );
}

function AppointmentDialog({ value, resources, services, onClose, onChanged }: { value: Partial<Appt>; resources: Resource_[]; services: any[]; onClose: () => void; onChanged: () => void }) {
    const customers = useQuery({ queryKey: ['customers'], queryFn: () => getList<any>('/customers') }).data ?? [];
    const [f, setF] = useState<any>({ ...value });
    const [paying, setPaying] = useState(false);
    const [tender, setTender] = useState({ method: 'cash', amount: Number(value.price ?? 0) });
    const [key] = useState(newIdempotencyKey);
    const service = services.find((s) => s.id === f.product_id);
    const existing = !!value.id;
    const active = !existing || ['booked', 'confirmed', 'arrived'].includes(value.status ?? '');

    const save = async () => {
        const body = { resource_id: f.resource_id, product_id: f.product_id, starts_at: f.starts_at, customer_id: f.customer_id ?? null, customer_name: f.customer_name,
            customer_phone: f.customer_phone ?? null, price: f.price ?? null, notes: f.notes ?? null, duration_minutes: f.duration_minutes ?? null };
        try {
            if (existing) await api.put(`/appointments/${value.id}`, body); else await api.post('/appointments', body);
            notifySuccess(existing ? 'Appointment moved.' : 'Booked.');
            onChanged();
        } catch (e) {
            notifyError(e);
        }
    };
    const status = async (s: string) => {
        try { await api.post(`/appointments/${value.id}/status`, { status: s }); notifySuccess(`Marked ${s.replace('_', ' ')}.`); onChanged(); } catch (e) { notifyError(e); }
    };
    const bill = async () => {
        try {
            const { data } = await api.post(`/appointments/${value.id}/bill`, { tenders: [tender] }, idempotent(key));
            notifySuccess(`Billed ${data.data.sale_no} · ${money(data.data.grand_total)}`);
            onChanged();
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <Popup visible title={existing ? `${value.appointment_no} · ${value.customer_name}` : 'New appointment'} width={640} height="auto" maxHeight="94vh" onHiding={onClose} showCloseButton>
            {existing && <div style={{ marginBottom: 8 }}>{statusCell({ value: value.status })}</div>}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                <Field label="Service"><SelectBox dataSource={services} valueExpr="id" displayExpr={(s: any) => (s ? `${s.name}${s.duration_minutes ? ` · ${s.duration_minutes} min` : ''}` : '')} searchEnabled disabled={!active}
                    value={f.product_id ?? null} onValueChanged={(e) => { const s = services.find((x) => x.id === e.value); setF({ ...f, product_id: e.value, price: Number(s?.sale_price ?? 0) }); }} /></Field>
                <Field label="With"><SelectBox dataSource={resources} valueExpr="id" displayExpr="name" disabled={!active} value={f.resource_id ?? null} onValueChanged={(e) => setF({ ...f, resource_id: e.value })} /></Field>
                <Field label="Start"><DateBox type="datetime" disabled={!active} value={f.starts_at} interval={15} onValueChanged={(e) => e.value && setF({ ...f, starts_at: new Date(e.value).toISOString() })} /></Field>
                <Field label={`Minutes (default ${service?.duration_minutes ?? 30})`}><NumberBox min={5} step={5} disabled={!active} value={f.duration_minutes ?? null} showClearButton onValueChanged={(e) => setF({ ...f, duration_minutes: e.value })} /></Field>
                <Field label="Existing customer"><SelectBox dataSource={customers} valueExpr="id" displayExpr="name" searchEnabled showClearButton disabled={!active} value={f.customer_id ?? null}
                    onValueChanged={(e) => { const c = customers.find((x: any) => x.id === e.value); setF({ ...f, customer_id: e.value, customer_name: c?.name ?? f.customer_name, customer_phone: c?.phone ?? f.customer_phone }); }} /></Field>
                <Field label="Name"><TextBox disabled={!active} value={f.customer_name ?? ''} onValueChanged={(e) => setF({ ...f, customer_name: e.value })} /></Field>
                <Field label="Phone (for reminders)"><TextBox disabled={!active} value={f.customer_phone ?? ''} onValueChanged={(e) => setF({ ...f, customer_phone: e.value })} /></Field>
                <Field label="Price"><NumberBox min={0} format="#,##0.00" disabled={!active} value={f.price ?? 0} onValueChanged={(e) => setF({ ...f, price: e.value })} /></Field>
            </div>
            <Field label="Notes"><TextBox disabled={!active} value={f.notes ?? ''} onValueChanged={(e) => setF({ ...f, notes: e.value })} /></Field>
            {paying && (
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                    <Field label="Paid by"><SelectBox dataSource={[{ id: 'cash', name: 'Cash' }, { id: 'card', name: 'Card' }, { id: 'wallet', name: 'Wallet' }]} valueExpr="id" displayExpr="name" value={tender.method} onValueChanged={(e) => setTender({ ...tender, method: e.value })} /></Field>
                    <Field label="Amount received (incl. tax)"><NumberBox min={0} format="#,##0.00" value={tender.amount} onValueChanged={(e) => setTender({ ...tender, amount: e.value ?? 0 })} /></Field>
                </div>
            )}
            <div className="erp-actions" style={{ flexWrap: 'wrap' }}>
                {existing && value.status === 'booked' && <Button text="Confirmed" onClick={() => status('confirmed')} />}
                {existing && ['booked', 'confirmed'].includes(value.status ?? '') && <Button text="Arrived" type="default" onClick={() => status('arrived')} />}
                {existing && ['booked', 'confirmed'].includes(value.status ?? '') && <Button text="No-show" stylingMode="outlined" onClick={() => status('no_show')} />}
                {existing && active && <Button text="Cancel booking" stylingMode="outlined" type="danger" onClick={() => status('cancelled')} />}
                <div style={{ flex: 1 }} />
                {existing && !value.sale_id && can('sales.sale.create') && !['cancelled', 'no_show'].includes(value.status ?? '') && (paying
                    ? <Button text="Take payment" type="success" onClick={bill} />
                    : <Button text="Bill" icon="money" type="success" onClick={() => setPaying(true)} />)}
                {active && <Button text={existing ? 'Save changes' : 'Book'} type="default" disabled={!f.product_id || !f.resource_id || !f.customer_name} onClick={save} />}
            </div>
        </Popup>
    );
}

function Reminders() {
    const client = useQueryClient();
    const rows = useQuery({ queryKey: ['reminders'], queryFn: () => getList<any>('/appointments/reminders') });
    const send = async (r: any) => {
        window.open(r.whatsapp_url, '_blank');
        try { await api.post(`/appointments/${r.id}/reminded`); client.invalidateQueries({ queryKey: ['reminders'] }); } catch (e) { notifyError(e); }
    };
    return (
        <div className="erp-card">
            <p className="erp-muted">Tomorrow's appointments. Send opens WhatsApp with the message ready.</p>
            <DataGrid dataSource={rows.data ?? []} keyExpr="id" showRowLines columnAutoWidth>
                <Column dataField="starts_at" caption="Time" dataType="datetime" format="hh:mm a" width={110} />
                <Column dataField="customer_name" caption="Customer" />
                <Column dataField="customer_phone" caption="Phone" />
                <Column dataField="status" width={110} cellRender={statusCell} />
                <Column dataField="reminded_at" caption="Reminded" dataType="datetime" width={160} />
                <Column caption="" width={150} cellRender={({ data }) => <Button text="Send on WhatsApp" stylingMode="text" type="success" onClick={() => send(data)} />} />
            </DataGrid>
        </div>
    );
}

function Resources() {
    const client = useQueryClient();
    const rows = useQuery({ queryKey: ['booking-resources'], queryFn: () => getList<Resource_>('/booking-resources') });
    const services = useServices();
    const [editing, setEditing] = useState<any | null>(null);
    const blank = { name: '', kind: 'staff', color: '#2563eb', working_hours: Object.fromEntries([1, 2, 3, 4, 5, 6].map((d) => [String(d), [['10:00', '20:00']]])), service_ids: null };

    const save = async () => {
        try {
            if (editing.id) await api.put(`/booking-resources/${editing.id}`, editing); else await api.post('/booking-resources', editing);
            notifySuccess('Saved.');
            setEditing(null);
            client.invalidateQueries({ queryKey: ['booking-resources'] });
        } catch (e) {
            notifyError(e);
        }
    };
    const hours = (d: number) => (editing.working_hours[String(d)] ?? []).map((r: string[]) => r.join('-')).join(', ');
    const setHours = (d: number, text: string) => {
        const ranges = text.split(',').map((s) => s.trim()).filter(Boolean).map((s) => s.split('-').map((x) => x.trim()));
        const wh = { ...editing.working_hours };
        if (ranges.length) wh[String(d)] = ranges; else delete wh[String(d)];
        setEditing({ ...editing, working_hours: wh });
    };

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}><Button text="Add staff member / room" icon="add" type="default" onClick={() => setEditing(blank)} /></div>
            <DataGrid dataSource={rows.data ?? []} keyExpr="id" showRowLines hoverStateEnabled onRowClick={(e) => e.rowType === 'data' && setEditing(JSON.parse(JSON.stringify(e.data)))}>
                <Column dataField="name" />
                <Column dataField="kind" width={110} />
                <Column caption="Hours" calculateCellValue={(r: Resource_) => Object.entries(r.working_hours).map(([d, rs]) => `${DAYS[Number(d) - 1]} ${rs.map((x) => x.join('-')).join(',')}`).join(' · ')} />
                <Column dataField="status" width={110} cellRender={statusCell} />
            </DataGrid>
            {editing && (
                <Popup visible title={editing.id ? editing.name : 'New staff member or room'} width={640} height="auto" maxHeight="92vh" onHiding={() => setEditing(null)} showCloseButton>
                    <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr', gap: 12 }}>
                        <Field label="Name"><TextBox value={editing.name} onValueChanged={(e) => setEditing({ ...editing, name: e.value })} /></Field>
                        <Field label="Type"><SelectBox dataSource={[{ id: 'staff', name: 'Staff' }, { id: 'room', name: 'Room / chair' }, { id: 'equipment', name: 'Equipment' }]} valueExpr="id" displayExpr="name" value={editing.kind} onValueChanged={(e) => setEditing({ ...editing, kind: e.value })} /></Field>
                        <Field label="Colour"><TextBox value={editing.color} onValueChanged={(e) => setEditing({ ...editing, color: e.value })} /></Field>
                    </div>
                    <div className="erp-muted" style={{ margin: '8px 0 4px' }}>Working hours, e.g. 10:00-13:00, 14:00-20:00 (empty = day off)</div>
                    <div style={{ display: 'grid', gridTemplateColumns: '60px 1fr', gap: 6, alignItems: 'center' }}>
                        {[1, 2, 3, 4, 5, 6, 7].map((d) => [<span key={`l${d}`}>{DAYS[d - 1]}</span>, <TextBox key={`t${d}`} value={hours(d)} onValueChanged={(e) => setHours(d, e.value)} />])}
                    </div>
                    <Field label="Services offered (empty = all)">
                        <TagBox dataSource={services} valueExpr="id" displayExpr="name" searchEnabled value={editing.service_ids ?? []} onValueChanged={(e) => setEditing({ ...editing, service_ids: e.value.length ? e.value : null })} />
                    </Field>
                    <div className="erp-actions">
                        {editing.id && <Button text={editing.status === 'inactive' ? 'Activate' : 'Deactivate'} stylingMode="outlined" onClick={() => setEditing({ ...editing, status: editing.status === 'inactive' ? 'active' : 'inactive' })} />}
                        <div style={{ flex: 1 }} /><Button text="Cancel" onClick={() => setEditing(null)} /><Button text="Save" type="default" disabled={!editing.name} onClick={save} />
                    </div>
                </Popup>
            )}
        </div>
    );
}

