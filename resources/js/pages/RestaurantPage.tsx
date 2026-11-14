import { useEffect, useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import CheckBox from 'devextreme-react/check-box';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TextBox from 'devextreme-react/text-box';
import Page, { Field } from '../components/Page';
import { api, getOne, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { useAppConfig } from '../lib/config';
import { money } from '../lib/format';
import { esc } from '../lib/print';
import { can, useSession } from '../lib/store';

interface OrderSummary { id: string; order_no: string; status: string; guests: number; total: string; ready: number; order_type: string; customer_name: string | null }
interface Table { id: string; name: string; area: string; seats: number; order: OrderSummary | null }
interface MenuItem { id: string; sku: string; name: string; sale_price: string; category: string | null; kitchen_station: string | null; modifier_group_ids: string[] }
interface Group { id: string; name: string; min_select: number; max_select: number; options: { id: string; name: string; price: string }[] }
interface Line { id: string; product_name: string; quantity: string; unit_price: string; modifiers: { group: string; name: string; price: string }[] | null; note: string | null; kitchen_status: string; kot_no: number | null; station: string | null }
interface Order { id: string; order_no: string; order_type: string; status: string; guests: number; table: { name: string } | null; customer_name: string | null; customer_phone: string | null; delivery_address: string | null; service_charge_percent: string; lines: Line[] }

const STATUS_COLOR: Record<string, string> = { pending: '#6b7280', sent: '#2563eb', preparing: '#d97706', ready: '#16a34a', served: '#9ca3af', void: '#dc2626' };

/** Floor plan, table orders with modifiers, kitchen tickets, bills and settlement. */
export default function RestaurantPage() {
    const client = useQueryClient();
    const branchId = useSession((s) => s.activeBranchId);
    const floor = useQuery({ queryKey: ['restaurant-floor', branchId], queryFn: () => getOne<{ tables: Table[]; other_orders: OrderSummary[] }>('/restaurant/floor'), enabled: !!branchId, refetchInterval: 10000 });
    const [orderId, setOrderId] = useState<string | null>(null);
    const [newOrder, setNewOrder] = useState<'takeaway' | 'delivery' | null>(null);
    const refresh = () => client.invalidateQueries({ queryKey: ['restaurant-floor'] });

    const openTable = async (t: Table) => {
        if (t.order) return setOrderId(t.order.id);
        const guests = Number(window.prompt(`Guests at ${t.name}?`, String(t.seats)) ?? 0);
        if (!guests) return;
        try {
            const { data } = await api.post('/restaurant/orders', { order_type: 'dine_in', table_id: t.id, guests });
            setOrderId(data.data.id);
            refresh();
        } catch (e) {
            notifyError(e);
        }
    };

    if (!branchId) return <Page title="Restaurant"><div className="erp-card">Choose a branch at the top first.</div></Page>;

    const areas = Array.from(new Set((floor.data?.tables ?? []).map((t) => t.area)));

    return (
        <Page title="Restaurant" subtitle="Tap a table to take or continue an order."
            actions={<>
                <Button text="Takeaway" icon="cart" onClick={() => setNewOrder('takeaway')} />
                <Button text="Delivery" icon="car" onClick={() => setNewOrder('delivery')} />
            </>}>
            {areas.map((area) => (
                <div key={area} style={{ marginBottom: 16 }}>
                    <div className="erp-nav-section" style={{ padding: '4px 0 8px' }}>{area}</div>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(140px, 1fr))', gap: 10 }}>
                        {(floor.data?.tables ?? []).filter((t) => t.area === area).map((t) => {
                            const o = t.order;
                            const bg = !o ? 'var(--erp-success-bg, #dcfce7)' : o.status === 'billed' ? 'var(--erp-warning-bg, #fef3c7)' : 'var(--erp-info-bg, #dbeafe)';
                            return (
                                <button key={t.id} type="button" onClick={() => openTable(t)} className="erp-card"
                                    style={{ background: bg, textAlign: 'left', cursor: 'pointer', padding: 12, border: '1px solid var(--erp-border, #e5e7eb)', position: 'relative' }}>
                                    <div style={{ fontSize: 18, fontWeight: 700 }}>{t.name}</div>
                                    <div className="erp-muted">{o ? `${o.guests} guests · ${o.order_no}` : `${t.seats} seats · free`}</div>
                                    {o && <div style={{ fontWeight: 600, marginTop: 4 }}>{money(o.total)}{o.status === 'billed' ? ' · bill out' : ''}</div>}
                                    {o && o.ready > 0 && <span style={{ position: 'absolute', top: 8, right: 8, background: '#16a34a', color: '#fff', borderRadius: 10, padding: '0 8px', fontSize: 12 }}>{o.ready} ready</span>}
                                </button>
                            );
                        })}
                    </div>
                </div>
            ))}
            {(floor.data?.tables ?? []).length === 0 && <div className="erp-card">No tables yet. Add them under Restaurant setup.</div>}
            {(floor.data?.other_orders ?? []).length > 0 && (
                <div className="erp-card">
                    <b>Takeaway &amp; delivery</b>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 8 }}>
                        {floor.data!.other_orders.map((o) => (
                            <Button key={o.id} text={`${o.order_no} · ${o.order_type} · ${o.customer_name ?? ''} · ${money(o.total)}${o.ready ? ` · ${o.ready} ready` : ''}`} onClick={() => setOrderId(o.id)} />
                        ))}
                    </div>
                </div>
            )}
            {newOrder && <NewOffTableOrder type={newOrder} onClose={() => setNewOrder(null)} onOpened={(id) => { setNewOrder(null); setOrderId(id); refresh(); }} />}
            {orderId && <OrderPanel orderId={orderId} tables={floor.data?.tables ?? []} onClose={() => { setOrderId(null); refresh(); }} />}
        </Page>
    );
}

