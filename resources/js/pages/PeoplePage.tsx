import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import DataGrid, { Column, Editing, Lookup, RequiredRule, SearchPanel, Summary, TotalItem } from 'devextreme-react/data-grid';
import DateBox from 'devextreme-react/date-box';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import TextBox from 'devextreme-react/text-box';
import Page, { Field } from '../components/Page';
import { statusCell } from '../components/Status';
import { api, getList, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { money, numberColumn, toDateString, today } from '../lib/format';
import { can, useSession } from '../lib/store';

interface Named { name: string; amount: string | number }
interface Employee {
    id: string; company_id: string; branch_id: string | null; code: string; name: string; father_name: string | null; cnic: string | null;
    phone: string | null; email: string | null; address: string | null; department: string | null; designation: string | null;
    join_date: string; leave_date: string | null; pay_type: 'monthly' | 'daily'; basic_salary: string; allowances: Named[] | null;
    deductions: Named[] | null; monthly_tax: string; annual_leave_days: string; bank_name: string | null; bank_account: string | null; status: string;
}
interface Line {
    id: string; employee_id: string; employee: { code: string; name: string; designation: string | null; bank_name: string | null; bank_account: string | null };
    payable_days: string; absent_days: string; overtime_hours: string; basic: string; allowances: string; overtime_amount: string; bonus: string;
    absence_deduction: string; gross: string; fixed_deductions: string; tax: string; other_deduction: string; advance_recovery: string; net: string;
    breakdown: { allowances: Named[]; deductions: Named[]; advances: { advance_no: string; amount: string }[] } | null; note: string | null;
}
interface Run { id: string; run_no: string; period: string; status: string; gross_total: string; net_total: string; deduction_total: string; lines_count?: number; lines?: Line[] }

const STATUSES = [
    { id: 'present', name: 'Present' }, { id: 'absent', name: 'Absent' }, { id: 'leave', name: 'Paid leave' },
    { id: 'unpaid_leave', name: 'Unpaid leave' }, { id: 'half_day', name: 'Half day' }, { id: 'holiday', name: 'Holiday' },
];

/** Staff, the daily register, advances and loans, and monthly payroll. */
export default function PeoplePage() {
    return (
        <Page title="HR & payroll" subtitle="Employees, attendance, advances and monthly salaries — posted to the accounts.">
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="Employees"><Employees /></Item>
                {can('people.attendance.manage') && <Item title="Attendance"><AttendanceSheet /></Item>}
                {can('people.payroll.manage') && <Item title="Payroll"><Payroll /></Item>}
                {can('people.payroll.manage') && <Item title="Advances & loans"><Advances /></Item>}
            </TabPanel>
        </Page>
    );
}

// ── Employees ─────────────────────────────────────────────────

function Employees() {
    const client = useQueryClient();
    const employees = useQuery({ queryKey: ['employees'], queryFn: () => getList<Employee>('/employees') });
    const [editing, setEditing] = useState<Employee | 'new' | null>(null);
    const canManage = can('people.employee.manage');
    const branches = useSession((s) => s.branches);

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                {canManage && <Button text="New employee" icon="add" type="default" onClick={() => setEditing('new')} />}
            </div>
            <DataGrid dataSource={employees.data ?? []} keyExpr="id" showRowLines hoverStateEnabled columnAutoWidth
                onRowClick={(e) => canManage && e.rowType === 'data' && setEditing(e.data)}>
                <SearchPanel visible width={240} />
                <Column dataField="code" width={100} />
                <Column dataField="name" />
                <Column dataField="designation" />
                <Column dataField="department" groupIndex={undefined} />
                <Column dataField="branch_id" caption="Branch"><Lookup dataSource={branches} valueExpr="id" displayExpr="name" /></Column>
                <Column dataField="pay_type" caption="Pay" width={90} />
                <Column {...numberColumn('basic_salary')} caption="Basic / rate" width={120} />
                <Column dataField="join_date" dataType="date" width={110} />
                <Column dataField="status" width={100} cellRender={statusCell} />
            </DataGrid>
            {editing && <EmployeeEditor value={editing === 'new' ? null : editing} onClose={() => setEditing(null)}
                onSaved={() => { setEditing(null); client.invalidateQueries({ queryKey: ['employees'] }); }} />}
        </div>
    );
}

