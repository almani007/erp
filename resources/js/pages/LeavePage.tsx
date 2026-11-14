import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import CheckBox from 'devextreme-react/check-box';
import DataGrid, { Column, FilterRow, SearchPanel } from 'devextreme-react/data-grid';
import DateBox from 'devextreme-react/date-box';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import TextBox from 'devextreme-react/text-box';
import Page, { Field } from '../components/Page';
import { statusCell } from '../components/Status';
import { api, getList, getOne, notifyError, notifySuccess } from '../lib/api';
import { toDateString, today } from '../lib/format';
import { can } from '../lib/store';

const thisYear = new Date().getFullYear();
const asDate = (v: unknown) => (v instanceof Date ? toDateString(v) : (v as string));

/** Leave: request and balances for everyone; approvals, register, types and holidays for managers. */
export default function LeavePage() {
    const manager = can('people.leave.manage') || can('people.leave.approve');
    return (
        <Page title="Leave" subtitle="Approved leave is written into attendance, so payroll pays or deducts it automatically.">
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="My leave"><MyLeave /></Item>
                {manager && <Item title="Requests"><Requests /></Item>}
                {manager && <Item title="Leave register"><Register /></Item>}
                {can('people.leave.manage') && <Item title="Leave types"><Types /></Item>}
                <Item title="Holidays"><Holidays /></Item>
            </TabPanel>
        </Page>
    );
}

function MyLeave() {
    const client = useQueryClient();
    const [year, setYear] = useState(thisYear);
    const balances = useQuery({ queryKey: ['leave-balances', 'me', year], queryFn: () => getOne<any>('/leave-balances', { year }), retry: false });
    const mine = useQuery({ queryKey: ['leave-requests', 'me', year], queryFn: () => getList<any>('/leave-requests', { year }) });
    const [asking, setAsking] = useState(false);
    const refresh = () => client.invalidateQueries({ queryKey: ['leave-balances'] }).then(() => client.invalidateQueries({ queryKey: ['leave-requests'] }));

    const withdraw = async (r: any) => {
        try { await api.post(`/leave-requests/${r.id}/cancel`, { reason: 'Withdrawn' }); refresh(); } catch (e) { notifyError(e); }
    };

    if (balances.isError) {
        return <div className="erp-card erp-muted">Your login is not linked to an employee record, so you have no leave balance here. Ask HR to link it under HR &amp; payroll.</div>;
    }

    return (
        <>
            <div className="erp-row">
                <SelectBox label="Year" width={120} dataSource={[thisYear - 1, thisYear, thisYear + 1]} value={year} onValueChanged={(e) => setYear(e.value)} />
                <Button text="Request leave" icon="add" type="default" onClick={() => setAsking(true)} />
            </div>
            <div className="erp-kpi-grid">
                {(balances.data?.balances ?? []).map((b: any) => (
                    <div className="erp-kpi" key={b.type_id}>
                        <div className="erp-kpi-label">{b.name}{!b.paid && ' (unpaid)'}</div>
                        <div className="erp-kpi-value">{b.limited ? b.available : '—'}</div>
                        <div className="erp-kpi-note">{b.limited ? `of ${Number(b.entitled) + Number(b.carried)} left · ${b.taken} taken${Number(b.pending) ? ` · ${b.pending} waiting` : ''}` : `${b.taken} taken`}</div>
                    </div>
                ))}
            </div>
            <div className="erp-card">
                <DataGrid dataSource={mine.data ?? []} keyExpr="id" showRowLines columnAutoWidth noDataText="No leave this year.">
                    <Column dataField="type.name" caption="Type" />
                    <Column dataField="from_date" dataType="date" width={110} />
                    <Column dataField="to_date" dataType="date" width={110} />
                    <Column dataField="days" width={70} />
                    <Column dataField="reason" />
                    <Column dataField="decision_note" caption="Manager's note" />
                    <Column dataField="status" width={110} cellRender={statusCell} />
                    <Column caption="" width={100} cellRender={({ data }) => data.status === 'pending' && <Button text="Withdraw" stylingMode="text" onClick={() => withdraw(data)} />} />
                </DataGrid>
            </div>
            {asking && <RequestLeave employeeId={balances.data?.employee?.id} onClose={() => setAsking(false)} onDone={() => { setAsking(false); refresh(); }} />}
        </>
    );
}