function NewOffTableOrder({ type, onClose, onOpened }: { type: 'takeaway' | 'delivery'; onClose: () => void; onOpened: (id: string) => void }) {
    const [f, setF] = useState<Record<string, string>>({});
    const save = async () => {
        try { onOpened((await api.post('/restaurant/orders', { order_type: type, ...f })).data.data.id); } catch (e) { notifyError(e); }
    };
    return (
        <Popup visible title={type === 'delivery' ? 'New delivery order' : 'New takeaway order'} width={480} height="auto" onHiding={onClose} showCloseButton>
            <Field label="Customer name"><TextBox value={f.customer_name ?? ''} onValueChanged={(e) => setF({ ...f, customer_name: e.value })} /></Field>
            <Field label="Phone"><TextBox value={f.customer_phone ?? ''} onValueChanged={(e) => setF({ ...f, customer_phone: e.value })} /></Field>
            {type === 'delivery' && <Field label="Delivery address"><TextBox value={f.delivery_address ?? ''} onValueChanged={(e) => setF({ ...f, delivery_address: e.value })} /></Field>}
            <div className="erp-actions"><Button text="Cancel" onClick={onClose} /><Button text="Start order" type="default" disabled={type === 'delivery' && !f.delivery_address} onClick={save} /></div>
        </Popup>
    );
}