function NamedAmounts({ label, rows, onChange }: { label: string; rows: Named[]; onChange: (rows: Named[]) => void }) {
    return (
        <div>
            <div className="erp-muted" style={{ margin: '8px 0 4px' }}>{label}</div>
            <DataGrid dataSource={rows} showBorders height={150}
                onRowInserted={() => onChange([...rows])} onRowUpdated={() => onChange([...rows])} onRowRemoved={() => onChange([...rows])}>
                <Editing mode="cell" allowAdding allowUpdating allowDeleting newRowPosition="last" />
                <Column dataField="name"><RequiredRule /></Column>
                <Column dataField="amount" dataType="number" width={120} format="#,##0.00"><RequiredRule /></Column>
            </DataGrid>
        </div>
    );
}

function EmployeeEditor({ value, onClose, onSaved }: { value: Employee | null; onClose: () => void; onSaved: () => void }) {
    const branches = useSession((s) => s.branches);
    const [e, setE] = useState<Partial<Employee>>(() => value
        ? { ...value, allowances: [...(value.allowances ?? [])], deductions: [...(value.deductions ?? [])] }
        : { pay_type: 'monthly', join_date: today(), allowances: [], deductions: [], monthly_tax: '0', annual_leave_days: '14' });
    const [busy, setBusy] = useState(false);
    const set = (patch: Partial<Employee>) => setE((x) => ({ ...x, ...patch }));

    const save = async () => {
        setBusy(true);
        const { id, status, ...body } = e as Employee;
        try {
            if (value) await api.put(`/employees/${value.id}`, { ...body, status });
            else await api.post('/employees', body);
            notifySuccess('Employee saved.');
            onSaved();
        } catch (err) {
            notifyError(err);
        } finally {
            setBusy(false);
        }
    };

    const text = (key: keyof Employee, label: string) => (
        <Field label={label}><TextBox value={(e[key] as string) ?? ''} onValueChanged={(v) => set({ [key]: v.value } as Partial<Employee>)} /></Field>
    );

    return (
        <Popup visible title={value ? `${value.code} — ${value.name}` : 'New employee'} width={900} height="auto" maxHeight="94vh" onHiding={onClose} showCloseButton>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 12 }}>
                <Field label="Code"><TextBox value={e.code ?? ''} disabled={!!value} onValueChanged={(v) => set({ code: v.value })} /></Field>
                {text('name', 'Name')}
                {text('father_name', 'Father / husband name')}
                {text('cnic', 'CNIC')}
                {text('phone', 'Phone')}
                {text('email', 'Email')}
                {text('designation', 'Designation')}
                {text('department', 'Department')}
                <Field label="Branch">
                    <SelectBox dataSource={branches} valueExpr="id" displayExpr="name" value={e.branch_id ?? null} showClearButton onValueChanged={(v) => set({ branch_id: v.value })} />
                </Field>
                <Field label="Joined"><DateBox type="date" value={e.join_date} onValueChanged={(v) => set({ join_date: v.value ? toDateString(new Date(v.value)) : undefined })} /></Field>
                <Field label="Left (leave empty while employed)"><DateBox type="date" value={e.leave_date ?? null} showClearButton onValueChanged={(v) => set({ leave_date: v.value ? toDateString(new Date(v.value)) : null })} /></Field>
                <Field label="Pay">
                    <SelectBox dataSource={[{ id: 'monthly', name: 'Monthly salary' }, { id: 'daily', name: 'Daily wage' }]} valueExpr="id" displayExpr="name"
                        value={e.pay_type} onValueChanged={(v) => set({ pay_type: v.value })} />
                </Field>
                <Field label={e.pay_type === 'daily' ? 'Daily rate' : 'Basic salary (month)'}>
                    <NumberBox value={Number(e.basic_salary ?? 0)} min={0} format="#,##0.00" onValueChanged={(v) => set({ basic_salary: String(v.value ?? 0) })} />
                </Field>
                <Field label="Monthly tax withheld">
                    <NumberBox value={Number(e.monthly_tax ?? 0)} min={0} format="#,##0.00" onValueChanged={(v) => set({ monthly_tax: String(v.value ?? 0) })} />
                </Field>
                <Field label="Annual leave days">
                    <NumberBox value={Number(e.annual_leave_days ?? 0)} min={0} onValueChanged={(v) => set({ annual_leave_days: String(v.value ?? 0) })} />
                </Field>
                {text('bank_name', 'Bank')}
                {text('bank_account', 'Account / IBAN')}
                {text('address', 'Address')}
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                <NamedAmounts label="Monthly allowances (house rent, conveyance, medical …)" rows={e.allowances ?? []} onChange={(allowances) => set({ allowances })} />
                <NamedAmounts label="Monthly deductions (EOBI, provident fund …)" rows={e.deductions ?? []} onChange={(deductions) => set({ deductions })} />
            </div>
            <div className="erp-actions">
                {value && <Button text={e.status === 'inactive' ? 'Reactivate' : 'Deactivate'} stylingMode="outlined"
                    onClick={() => set({ status: e.status === 'inactive' ? 'active' : 'inactive' })} />}
                <div style={{ flex: 1 }} />
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Saving…' : 'Save'} type="default" disabled={busy || !e.code || !e.name} onClick={save} />
            </div>
        </Popup>
    );
}

