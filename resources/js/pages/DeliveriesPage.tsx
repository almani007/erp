import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import CheckBox from 'devextreme-react/check-box';
import DataGrid, { Column, SearchPanel } from 'devextreme-react/data-grid';
import DateBox from 'devextreme-react/date-box';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TextBox from 'devextreme-react/text-box';
import Page, { Field } from '../components/Page';
import { statusCell } from '../components/Status';
import { api, getList, getOne, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { money, qty, toDateString, today } from '../lib/format';
import { esc, printHtml } from '../lib/print';
import { can } from '../lib/store';

const views = [
    { id: 'open', n: 'To do (pending, on the road, failed)' },
    { id: 'pending', n: 'Pending' }, { id: 'dispatched', n: 'On the road' }, { id: 'delivered', n: 'Delivered' },
    { id: 'failed', n: 'Failed' }, { id: 'cancelled', n: 'Cancelled' },
];

/** Deliveries: challans for invoices, riders, proof of delivery and cash on delivery. */
export default function DeliveriesPage() {
    const client = useQueryClient();
    const [status, setStatus] = useState('open');
    const [date, setDate] = useState<string | null>(null);
    const rows = useQuery({ queryKey: ['deliveries', status, date], queryFn: () => getList<any>('/deliveries', { status, ...(date ? { date } : {}) }), refetchInterval: 60000 });
    const [creating, setCreating] = useState(false);
    const [acting, setActing] = useState<{ note: any; action: 'dispatch' | 'deliver' } | null>(null);
    const refresh = () => client.invalidateQueries({ queryKey: ['deliveries'] });

    const simple = async (note: any, action: 'fail' | 'cancel') => {
        const reason = window.prompt(action === 'fail' ? 'Why was it not delivered?' : 'Why cancel this delivery?');
        if (!reason) return;
        try { await api.post(`/deliveries/${note.id}/${action}`, { reason }); refresh(); } catch (e) { notifyError(e); }
    };

    return (
        <Page title="Deliveries" subtitle="Delivery notes for invoices. Riders mark them delivered with the receiver's signature and any cash collected."
            actions={<Button text="New delivery" icon="add" type="default" onClick={() => setCreating(true)} />}>
            <div className="erp-row">
                <SelectBox width={280} dataSource={views} valueExpr="id" displayExpr="n" value={status} onValueChanged={(e) => setStatus(e.value)} />
                <DateBox type="date" width={160} placeholder="Any day" showClearButton value={date} onValueChanged={(e) => setDate(e.value ? toDateString(new Date(e.value)) : null)} />
            </div>
            <div className="erp-card">
                <DataGrid dataSource={rows.data ?? []} keyExpr="id" showRowLines columnAutoWidth height="calc(100vh - 290px)" noDataText="No deliveries here.">
                    <SearchPanel visible width={240} />
                    <Column dataField="note_no" caption="Note" width={110} />
                    <Column dataField="sale.sale_no" caption="Invoice" width={120} />
                    <Column dataField="scheduled_for" caption="For" dataType="date" width={100} />
                    <Column dataField="ship_to_name" caption="Deliver to" />
                    <Column dataField="ship_to_phone" caption="Phone" width={120} />
                    <Column dataField="ship_to_address" caption="Address" />
                    <Column caption="Rider" calculateCellValue={(d: any) => [d.driver_name, d.vehicle_no].filter(Boolean).join(' · ')} />
                    <Column dataField="cod_amount" caption="Collect" dataType="number" format="#,##0.00" width={100} />
                    <Column dataField="status" width={110} cellRender={statusCell} />
                    <Column caption="" width={300} cellRender={({ data }) => (
                        <>
                            <Button icon="print" stylingMode="text" hint="Print delivery note" onClick={() => printNote(data.id)} />
                            {['pending', 'failed'].includes(data.status) && <Button text="Send out" stylingMode="text" onClick={() => setActing({ note: data, action: 'dispatch' })} />}
                            {data.status === 'dispatched' && <Button text="Delivered" type="success" stylingMode="text" onClick={() => setActing({ note: data, action: 'deliver' })} />}
                            {data.status === 'dispatched' && <Button text="Not delivered" type="danger" stylingMode="text" onClick={() => simple(data, 'fail')} />}
                            {['pending', 'failed'].includes(data.status) && <Button text="Cancel" stylingMode="text" onClick={() => simple(data, 'cancel')} />}
                        </>
                    )} />
                </DataGrid>
            </div>
            {creating && <NewDelivery onClose={() => setCreating(false)} onDone={(n) => { setCreating(false); refresh(); printNote(n.id); }} />}
            {acting?.action === 'dispatch' && <Dispatch note={acting.note} onClose={() => setActing(null)} onDone={() => { setActing(null); refresh(); }} />}
            {acting?.action === 'deliver' && <Deliver note={acting.note} onClose={() => setActing(null)} onDone={() => { setActing(null); refresh(); }} />}
        </Page>
    );
}

function useDrivers() {
    return useQuery({ queryKey: ['employees'], queryFn: () => getList<any>('/employees'), enabled: can('people.employee.view'), retry: false }).data ?? [];
}

function NewDelivery({ onClose, onDone }: { onClose: () => void; onDone: (note: any) => void }) {
    const [saleNo, setSaleNo] = useState('');
    const [found, setFound] = useState<any | null>(null);
    const [lines, setLines] = useState<any[]>([]);
    const [f, setF] = useState<any>({ scheduled_for: today(), collect_cash: false });
    const drivers = useDrivers();
    const set = (p: any) => setF((x: any) => ({ ...x, ...p }));

    const find = async () => {
        try {
            const data = await getOne<any>(`/deliveries/for-invoice/${encodeURIComponent(saleNo.trim())}`);
            setFound(data);
            setLines(data.lines.map((l: any) => ({ ...l, quantity: Number(l.open) })));
            const c = data.sale.customer;
            setF((x: any) => ({ ...x, ship_to_name: c?.name ?? '', ship_to_phone: c?.phone ?? '', ship_to_address: c?.address ?? '', collect_cash: Number(data.sale.balance_due) > 0 && !!c }));
            if (data.lines.length === 0) notifyError(new Error('Everything on this invoice is already delivered or on its way.'));
        } catch (e) { setFound(null); notifyError(e); }
    };
    const save = async () => {
        try {
            const { data } = await api.post('/deliveries', {
                ...f, sale_id: found.sale.id, lines: lines.map((l) => ({ sale_item_id: l.sale_item_id, quantity: l.quantity || 0 })),
            });
            notifySuccess(`Delivery note ${data.data.note_no} created.`);
            onDone(data.data);
        } catch (e) { notifyError(e); }
    };

    return (
        <Popup visible title="New delivery" width={760} height="auto" maxHeight="92vh" onHiding={onClose} showCloseButton>
            <div className="erp-row">
                <Field label="Invoice number"><TextBox width={200} value={saleNo} onValueChanged={(e) => setSaleNo(e.value)} onEnterKey={find} /></Field>
                <Button text="Find" icon="search" onClick={find} disabled={!saleNo} />
                {found && <span className="erp-muted">Total {money(found.sale.grand_total)} · still owed {money(found.sale.balance_due)}</span>}
            </div>
            {found && lines.length > 0 && (
                <>
                    <DataGrid dataSource={lines} keyExpr="sale_item_id" showRowLines columnAutoWidth onRowUpdated={() => setLines([...lines])}>
                        <Column dataField="description" caption="Item" allowEditing={false} />
                        <Column caption="Sold" width={80} allowEditing={false} calculateCellValue={(l: any) => qty(l.sold)} />
                        <Column caption="Left" width={80} allowEditing={false} calculateCellValue={(l: any) => qty(l.open)} />
                        <Column dataField="quantity" caption="Send now" dataType="number" width={100} />
                    </DataGrid>
                    <div className="erp-row">
                        <Field label="Deliver to"><TextBox value={f.ship_to_name} onValueChanged={(e) => set({ ship_to_name: e.value })} /></Field>
                        <Field label="Phone"><TextBox value={f.ship_to_phone} onValueChanged={(e) => set({ ship_to_phone: e.value })} /></Field>
                        <Field label="On"><DateBox type="date" value={f.scheduled_for} onValueChanged={(e) => set({ scheduled_for: toDateString(new Date(e.value)) })} /></Field>
                    </div>
                    <Field label="Address"><TextBox value={f.ship_to_address} onValueChanged={(e) => set({ ship_to_address: e.value })} /></Field>
                    <div className="erp-row">
                        {drivers.length > 0 && <Field label="Rider (staff)"><SelectBox width={220} dataSource={drivers} valueExpr="id" displayExpr="name" searchEnabled showClearButton value={f.driver_employee_id ?? null} onValueChanged={(e) => set({ driver_employee_id: e.value, driver_name: drivers.find((d: any) => d.id === e.value)?.name ?? f.driver_name })} /></Field>}
                        <Field label="Rider name"><TextBox value={f.driver_name ?? ''} onValueChanged={(e) => set({ driver_name: e.value })} /></Field>
                        <Field label="Vehicle"><TextBox width={120} value={f.vehicle_no ?? ''} onValueChanged={(e) => set({ vehicle_no: e.value })} /></Field>
                    </div>
                    {Number(found.sale.balance_due) > 0 && found.sale.customer_id && (
                        <CheckBox text={`Collect ${money(found.sale.balance_due)} on delivery`} value={f.collect_cash} onValueChanged={(e) => set({ collect_cash: e.value })} />
                    )}
                </>
            )}
            <div className="erp-actions">
                <Button text="Cancel" onClick={onClose} />
                <Button text="Create and print" type="default" disabled={!found || !lines.some((l) => Number(l.quantity) > 0)} onClick={save} />
            </div>
        </Popup>
    );
}

function Dispatch({ note, onClose, onDone }: { note: any; onClose: () => void; onDone: () => void }) {
    const drivers = useDrivers();
    const [f, setF] = useState<any>({ driver_employee_id: note.driver_employee_id, driver_name: note.driver_name, vehicle_no: note.vehicle_no });
    const save = async () => {
        try { await api.post(`/deliveries/${note.id}/dispatch`, f); notifySuccess(`${note.note_no} is on the road.`); onDone(); } catch (e) { notifyError(e); }
    };
    return (
        <Popup visible title={`Send out ${note.note_no}`} width={440} height="auto" onHiding={onClose} showCloseButton>
            {drivers.length > 0 && <Field label="Rider (staff)"><SelectBox dataSource={drivers} valueExpr="id" displayExpr="name" searchEnabled showClearButton value={f.driver_employee_id ?? null} onValueChanged={(e) => setF({ ...f, driver_employee_id: e.value, driver_name: drivers.find((d: any) => d.id === e.value)?.name ?? f.driver_name })} /></Field>}
            <Field label="Rider name"><TextBox value={f.driver_name ?? ''} onValueChanged={(e) => setF({ ...f, driver_name: e.value })} /></Field>
            <Field label="Vehicle"><TextBox value={f.vehicle_no ?? ''} onValueChanged={(e) => setF({ ...f, vehicle_no: e.value })} /></Field>
            <div className="erp-actions"><Button text="Cancel" onClick={onClose} /><Button text="Send out" type="default" disabled={!f.driver_name && !f.driver_employee_id} onClick={save} /></div>
        </Popup>
    );
}

function Deliver({ note, onClose, onDone }: { note: any; onClose: () => void; onDone: () => void }) {
    const [receivedBy, setReceivedBy] = useState(note.ship_to_name ?? '');
    const [collected, setCollected] = useState<number>(Number(note.cod_amount) || 0);
    const [method, setMethod] = useState('cash');
    const [remark, setRemark] = useState('');
    const [key] = useState(newIdempotencyKey);
    const pad = useRef<SignaturePadHandle>(null);

    const save = async () => {
        try {
            await api.post(`/deliveries/${note.id}/deliver`, {
                received_by: receivedBy, signature: pad.current?.value() ?? null,
                collected_amount: collected || 0, collected_by: method, note: remark || null,
            }, idempotent(key));
            notifySuccess(`${note.note_no} delivered${collected ? ` — ${money(collected)} recorded as received` : ''}.`);
            onDone();
        } catch (e) { notifyError(e); }
    };

    return (
        <Popup visible title={`Delivered: ${note.note_no}`} width={480} height="auto" onHiding={onClose} showCloseButton>
            <Field label="Received by"><TextBox value={receivedBy} onValueChanged={(e) => setReceivedBy(e.value)} /></Field>
            <Field label="Signature"><SignaturePad ref={pad} /></Field>
            {Number(note.cod_amount) > 0 && (
                <div className="erp-row">
                    <Field label={`Collected (due ${money(note.cod_amount)})`}><NumberBox min={0} format="#,##0.00" value={collected} onValueChanged={(e) => setCollected(e.value ?? 0)} /></Field>
                    <Field label="As"><SelectBox width={120} dataSource={[{ id: 'cash', n: 'Cash' }, { id: 'bank', n: 'Bank transfer' }]} valueExpr="id" displayExpr="n" value={method} onValueChanged={(e) => setMethod(e.value)} /></Field>
                </div>
            )}
            <Field label="Remarks"><TextBox value={remark} onValueChanged={(e) => setRemark(e.value)} /></Field>
            <div className="erp-actions"><Button text="Cancel" onClick={onClose} /><Button text="Mark delivered" type="success" disabled={receivedBy.trim().length < 2} onClick={save} /></div>
        </Popup>
    );
}

interface SignaturePadHandle { value: () => string | null }

/** Finger or mouse signature; returns a PNG data URL, or null when nothing was drawn. */
const SignaturePad = forwardRef<SignaturePadHandle>(function SignaturePad(_props, ref) {
    const canvas = useRef<HTMLCanvasElement>(null);
    const drawn = useRef(false);

    useEffect(() => {
        const c = canvas.current!;
        const ctx = c.getContext('2d')!;
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.strokeStyle = '#111';
        let down = false;
        const at = (e: PointerEvent) => { const r = c.getBoundingClientRect(); return [(e.clientX - r.left) * (c.width / r.width), (e.clientY - r.top) * (c.height / r.height)]; };
        const start = (e: PointerEvent) => { down = true; const [x, y] = at(e); ctx.beginPath(); ctx.moveTo(x, y); c.setPointerCapture(e.pointerId); };
        const move = (e: PointerEvent) => { if (!down) return; const [x, y] = at(e); ctx.lineTo(x, y); ctx.stroke(); drawn.current = true; };
        const end = () => { down = false; };
        c.addEventListener('pointerdown', start);
        c.addEventListener('pointermove', move);
        c.addEventListener('pointerup', end);
        c.addEventListener('pointerleave', end);
        return () => { c.removeEventListener('pointerdown', start); c.removeEventListener('pointermove', move); c.removeEventListener('pointerup', end); c.removeEventListener('pointerleave', end); };
    }, []);

    useImperativeHandle(ref, () => ({ value: () => (drawn.current ? canvas.current!.toDataURL('image/png') : null) }), []);

    const clear = () => { const c = canvas.current!; c.getContext('2d')!.clearRect(0, 0, c.width, c.height); drawn.current = false; };

    return (
        <div>
            <canvas ref={canvas} width={420} height={140} style={{ width: '100%', height: 140, border: '1px dashed #9ca3af', borderRadius: 8, touchAction: 'none', background: '#fff' }} />
            <Button text="Clear" stylingMode="text" onClick={clear} />
        </div>
    );
});

/** Delivery note (challan) on A4 with room for signatures; shows the captured signature once delivered. */
async function printNote(id: string) {
    try {
        const n = await getOne<any>(`/deliveries/${id}`);
        const rows = n.lines.map((l: any, i: number) => `<tr><td>${i + 1}</td><td>${esc(l.description)}</td><td style="text-align:right">${esc(qty(l.quantity))}</td></tr>`).join('');
        printHtml(`Delivery note ${n.note_no}`, `<style>table{width:100%;border-collapse:collapse}td,th{border:1px solid #ccc;padding:6px}h1{margin:0 0 4px}.g{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:12px 0}.sig{margin-top:40px;display:grid;grid-template-columns:1fr 1fr;gap:40px}.sig div{border-top:1px solid #333;padding-top:4px}</style>
            <h1>Delivery note ${esc(n.note_no)}</h1><div>Invoice ${esc(n.sale?.sale_no ?? '')} · ${esc(n.scheduled_for ?? '')}</div>
            <div class="g"><div><b>Deliver to</b><br>${esc(n.ship_to_name ?? '')}<br>${esc(n.ship_to_phone ?? '')}<br>${esc(n.ship_to_address ?? '')}</div>
            <div><b>Rider</b><br>${esc(n.driver ?? '')} ${esc(n.vehicle_no ?? '')}${Number(n.cod_amount) > 0 ? `<br><b>Collect: ${esc(money(n.cod_amount))}</b>` : ''}</div></div>
            <table><thead><tr><th>#</th><th>Item</th><th>Qty</th></tr></thead><tbody>${rows}</tbody></table>
            ${n.status === 'delivered'
                ? `<p>Received by <b>${esc(n.received_by ?? '')}</b> on ${esc(new Date(n.delivered_at).toLocaleString())}</p>${n.signature?.startsWith('data:image/png;base64,') ? `<img src="${n.signature}" style="height:90px" alt="signature" />` : ''}`
                : '<div class="sig"><div>Rider</div><div>Received in good condition (name &amp; signature)</div></div>'}`);
    } catch (e) {
        notifyError(e);
    }
}