function OrderPanel({ orderId, tables, onClose }: { orderId: string; tables: Table[]; onClose: () => void }) {
    const { config } = useAppConfig();
    const menu = useQuery({ queryKey: ['restaurant-menu'], queryFn: () => getOne<{ items: MenuItem[]; modifier_groups: Group[] }>('/restaurant/menu') });
    const [order, setOrder] = useState<Order | null>(null);
    const [category, setCategory] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const [picking, setPicking] = useState<MenuItem | null>(null);
    const [settling, setSettling] = useState(false);
    const load = () => api.get(`/restaurant/orders/${orderId}`).then((r) => setOrder(r.data.data)).catch(notifyError);
    useEffect(() => { load(); const t = setInterval(load, 15000); return () => clearInterval(t); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [orderId]);

    const items = menu.data?.items ?? [];
    const categories = useMemo(() => Array.from(new Set(items.map((i) => i.category ?? 'Other'))), [items]);
    const shown = items.filter((i) => (search ? `${i.name} ${i.sku}`.toLowerCase().includes(search.toLowerCase()) : (i.category ?? 'Other') === (category ?? categories[0])));
    const live = (order?.lines ?? []).filter((l) => l.kitchen_status !== 'void');
    const subtotal = live.reduce((s, l) => s + Number(l.unit_price) * Number(l.quantity), 0);
    const service = subtotal * Number(order?.service_charge_percent ?? 0) / 100;
    const editable = order?.status === 'open';

    const act = async (action: string, body: Record<string, unknown> = {}, message?: string) => {
        try {
            const { data } = await api.post(`/restaurant/orders/${orderId}/${action}`, body);
            setOrder(data.data);
            if (message) notifySuccess(message);
            return data.data as Order;
        } catch (e) {
            notifyError(e);
            return null;
        }
    };
    const add = async (item: MenuItem, optionIds: string[] = [], note = '', qty = 1) => {
        try {
            const { data } = await api.post(`/restaurant/orders/${orderId}/items`, { items: [{ product_id: item.id, quantity: qty, modifier_option_ids: optionIds, note: note || null }] });
            setOrder(data.data);
        } catch (e) {
            notifyError(e);
        }
    };
    const tap = (item: MenuItem) => (item.modifier_group_ids.length ? setPicking(item) : add(item));
    const send = async () => {
        const before = new Set((order?.lines ?? []).filter((l) => l.kitchen_status === 'pending').map((l) => l.id));
        const o = await act('send', {}, 'Sent to the kitchen.');
        if (o) printKot(o, o.lines.filter((l) => before.has(l.id)));
    };
    const voidLine = async (l: Line) => {
        const reason = l.kitchen_status === 'pending' ? null : window.prompt('Reason for voiding an item already sent to the kitchen?');
        if (l.kitchen_status !== 'pending' && !reason) return;
        try { setOrder((await api.post(`/restaurant/orders/${orderId}/lines/${l.id}/void`, { reason })).data.data); } catch (e) { notifyError(e); }
    };
    const move = async () => {
        const free = tables.filter((t) => !t.order);
        const name = window.prompt(`Move to which table? Free: ${free.map((t) => t.name).join(', ')}`);
        const target = free.find((t) => t.name.toLowerCase() === (name ?? '').trim().toLowerCase());
        if (target) act('move', { table_id: target.id }, `Moved to ${target.name}.`);
    };

    const printBill = (o: Order) => {
        const rows = o.lines.filter((l) => l.kitchen_status !== 'void').map((l) => `<tr><td>${esc(l.quantity)} × ${esc(l.product_name)}${(l.modifiers ?? []).map((m) => `<div class="m">+ ${esc(m.name)}</div>`).join('')}</td><td class="r">${money(Number(l.unit_price) * Number(l.quantity))}</td></tr>`).join('');
        receipt(`<h3>${esc(config?.company?.legal_name ?? '')}</h3><div>${esc(o.order_no)} · ${o.table ? `Table ${esc(o.table.name)}` : esc(o.order_type)} · ${o.guests} guests</div><table>${rows}</table>
            <table><tr><td>Subtotal</td><td class="r">${money(subtotal)}</td></tr>${service ? `<tr><td>Service ${o.service_charge_percent}%</td><td class="r">${money(service)}</td></tr>` : ''}
            <tr><td><b>Total (before tax)</b></td><td class="r"><b>${money(subtotal + service)}</b></td></tr></table><p class="m">Tax is added on the final receipt.</p>`);
    };

    if (!order) return null;

    return (
        <Popup visible title={`${order.order_no} · ${order.table ? `Table ${order.table.name}` : order.order_type}${order.customer_name ? ` · ${order.customer_name}` : ''}`}
            width="98vw" height="96vh" onHiding={onClose} showCloseButton>
            <div style={{ display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 16, height: '100%' }}>
                <div style={{ display: 'flex', flexDirection: 'column', minHeight: 0 }}>
                    <TextBox placeholder="Search the menu" value={search} onValueChanged={(e) => setSearch(e.value)} valueChangeEvent="input" showClearButton />
                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', margin: '8px 0' }}>
                        {categories.map((c) => <Button key={c} text={c} type={(category ?? categories[0]) === c && !search ? 'default' : 'normal'} onClick={() => { setCategory(c); setSearch(''); }} />)}
                    </div>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(150px, 1fr))', gap: 8, overflowY: 'auto' }}>
                        {shown.map((i) => (
                            <button key={i.id} type="button" disabled={!editable} onClick={() => tap(i)} className="erp-card"
                                style={{ textAlign: 'left', cursor: editable ? 'pointer' : 'not-allowed', padding: 10, minHeight: 72 }}>
                                <div style={{ fontWeight: 600 }}>{i.name}</div>
                                <div className="erp-muted">{money(i.sale_price)}{i.modifier_group_ids.length ? ' · options' : ''}</div>
                            </button>
                        ))}
                    </div>
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', minHeight: 0 }}>
                    <div style={{ flex: 1, overflowY: 'auto' }}>
                        {order.lines.map((l) => (
                            <div key={l.id} style={{ display: 'flex', gap: 8, padding: '6px 0', borderBottom: '1px solid var(--erp-border, #eee)', opacity: l.kitchen_status === 'void' ? 0.5 : 1 }}>
                                <div style={{ flex: 1 }}>
                                    <div style={{ textDecoration: l.kitchen_status === 'void' ? 'line-through' : undefined }}><b>{Number(l.quantity)} ×</b> {l.product_name}</div>
                                    {(l.modifiers ?? []).map((m, i) => <div key={i} className="erp-muted">+ {m.name}{Number(m.price) ? ` (${money(m.price)})` : ''}</div>)}
                                    {l.note && <div className="erp-muted">“{l.note}”</div>}
                                    <span style={{ fontSize: 11, color: STATUS_COLOR[l.kitchen_status] }}>{l.kitchen_status}{l.kot_no ? ` · KOT ${l.kot_no}` : ''}</span>
                                </div>
                                <div style={{ textAlign: 'right' }}>
                                    <div>{money(Number(l.unit_price) * Number(l.quantity))}</div>
                                    {editable && l.kitchen_status !== 'void' && <Button icon="trash" stylingMode="text" hint="Void" onClick={() => voidLine(l)} />}
                                </div>
                            </div>
                        ))}
                        {order.lines.length === 0 && <div className="erp-muted" style={{ padding: 16 }}>Tap menu items to add them.</div>}
                    </div>
                    <div className="pos-total-row" style={{ padding: '6px 0' }}><span>Subtotal</span><span>{money(subtotal)}</span></div>
                    {service > 0 && <div className="pos-total-row" style={{ padding: '2px 0' }}><span>Service {Number(order.service_charge_percent)}%</span><span>{money(service)}</span></div>}
                    <div className="pos-total-row" style={{ fontWeight: 700, fontSize: 18, padding: '6px 0' }}><span>Total before tax</span><span>{money(subtotal + service)}</span></div>
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
                        {editable && <Button text="Send to kitchen" type="default" icon="send" disabled={!order.lines.some((l) => l.kitchen_status === 'pending')} onClick={send} />}
                        {editable && <Button text="Print bill" icon="print" onClick={async () => { const o = await act('bill'); if (o) printBill(o); }} />}
                        {order.status === 'billed' && <Button text="Reopen" onClick={() => act('reopen')} />}
                        {order.status === 'billed' && <Button text="Reprint bill" icon="print" onClick={() => printBill(order)} />}
                        {editable && order.order_type === 'dine_in' && <Button text="Move table" onClick={move} />}
                        {editable && <Button text="Cancel order" stylingMode="outlined" type="danger" onClick={() => { const r = window.prompt('Reason?'); if (r) act('cancel', { reason: r }).then((o) => o && onClose()); }} />}
                        {can('sales.sale.create') && ['open', 'billed'].includes(order.status) && live.length > 0 && <Button text="Settle payment" type="success" icon="money" onClick={() => setSettling(true)} />}
                    </div>
                </div>
            </div>
            {picking && <ModifierPicker item={picking} groups={(menu.data?.modifier_groups ?? []).filter((g) => picking.modifier_group_ids.includes(g.id))}
                onClose={() => setPicking(null)} onAdd={(ids, note, qty) => { add(picking, ids, note, qty); setPicking(null); }} />}
            {settling && <SettleDialog order={order} estimate={subtotal + service} onClose={() => setSettling(false)} onDone={() => { setSettling(false); onClose(); }} />}
        </Popup>
    );
}