// ── Attendance ────────────────────────────────────────────────

interface SheetRow { employee_id: string; code: string; name: string; designation: string | null; status: string | null; overtime_hours: string | number; note: string | null }

function AttendanceSheet() {
    const [date, setDate] = useState<string>(today());
    const [rows, setRows] = useState<SheetRow[]>([]);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        getList<SheetRow>('/attendance', { date }).then(setRows).catch(notifyError);
    }, [date]);

    const markAll = (status: string) => setRows(rows.map((r) => ({ ...r, status: r.status ?? status })));

    const save = async () => {
        setBusy(true);
        try {
            const marked = rows.filter((r) => r.status);
            await api.post('/attendance', { date, rows: marked.map((r) => ({ employee_id: r.employee_id, status: r.status, overtime_hours: Number(r.overtime_hours || 0), note: r.note })) });
            notifySuccess(`Attendance saved for ${marked.length} employees.`);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start', alignItems: 'center' }}>
                <DateBox type="date" value={date} max={new Date()} onValueChanged={(e) => e.value && setDate(toDateString(new Date(e.value)))} />
                <Button text="Mark the rest present" onClick={() => markAll('present')} />
                <div style={{ flex: 1 }} />
                <Button text={busy ? 'Saving…' : 'Save attendance'} type="default" disabled={busy} onClick={save} />
            </div>
            <DataGrid dataSource={rows} keyExpr="employee_id" showRowLines columnAutoWidth onRowUpdated={() => setRows([...rows])}>
                <Editing mode="cell" allowUpdating />
                <Column dataField="code" width={100} allowEditing={false} />
                <Column dataField="name" allowEditing={false} />
                <Column dataField="designation" allowEditing={false} />
                <Column dataField="status" width={150}><Lookup dataSource={STATUSES} valueExpr="id" displayExpr="name" /></Column>
                <Column dataField="overtime_hours" caption="Overtime (h)" dataType="number" width={120} />
                <Column dataField="note" />
            </DataGrid>
        </div>
    );
}

// ── Payroll ───────────────────────────────────────────────────