function RequestLeave({ employeeId, forOthers, onClose, onDone }: { employeeId?: string; forOthers?: boolean; onClose: () => void; onDone: () => void }) {
    const types = useQuery({ queryKey: ['leave-types'], queryFn: () => getList<any>('/leave-types') }).data?.filter((t) => t.status === 'active') ?? [];
    const employees = useQuery({ queryKey: ['employees'], queryFn: () => getList<any>('/employees'), enabled: !!forOthers }).data ?? [];
    const [f, setF] = useState<any>({ employee_id: employeeId, from_date: today(), to_date: today(), half_day: false });
    const set = (p: any) => setF((x: any) => ({ ...x, ...p }));
    const type = types.find((t) => t.id === f.leave_type_id);

    const save = async () => {
        try {
            const { data } = await api.post('/leave-requests', { ...f, employee_id: forOthers ? f.employee_id : null });
            notifySuccess(`Requested ${data.data.days} day(s). Your manager has been notified.`);
            onDone();
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <Popup visible title="Request leave" width={480} height="auto" onHiding={onClose} showCloseButton>
            {forOthers && (
                <Field label="Employee">
                    <SelectBox dataSource={employees} valueExpr="id" displayExpr={(e: any) => e && `${e.code} — ${e.name}`} searchEnabled value={f.employee_id} onValueChanged={(e) => set({ employee_id: e.value })} />
                </Field>
            )}
            <Field label="Type">
                <SelectBox dataSource={types} valueExpr="id" displayExpr={(t: any) => t && `${t.name}${t.paid ? '' : ' (unpaid)'}`} value={f.leave_type_id} onValueChanged={(e) => set({ leave_type_id: e.value })} />
            </Field>
            <div className="erp-row">
                <Field label="From"><DateBox value={f.from_date} type="date" onValueChanged={(e) => set({ from_date: asDate(e.value), to_date: f.half_day ? asDate(e.value) : f.to_date < asDate(e.value) ? asDate(e.value) : f.to_date })} /></Field>
                <Field label="To"><DateBox value={f.to_date} type="date" disabled={f.half_day} min={f.from_date} onValueChanged={(e) => set({ to_date: asDate(e.value) })} /></Field>
            </div>
            {type?.allow_half_day !== false && <CheckBox text="Half day" value={f.half_day} onValueChanged={(e) => set({ half_day: e.value, to_date: e.value ? f.from_date : f.to_date })} />}
            <Field label="Reason"><TextBox value={f.reason ?? ''} onValueChanged={(e) => set({ reason: e.value })} /></Field>
            <div className="erp-muted">Holidays and weekly off days inside the dates are not counted.</div>
            <div className="erp-actions">
                <Button text="Cancel" onClick={onClose} />
                <Button text="Send request" type="default" disabled={!f.leave_type_id || (forOthers && !f.employee_id)} onClick={save} />
            </div>
        </Popup>
    );
}

function Requests() {
    const client = useQueryClient();
    const [status, setStatus] = useState<string | null>('pending');
    const rows = useQuery({ queryKey: ['leave-requests', 'all', status], queryFn: () => getList<any>('/leave-requests', status ? { status } : {}) });
    const [adding, setAdding] = useState(false);
    const refresh = () => client.invalidateQueries({ queryKey: ['leave-requests'] });

    const decide = async (r: any, decision: 'approve' | 'reject') => {
        const note = window.prompt(decision === 'reject' ? 'Why is it rejected?' : 'Note (optional)') ?? undefined;
        if (decision === 'reject' && !note) return;
        try {
            await api.post(`/leave-requests/${r.id}/decide`, { decision, note: note || null });
            notifySuccess(decision === 'approve' ? 'Approved and marked in attendance.' : 'Rejected.');
            refresh();
        } catch (e) { notifyError(e); }
    };
    const cancel = async (r: any) => {
        const reason = window.prompt('Why is this leave cancelled?');
        if (!reason) return;
        try { await api.post(`/leave-requests/${r.id}/cancel`, { reason }); notifySuccess('Cancelled; attendance cleared for those days.'); refresh(); } catch (e) { notifyError(e); }
    };

    return (
        <>
            <div className="erp-row">
                <SelectBox label="Show" width={180} dataSource={[{ id: 'pending', n: 'Waiting' }, { id: 'approved', n: 'Approved' }, { id: 'rejected', n: 'Rejected' }, { id: 'cancelled', n: 'Cancelled' }]}
                    valueExpr="id" displayExpr="n" showClearButton placeholder="All" value={status} onValueChanged={(e) => setStatus(e.value)} />
                {can('people.leave.manage') && <Button text="Leave for an employee" icon="add" onClick={() => setAdding(true)} />}
            </div>
            <div className="erp-card">
                <DataGrid dataSource={rows.data ?? []} keyExpr="id" showRowLines columnAutoWidth height="calc(100vh - 320px)">
                    <SearchPanel visible width={240} />
                    <Column dataField="employee.code" caption="Code" width={90} />
                    <Column dataField="employee.name" caption="Employee" />
                    <Column dataField="employee.department" caption="Department" />
                    <Column dataField="type.name" caption="Type" />
                    <Column dataField="from_date" dataType="date" width={110} />
                    <Column dataField="to_date" dataType="date" width={110} />
                    <Column dataField="days" width={70} />
                    <Column dataField="reason" />
                    <Column dataField="decision_note" caption="Note" />
                    <Column dataField="status" width={110} cellRender={statusCell} />
                    <Column caption="" width={230} cellRender={({ data }) => (
                        <>
                            {data.status === 'pending' && can('people.leave.approve') && <Button text="Approve" type="success" stylingMode="text" onClick={() => decide(data, 'approve')} />}
                            {data.status === 'pending' && can('people.leave.approve') && <Button text="Reject" type="danger" stylingMode="text" onClick={() => decide(data, 'reject')} />}
                            {data.status === 'approved' && can('people.leave.manage') && <Button text="Cancel" stylingMode="text" onClick={() => cancel(data)} />}
                        </>
                    )} />
                </DataGrid>
            </div>
            {adding && <RequestLeave forOthers onClose={() => setAdding(false)} onDone={() => { setAdding(false); refresh(); }} />}
        </>
    );
}

function Register() {
    const [year, setYear] = useState(thisYear);
    const data = useQuery({ queryKey: ['leave-register', year], queryFn: () => getOne<any>('/leave-register', { year }) }).data;

    return (
        <>
            <div className="erp-row">
                <SelectBox label="Year" width={120} dataSource={[thisYear - 1, thisYear, thisYear + 1]} value={year} onValueChanged={(e) => setYear(e.value)} />
            </div>
            <div className="erp-card">
                <DataGrid dataSource={data?.rows ?? []} keyExpr="employee_id" showRowLines columnAutoWidth height="calc(100vh - 320px)" export={{ enabled: true }}>
                    <FilterRow visible />
                    <Column dataField="code" width={90} fixed />
                    <Column dataField="name" fixed />
                    <Column dataField="department" />
                    {(data?.types ?? []).map((t: any) => (
                        <Column key={t.code} caption={t.name}>
                            <Column dataField={`${t.code}_taken`} caption="Taken" dataType="number" width={80} />
                            {t.paid && <Column dataField={`${t.code}_available`} caption="Left" dataType="number" width={80} />}
                        </Column>
                    ))}
                </DataGrid>
            </div>
        </>
    );
}

function Types() {
    const client = useQueryClient();
    const rows = useQuery({ queryKey: ['leave-types'], queryFn: () => getList<any>('/leave-types') });
    const [editing, setEditing] = useState<any | null>(null);

    const save = async () => {
        try {
            if (editing.id) await api.put(`/leave-types/${editing.id}`, editing); else await api.post('/leave-types', editing);
            setEditing(null);
            client.invalidateQueries({ queryKey: ['leave-types'] });
        } catch (e) { notifyError(e); }
    };
    const set = (p: any) => setEditing((x: any) => ({ ...x, ...p }));

    return (
        <>
            <div className="erp-row"><Button text="New leave type" icon="add" onClick={() => setEditing({ paid: true, days_per_year: 0, carry_forward_max: 0, allow_half_day: true, use_employee_days: false, status: 'active' })} /></div>
            <div className="erp-card">
                <DataGrid dataSource={rows.data ?? []} keyExpr="id" showRowLines columnAutoWidth onRowDblClick={(e) => setEditing({ ...e.data })}>
                    <Column dataField="code" width={80} />
                    <Column dataField="name" />
                    <Column dataField="paid" dataType="boolean" width={70} />
                    <Column caption="Days a year" calculateCellValue={(t: any) => (t.use_employee_days ? "Employee's own" : Number(t.days_per_year) || 'No limit')} />
                    <Column dataField="carry_forward_max" caption="Carry forward up to" width={150} />
                    <Column dataField="allow_half_day" caption="Half days" dataType="boolean" width={90} />
                    <Column dataField="status" width={100} cellRender={statusCell} />
                    <Column caption="" width={80} cellRender={({ data }) => <Button text="Edit" stylingMode="text" onClick={() => setEditing({ ...data })} />} />
                </DataGrid>
            </div>
            {editing && (
                <Popup visible title={editing.id ? 'Edit leave type' : 'New leave type'} width={440} height="auto" onHiding={() => setEditing(null)} showCloseButton>
                    <div className="erp-row">
                        <Field label="Code"><TextBox width={90} value={editing.code ?? ''} onValueChanged={(e) => set({ code: e.value })} /></Field>
                        <Field label="Name"><TextBox value={editing.name ?? ''} onValueChanged={(e) => set({ name: e.value })} /></Field>
                    </div>
                    <CheckBox text="Paid" value={!!editing.paid} onValueChanged={(e) => set({ paid: e.value })} />
                    {editing.paid && (
                        <>
                            <CheckBox text="Use each employee's annual leave days" value={!!editing.use_employee_days} onValueChanged={(e) => set({ use_employee_days: e.value })} />
                            <div className="erp-row">
                                {!editing.use_employee_days && <Field label="Days a year (0 = no limit)"><NumberBox value={Number(editing.days_per_year)} min={0} onValueChanged={(e) => set({ days_per_year: e.value })} /></Field>}
                                <Field label="Carry forward up to"><NumberBox value={Number(editing.carry_forward_max)} min={0} onValueChanged={(e) => set({ carry_forward_max: e.value })} /></Field>
                            </div>
                        </>
                    )}
                    <CheckBox text="Can be taken as half days" value={!!editing.allow_half_day} onValueChanged={(e) => set({ allow_half_day: e.value })} />
                    <Field label="Status"><SelectBox dataSource={['active', 'inactive']} value={editing.status} onValueChanged={(e) => set({ status: e.value })} /></Field>
                    <div className="erp-actions"><Button text="Cancel" onClick={() => setEditing(null)} /><Button text="Save" type="default" onClick={save} /></div>
                </Popup>
            )}
        </>
    );
}

function Holidays() {
    const client = useQueryClient();
    const [year, setYear] = useState(thisYear);
    const rows = useQuery({ queryKey: ['holidays', year], queryFn: () => getList<any>('/holidays', { year }) });
    const branches = useQuery({ queryKey: ['branches'], queryFn: () => getList<any>('/branches') }).data ?? [];
    const [f, setF] = useState<any>({ date: today() });
    const manage = can('people.leave.manage');

    const add = async () => {
        try {
            await api.post('/holidays', f);
            setF({ date: today() });
            client.invalidateQueries({ queryKey: ['holidays'] });
        } catch (e) { notifyError(e); }
    };
    const remove = async (h: any) => {
        try { await api.delete(`/holidays/${h.id}`); client.invalidateQueries({ queryKey: ['holidays'] }); } catch (e) { notifyError(e); }
    };

    return (
        <>
            <div className="erp-row">
                <SelectBox label="Year" width={120} dataSource={[thisYear - 1, thisYear, thisYear + 1]} value={year} onValueChanged={(e) => setYear(e.value)} />
            </div>
            {manage && (
                <div className="erp-row erp-card">
                    <Field label="Date"><DateBox type="date" value={f.date} onValueChanged={(e) => setF({ ...f, date: asDate(e.value) })} /></Field>
                    <Field label="To (for several days)"><DateBox type="date" value={f.to_date ?? null} min={f.date} showClearButton onValueChanged={(e) => setF({ ...f, to_date: e.value ? asDate(e.value) : null })} /></Field>
                    <Field label="Name"><TextBox value={f.name ?? ''} onValueChanged={(e) => setF({ ...f, name: e.value })} /></Field>
                    <Field label="Branch"><SelectBox dataSource={branches} valueExpr="id" displayExpr="name" placeholder="All branches" showClearButton value={f.branch_id ?? null} onValueChanged={(e) => setF({ ...f, branch_id: e.value })} /></Field>
                    <Button text="Add" icon="add" disabled={!f.name} onClick={add} />
                </div>
            )}
            <div className="erp-card">
                <DataGrid dataSource={rows.data ?? []} keyExpr="id" showRowLines columnAutoWidth noDataText="No holidays entered for this year.">
                    <Column dataField="date" dataType="date" width={120} />
                    <Column caption="Day" width={110} calculateCellValue={(h: any) => new Date(h.date).toLocaleDateString(undefined, { weekday: 'long' })} />
                    <Column dataField="name" />
                    <Column caption="Branch" calculateCellValue={(h: any) => branches.find((b: any) => b.id === h.branch_id)?.name ?? 'All'} />
                    {manage && <Column caption="" width={80} cellRender={({ data }) => <Button icon="trash" stylingMode="text" hint="Remove" onClick={() => remove(data)} />} />}
                </DataGrid>
            </div>
        </>
    );
}