function ModifierPicker({ item, groups, onClose, onAdd }: { item: MenuItem; groups: Group[]; onClose: () => void; onAdd: (ids: string[], note: string, qty: number) => void }) {
    const [picked, setPicked] = useState<string[]>([]);
    const [note, setNote] = useState('');
    const [qty, setQty] = useState(1);
    const toggle = (g: Group, id: string) => {
        const inGroup = picked.filter((p) => g.options.some((o) => o.id === p));
        if (picked.includes(id)) return setPicked(picked.filter((p) => p !== id));
        if (g.max_select === 1) return setPicked([...picked.filter((p) => !inGroup.includes(p)), id]);
        if (inGroup.length < g.max_select) setPicked([...picked, id]);
    };
    const valid = groups.every((g) => picked.filter((p) => g.options.some((o) => o.id === p)).length >= g.min_select);
    const extra = groups.flatMap((g) => g.options).filter((o) => picked.includes(o.id)).reduce((s, o) => s + Number(o.price), 0);

    return (
        <Popup visible title={item.name} width={560} height="auto" maxHeight="90vh" onHiding={onClose} showCloseButton>
            {groups.map((g) => (
                <div key={g.id} style={{ marginBottom: 12 }}>
                    <b>{g.name}</b> <span className="erp-muted">{g.min_select ? `choose ${g.min_select}${g.max_select > g.min_select ? `–${g.max_select}` : ''}` : `optional, up to ${g.max_select}`}</span>
                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 6 }}>
                        {g.options.map((o) => <Button key={o.id} text={`${o.name}${Number(o.price) ? ` +${money(o.price)}` : ''}`} type={picked.includes(o.id) ? 'default' : 'normal'} onClick={() => toggle(g, o.id)} />)}
                    </div>
                </div>
            ))}
            <div style={{ display: 'grid', gridTemplateColumns: '120px 1fr', gap: 12 }}>
                <Field label="Quantity"><NumberBox min={1} max={99} showSpinButtons value={qty} onValueChanged={(e) => setQty(e.value ?? 1)} /></Field>
                <Field label="Kitchen note"><TextBox value={note} placeholder="No onions, extra spicy …" onValueChanged={(e) => setNote(e.value)} /></Field>
            </div>
            <div className="erp-actions">
                <span>{money((Number(item.sale_price) + extra) * qty)}</span>
                <div style={{ flex: 1 }} />
                <Button text="Cancel" onClick={onClose} />
                <Button text="Add" type="default" disabled={!valid} onClick={() => onAdd(picked, note, qty)} />
            </div>
        </Popup>
    );
}