function Payroll() {
    const client = useQueryClient();
    const runs = useQuery({ queryKey: ['payroll-runs'], queryFn: () => getList<Run>('/payroll-runs') });
    const [period, setPeriod] = useState<Date>(new Date());
    const [open, setOpen] = useState<Run | null>(null);
    const refresh = () => client.invalidateQueries({ queryKey: ['payroll-runs'] });

    const create = async () => {
        try {
            const { data } = await api.post('/payroll-runs', {
                period: `${period.getFullYear()}-${String(period.getMonth() + 1).padStart(2, '0')}`,
            });
            refresh();
            setOpen(data.data);
        } catch (e) {
            notifyError(e);
        }
    };

    const openRun = async (id: string) => {
        try {
            const { data } = await api.get(`/payroll-runs/${id}`);
            setOpen(data.data);
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start', alignItems: 'center' }}>
                <DateBox type="date" value={period} displayFormat="MMMM yyyy" calendarOptions={{ maxZoomLevel: 'year', minZoomLevel: 'century' }}
                    onValueChanged={(e) => e.value && setPeriod(new Date(e.value))} />
                <Button text="Prepare payroll" icon="add" type="default" onClick={create} />
            </div>
            <DataGrid dataSource={runs.data ?? []} keyExpr="id" showRowLines hoverStateEnabled onRowClick={(e) => e.rowType === 'data' && openRun(e.data.id)}>
                <Column dataField="run_no" width={130} />
                <Column dataField="period" width={110} />
                <Column dataField="lines_count" caption="Employees" width={100} />
                <Column {...numberColumn('gross_total')} caption="Gross" />
                <Column {...numberColumn('deduction_total')} caption="Deductions" />
                <Column {...numberColumn('net_total')} caption="Net pay" />
                <Column dataField="status" width={110} cellRender={statusCell} />
            </DataGrid>
            {open && <RunEditor run={open} onChange={(r) => { setOpen(r); refresh(); }} onClose={() => setOpen(null)} />}
        </div>
    );
}

function RunEditor({ run, onChange, onClose }: { run: Run; onChange: (r: Run) => void; onClose: () => void }) {
    const [busy, setBusy] = useState(false);
    const [payMethod, setPayMethod] = useState<'cash' | 'bank'>('bank');
    const [key] = useState(newIdempotencyKey);
    const draft = run.status === 'draft';

    const act = async (fn: () => Promise<{ data: { data: Run } }>, message?: string) => {
        setBusy(true);
        try {
            const { data } = await fn();
            onChange(data.data);
            if (message) notifySuccess(message);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    const adjust = (lineId: string, values: Record<string, unknown>) =>
        act(() => api.put(`/payroll-runs/${run.id}/lines/${lineId}`, values));

    return (
        <Popup visible title={`Payroll ${run.run_no} — ${run.period} (${run.status})`} width="96vw" height="92vh" onHiding={onClose} showCloseButton>
            <div style={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
                {draft && <div className="erp-muted" style={{ marginBottom: 6 }}>Edit absent days, overtime, bonus, tax, other deductions or advance recovery — the line recalculates.</div>}
                <DataGrid dataSource={run.lines ?? []} keyExpr="id" showBorders columnAutoWidth height="100%" style={{ flex: 1 }}
                    onRowUpdating={(e) => { e.cancel = true; adjust(e.key, e.newData); }}>
                    <Editing mode="cell" allowUpdating={draft} />
                    <Column caption="Employee" allowEditing={false} calculateCellValue={(l: Line) => `${l.employee.code} — ${l.employee.name}`} fixed />
                    <Column {...numberColumn('payable_days')} caption="Paid days" allowEditing={false} />
                    <Column {...numberColumn('absent_days')} caption="Absent" />
                    <Column {...numberColumn('overtime_hours')} caption="OT h" />
                    <Column {...numberColumn('basic')} caption="Basic" allowEditing={false} />
                    <Column {...numberColumn('allowances')} caption="Allowances" allowEditing={false} />
                    <Column {...numberColumn('overtime_amount')} caption="Overtime" allowEditing={false} />
                    <Column {...numberColumn('bonus')} caption="Bonus" />
                    <Column {...numberColumn('absence_deduction')} caption="Absence" allowEditing={false} />
                    <Column {...numberColumn('gross')} caption="Gross" allowEditing={false} />
                    <Column {...numberColumn('fixed_deductions')} caption="EOBI etc." allowEditing={false} />
                    <Column {...numberColumn('tax')} caption="Tax" />
                    <Column {...numberColumn('other_deduction')} caption="Other ded." />
                    <Column {...numberColumn('advance_recovery')} caption="Advance" />
                    <Column {...numberColumn('net')} caption="Net pay" allowEditing={false} cssClass="erp-strong" />
                    <Column caption="" width={70} allowEditing={false} cellRender={({ data }: { data: Line }) => (
                        <Button icon="print" stylingMode="text" hint="Payslip" onClick={() => printPayslip(run, data)} />
                    )} />
                    <Summary>
                        <TotalItem column="gross" summaryType="sum" valueFormat="#,##0.00" displayFormat="{0}" />
                        <TotalItem column="net" summaryType="sum" valueFormat="#,##0.00" displayFormat="{0}" />
                    </Summary>
                </DataGrid>
                <div className="erp-actions">
                    <span>Gross <b>{money(run.gross_total)}</b> · Deductions <b>{money(run.deduction_total)}</b> · Net <b>{money(run.net_total)}</b></span>
                    <div style={{ flex: 1 }} />
                    {draft && <Button text="Cancel payroll" stylingMode="outlined" type="danger" disabled={busy} onClick={() => act(() => api.post(`/payroll-runs/${run.id}/cancel`), 'Payroll cancelled.')} />}
                    {draft && <Button text="Recalculate" disabled={busy} onClick={() => act(() => api.post(`/payroll-runs/${run.id}/recalculate`))} />}
                    {draft && can('people.payroll.approve') && <Button text="Approve & post" type="default" disabled={busy}
                        onClick={() => act(() => api.post(`/payroll-runs/${run.id}/approve`), 'Approved. Salary expense posted.')} />}
                    {run.status === 'approved' && can('people.payroll.approve') && (
                        <>
                            <SelectBox width={120} dataSource={[{ id: 'bank', name: 'Bank' }, { id: 'cash', name: 'Cash' }]} valueExpr="id" displayExpr="name"
                                value={payMethod} onValueChanged={(e) => setPayMethod(e.value)} />
                            <Button text="Mark as paid" type="success" disabled={busy}
                                onClick={() => act(() => api.post(`/payroll-runs/${run.id}/pay`, { method: payMethod, date: today() }, idempotent(key)), 'Salaries paid.')} />
                        </>
                    )}
                </div>
            </div>
        </Popup>
    );
}

function printPayslip(run: Run, l: Line) {
    const row = (label: string, value: string | number) => `<tr><td>${label}</td><td style="text-align:right">${money(value)}</td></tr>`;
    const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c] as string));
    const earnings = [row('Basic', l.basic), ...(l.breakdown?.allowances ?? []).map((a) => row(esc(a.name), a.amount)),
        row('Overtime', l.overtime_amount), row('Bonus', l.bonus), row('Absence', -Number(l.absence_deduction))].join('');
    const deductions = [...(l.breakdown?.deductions ?? []).map((d) => row(esc(d.name), d.amount)), row('Income tax', l.tax),
        row('Other', l.other_deduction), ...(l.breakdown?.advances ?? []).map((a) => row(`Advance ${esc(a.advance_no)}`, a.amount))].join('');
    const w = window.open('', '_blank', 'width=720,height=900');
    if (!w) return;
    w.document.write(`<html><head><title>Payslip ${esc(l.employee.code)} ${run.period}</title><style>
        body{font-family:system-ui,sans-serif;padding:24px;color:#111}table{width:100%;border-collapse:collapse}td{padding:4px 0;border-bottom:1px solid #eee}
        h2{margin:0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:16px}.net{font-size:20px;margin-top:20px;text-align:right}
        </style></head><body>
        <h2>Payslip — ${run.period}</h2><div>${esc(l.employee.code)} · ${esc(l.employee.name)} · ${esc(l.employee.designation ?? '')}</div>
        <div>Paid days ${l.payable_days} · Absent ${l.absent_days} · Overtime ${l.overtime_hours} h</div>
        <div class="grid"><div><b>Earnings</b><table>${earnings}<tr><td><b>Gross</b></td><td style="text-align:right"><b>${money(l.gross)}</b></td></tr></table></div>
        <div><b>Deductions</b><table>${deductions}</table></div></div>
        <div class="net">Net pay <b>${money(l.net)}</b></div>
        <div style="margin-top:8px">${l.employee.bank_name ? `Bank: ${esc(l.employee.bank_name)} ${esc(l.employee.bank_account ?? '')}` : ''}</div>
        <script>window.print()</script></body></html>`);
    w.document.close();
}

// ── Advances ──────────────────────────────────────────────────

function Advances() {
    const client = useQueryClient();
    const advances = useQuery({ queryKey: ['employee-advances'], queryFn: () => getList('/employee-advances') });
    const employees = useQuery({ queryKey: ['employees'], queryFn: () => getList<Employee>('/employees') });
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState({ employee_id: null as string | null, kind: 'advance', amount: 0, installment: 0, paid_from: 'cash', date: today(), note: '' });
    const [key, setKey] = useState(newIdempotencyKey);

    const save = async () => {
        try {
            await api.post('/employee-advances', { ...form, installment: form.installment || null }, idempotent(key));
            notifySuccess('Advance recorded and posted.');
            setOpen(false);
            setKey(newIdempotencyKey());
            client.invalidateQueries({ queryKey: ['employee-advances'] });
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                <Button text="Give advance / loan" icon="add" type="default" onClick={() => setOpen(true)} />
            </div>
            <DataGrid dataSource={advances.data ?? []} keyExpr="id" showRowLines columnAutoWidth>
                <SearchPanel visible width={240} />
                <Column dataField="advance_no" width={120} />
                <Column dataField="date" dataType="date" width={110} />
                <Column caption="Employee" calculateCellValue={(a: any) => `${a.employee?.code} — ${a.employee?.name}`} />
                <Column dataField="kind" width={90} />
                <Column {...numberColumn('amount')} />
                <Column {...numberColumn('installment')} caption="Per month" />
                <Column {...numberColumn('recovered')} />
                <Column caption="Outstanding" dataType="number" format="#,##0.00" calculateCellValue={(a: any) => Number(a.amount) - Number(a.recovered)} />
                <Column dataField="status" width={100} cellRender={statusCell} />
            </DataGrid>
            {open && (
                <Popup visible title="Give advance or loan" width={520} height="auto" onHiding={() => setOpen(false)} showCloseButton>
                    <Field label="Employee">
                        <SelectBox dataSource={(employees.data ?? []).filter((e) => e.status === 'active')} valueExpr="id" displayExpr={(e: Employee) => (e ? `${e.code} — ${e.name}` : '')}
                            searchEnabled value={form.employee_id} onValueChanged={(e) => setForm({ ...form, employee_id: e.value })} />
                    </Field>
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                        <Field label="Type"><SelectBox dataSource={[{ id: 'advance', name: 'Salary advance' }, { id: 'loan', name: 'Loan' }]} valueExpr="id" displayExpr="name" value={form.kind} onValueChanged={(e) => setForm({ ...form, kind: e.value })} /></Field>
                        <Field label="Date"><DateBox type="date" value={form.date} onValueChanged={(e) => e.value && setForm({ ...form, date: toDateString(new Date(e.value)) })} /></Field>
                        <Field label="Amount"><NumberBox value={form.amount} min={0} format="#,##0.00" onValueChanged={(e) => setForm({ ...form, amount: e.value })} /></Field>
                        <Field label="Recover per month (empty = all next payroll)"><NumberBox value={form.installment} min={0} format="#,##0.00" onValueChanged={(e) => setForm({ ...form, installment: e.value })} /></Field>
                        <Field label="Paid from"><SelectBox dataSource={[{ id: 'cash', name: 'Cash' }, { id: 'bank', name: 'Bank' }]} valueExpr="id" displayExpr="name" value={form.paid_from} onValueChanged={(e) => setForm({ ...form, paid_from: e.value })} /></Field>
                        <Field label="Note"><TextBox value={form.note} onValueChanged={(e) => setForm({ ...form, note: e.value })} /></Field>
                    </div>
                    <div className="erp-actions">
                        <Button text="Cancel" onClick={() => setOpen(false)} />
                        <Button text="Save" type="default" disabled={!form.employee_id || !form.amount} onClick={save} />
                    </div>
                </Popup>
            )}
        </div>
    );
}