function SettleDialog({ order, estimate, onClose, onDone }: { order: Order; estimate: number; onClose: () => void; onDone: () => void }) {
    const [method, setMethod] = useState('cash');
    const [amount, setAmount] = useState<number>(Math.ceil(estimate * 1.2));
    const [discount, setDiscount] = useState(0);
    const [split, setSplit] = useState(false);
    const [second, setSecond] = useState({ method: 'card', amount: 0 });
    const [busy, setBusy] = useState(false);
    const [key] = useState(newIdempotencyKey);

    const settle = async () => {
        setBusy(true);
        try {
            const tenders = [{ method, amount }, ...(split && second.amount > 0 ? [second] : [])];
            const { data } = await api.post(`/restaurant/orders/${order.id}/settle`, { tenders, discount: discount || null }, idempotent(key));
            const sale = data.data;
            notifySuccess(`Paid. ${sale.sale_no} · total ${money(sale.grand_total)}${Number(sale.change_total) > 0 ? ` · change ${money(sale.change_total)}` : ''}`);
            onDone();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title={`Settle ${order.order_no}`} width={520} height="auto" onHiding={onClose} showCloseButton>
            <p className="erp-muted">Approximate total with tax: {money(estimate)} + tax. Enter what the guest hands over; change is worked out on the receipt. Uses your open till shift.</p>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                <Field label="Paid by"><SelectBox dataSource={[{ id: 'cash', name: 'Cash' }, { id: 'card', name: 'Card' }, { id: 'wallet', name: 'Wallet (JazzCash / Easypaisa)' }, { id: 'bank_transfer', name: 'Bank transfer' }]} valueExpr="id" displayExpr="name" value={method} onValueChanged={(e) => setMethod(e.value)} /></Field>
                <Field label="Amount"><NumberBox min={0} format="#,##0.00" value={amount} onValueChanged={(e) => setAmount(e.value ?? 0)} /></Field>
                {can('sales.price.override') && <Field label="Discount"><NumberBox min={0} format="#,##0.00" value={discount} onValueChanged={(e) => setDiscount(e.value ?? 0)} /></Field>}
            </div>
            <CheckBox text="Split the bill across two payments" value={split} onValueChanged={(e) => setSplit(e.value)} />
            {split && (
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginTop: 8 }}>
                    <Field label="Second payment by"><SelectBox dataSource={[{ id: 'card', name: 'Card' }, { id: 'cash', name: 'Cash' }, { id: 'wallet', name: 'Wallet' }]} valueExpr="id" displayExpr="name" value={second.method} onValueChanged={(e) => setSecond({ ...second, method: e.value })} /></Field>
                    <Field label="Amount"><NumberBox min={0} format="#,##0.00" value={second.amount} onValueChanged={(e) => setSecond({ ...second, amount: e.value ?? 0 })} /></Field>
                </div>
            )}
            <div className="erp-actions"><Button text="Cancel" onClick={onClose} /><Button text={busy ? 'Settling…' : 'Settle'} type="success" disabled={busy || amount <= 0} onClick={settle} /></div>
        </Popup>
    );
}

function receipt(body: string) {
    const w = window.open('', '_blank', 'width=360,height=640');
    if (!w) return;
    w.document.write(`<html><head><style>body{font:13px monospace;width:72mm;margin:0 auto}table{width:100%;border-collapse:collapse}td{padding:2px 0;vertical-align:top}.r{text-align:right}.m{color:#555;font-size:11px}h3,h2{margin:4px 0;text-align:center}</style></head><body>${body}<script>window.onload=()=>window.print()</script></body></html>`);
    w.document.close();
}

/** Kitchen order ticket for the items just sent, grouped by station. */
function printKot(o: Order, lines: Line[]) {
    if (lines.length === 0) return;
    const byStation = lines.reduce<Record<string, Line[]>>((acc, l) => { (acc[l.station ?? 'Kitchen'] ??= []).push(l); return acc; }, {});
    receipt(Object.entries(byStation).map(([station, ls]) => `<h2>KOT ${ls[0].kot_no} · ${esc(station)}</h2><div>${esc(o.order_no)} · ${o.table ? `Table ${esc(o.table.name)}` : esc(o.order_type)} · ${new Date().toLocaleTimeString()}</div><hr>
        ${ls.map((l) => `<div><b>${esc(l.quantity)} × ${esc(l.product_name)}</b>${(l.modifiers ?? []).map((m) => `<div>&nbsp;+ ${esc(m.name)}</div>`).join('')}${l.note ? `<div>&nbsp;** ${esc(l.note)}</div>` : ''}</div>`).join('')}<hr>`).join('<div style="page-break-after:always"></div>'));
}
