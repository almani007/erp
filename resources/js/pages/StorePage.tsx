import { useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import axios from 'axios';

/** The business's public online store: order for delivery or pick-up, and book appointments. No login. */
interface Product { id: string; name: string; description: string | null; sale_price: string; image_url: string | null; category: string | null; available: boolean }
interface Service { id: string; name: string; sale_price: string; duration_minutes: number; description: string | null }
interface Store { store: { title: string; about: string | null; whatsapp: string | null; min_order: string | null; delivery_fee_note: string | null; booking_enabled: boolean }; products: Product[]; services: Service[] }

const pkr = (v: number | string) => `Rs ${Number(v).toLocaleString('en-PK', { maximumFractionDigits: 2 })}`;
const http = axios.create({ baseURL: '/api/v1/public/stores', headers: { Accept: 'application/json' } });
const message = (e: any) => e?.response?.data?.error?.message ?? (e?.response?.data?.errors ? Object.values(e.response.data.errors).flat().join(' ') : 'Something went wrong. Please try again.');

export default function StorePage() {
    const { slug = '' } = useParams();
    const [data, setData] = useState<Store | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [cart, setCart] = useState<Record<string, number>>(() => { try { return JSON.parse(localStorage.getItem(`cart:${slug}`) ?? '{}'); } catch { return {}; } });
    const [tab, setTab] = useState<'shop' | 'book'>('shop');
    const [category, setCategory] = useState<string>('All');
    const [search, setSearch] = useState('');
    const [checkout, setCheckout] = useState(false);
    const [done, setDone] = useState<string | null>(null);

    useEffect(() => { http.get(`/${slug}`).then((r) => setData(r.data.data)).catch(() => setError('This store is not available.')); }, [slug]);
    useEffect(() => { try { localStorage.setItem(`cart:${slug}`, JSON.stringify(cart)); } catch { /* storage may be off */ } }, [cart, slug]);

    const products = data?.products ?? [];
    const categories = useMemo(() => ['All', ...Array.from(new Set(products.map((p) => p.category ?? 'Other')))], [products]);
    const shown = products.filter((p) => (category === 'All' || (p.category ?? 'Other') === category) && p.name.toLowerCase().includes(search.toLowerCase()));
    const lines = Object.entries(cart).filter(([, q]) => q > 0).map(([id, q]) => ({ product: products.find((p) => p.id === id)!, q })).filter((l) => l.product);
    const total = lines.reduce((s, l) => s + Number(l.product.sale_price) * l.q, 0);
    const add = (id: string, d: number) => setCart((c) => ({ ...c, [id]: Math.max(0, (c[id] ?? 0) + d) }));

    if (error) return <div className="store"><h2>{error}</h2></div>;
    if (!data) return <div className="store">Loading…</div>;
    if (done) return <div className="store"><div className="store-card"><h2>Thank you!</h2><p>{done}</p><button className="store-btn" onClick={() => setDone(null)}>Back to the store</button></div></div>;

    return (
        <div className="store">
            <header className="store-head">
                <div><h1>{data.store.title}</h1>{data.store.about && <p>{data.store.about}</p>}</div>
                {data.store.whatsapp && <a className="store-btn" href={`https://wa.me/${data.store.whatsapp.replace(/\D/g, '').replace(/^0/, '92')}`} target="_blank" rel="noreferrer">WhatsApp us</a>}
            </header>
            {data.store.booking_enabled && data.services.length > 0 && (
                <div className="store-tabs">
                    <button className={tab === 'shop' ? 'on' : ''} onClick={() => setTab('shop')}>Order</button>
                    <button className={tab === 'book' ? 'on' : ''} onClick={() => setTab('book')}>Book an appointment</button>
                </div>
            )}
            {tab === 'book' ? <Booking slug={slug} services={data.services} onDone={setDone} /> : (
                <>
                    <input className="store-input" placeholder="Search" value={search} onChange={(e) => setSearch(e.target.value)} />
                    <div className="store-chips">{categories.map((c) => <button key={c} className={category === c ? 'on' : ''} onClick={() => setCategory(c)}>{c}</button>)}</div>
                    <div className="store-grid">
                        {shown.map((p) => (
                            <div key={p.id} className="store-card">
                                {p.image_url && <img src={p.image_url} alt="" loading="lazy" />}
                                <div className="store-name">{p.name}</div>
                                {p.description && <div className="store-muted">{p.description}</div>}
                                <div className="store-price">{pkr(p.sale_price)}</div>
                                {!p.available ? <div className="store-muted">Out of stock</div> : cart[p.id]
                                    ? <div className="store-qty"><button onClick={() => add(p.id, -1)}>−</button><span>{cart[p.id]}</span><button onClick={() => add(p.id, 1)}>+</button></div>
                                    : <button className="store-btn" onClick={() => add(p.id, 1)}>Add</button>}
                            </div>
                        ))}
                    </div>
                    {lines.length > 0 && (
                        <div className="store-bar">
                            <span>{lines.reduce((s, l) => s + l.q, 0)} items · {pkr(total)}</span>
                            <button className="store-btn" onClick={() => setCheckout(true)}>Checkout</button>
                        </div>
                    )}
                    {checkout && <Checkout slug={slug} lines={lines} total={total} store={data.store} onClose={() => setCheckout(false)}
                        onDone={(text) => { setCart({}); setCheckout(false); setDone(text); }} />}
                </>
            )}
        </div>
    );
}

function Checkout({ slug, lines, total, store, onClose, onDone }: { slug: string; lines: { product: Product; q: number }[]; total: number; store: Store['store']; onClose: () => void; onDone: (t: string) => void }) {
    const [f, setF] = useState({ name: '', phone: '', fulfilment: 'delivery', address: '', notes: '', website: '' });
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState<string | null>(null);
    const submit = async () => {
        setBusy(true); setErr(null);
        try {
            const { data } = await http.post(`/${slug}/orders`, { ...f, website: f.website || undefined, items: lines.map((l) => ({ product_id: l.product.id, quantity: l.q })) });
            onDone(`Your order ${data.data.order_no} for ${pkr(data.data.total)} is received. We will call you on ${f.phone} to confirm.`);
        } catch (e) {
            setErr(message(e));
        } finally {
            setBusy(false);
        }
    };
    return (
        <div className="store-modal"><div className="store-card store-sheet">
            <h2>Checkout</h2>
            {lines.map((l) => <div key={l.product.id} className="store-row"><span>{l.q} × {l.product.name}</span><span>{pkr(Number(l.product.sale_price) * l.q)}</span></div>)}
            <div className="store-row"><b>Total (tax as applicable)</b><b>{pkr(total)}</b></div>
            {store.delivery_fee_note && <p className="store-muted">{store.delivery_fee_note}</p>}
            {store.min_order && total < Number(store.min_order) && <p className="store-err">Minimum order is {pkr(store.min_order)}.</p>}
            <input className="store-input" placeholder="Your name" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
            <input className="store-input" placeholder="Mobile number (03xx-xxxxxxx)" inputMode="tel" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} />
            <div className="store-chips">
                <button className={f.fulfilment === 'delivery' ? 'on' : ''} onClick={() => setF({ ...f, fulfilment: 'delivery' })}>Delivery</button>
                <button className={f.fulfilment === 'pickup' ? 'on' : ''} onClick={() => setF({ ...f, fulfilment: 'pickup' })}>I will pick up</button>
            </div>
            {f.fulfilment === 'delivery' && <textarea className="store-input" placeholder="Delivery address" value={f.address} onChange={(e) => setF({ ...f, address: e.target.value })} />}
            <input className="store-input" placeholder="Notes (optional)" value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} />
            <input style={{ display: 'none' }} tabIndex={-1} autoComplete="off" value={f.website} onChange={(e) => setF({ ...f, website: e.target.value })} aria-hidden />
            {err && <p className="store-err">{err}</p>}
            <div className="store-row"><button className="store-btn ghost" onClick={onClose}>Back</button>
                <button className="store-btn" disabled={busy || !f.name || f.phone.replace(/\D/g, '').length < 10 || (f.fulfilment === 'delivery' && !f.address)} onClick={submit}>{busy ? 'Placing…' : 'Place order'}</button></div>
        </div></div>
    );
}

function Booking({ slug, services, onDone }: { slug: string; services: Service[]; onDone: (t: string) => void }) {
    const [service, setService] = useState<Service | null>(null);
    const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
    const [slots, setSlots] = useState<{ resource_id: string; with: string; start: string }[] | null>(null);
    const [slot, setSlot] = useState<{ resource_id: string; with: string; start: string } | null>(null);
    const [f, setF] = useState({ name: '', phone: '', notes: '' });
    const [err, setErr] = useState<string | null>(null);

    useEffect(() => {
        if (!service) return;
        setSlots(null); setSlot(null);
        http.get(`/${slug}/slots`, { params: { product_id: service.id, date } }).then((r) => setSlots(r.data.data)).catch((e) => setErr(message(e)));
    }, [service, date, slug]);

    const book = async () => {
        setErr(null);
        try {
            const { data } = await http.post(`/${slug}/bookings`, { product_id: service!.id, resource_id: slot!.resource_id, starts_at: slot!.start, ...f });
            onDone(`Booked: ${service!.name} with ${slot!.with} on ${new Date(data.data.starts_at).toLocaleString()} (${data.data.appointment_no}).`);
        } catch (e) {
            setErr(message(e));
        }
    };

    return (
        <div>
            <div className="store-grid">
                {services.map((s) => (
                    <button key={s.id} className={`store-card ${service?.id === s.id ? 'sel' : ''}`} onClick={() => setService(s)} style={{ textAlign: 'left' }}>
                        <div className="store-name">{s.name}</div><div className="store-muted">{s.duration_minutes} min</div><div className="store-price">{pkr(s.sale_price)}</div>
                    </button>
                ))}
            </div>
            {service && (
                <div className="store-card" style={{ marginTop: 12 }}>
                    <input type="date" className="store-input" min={new Date().toISOString().slice(0, 10)} value={date} onChange={(e) => setDate(e.target.value)} />
                    {slots === null ? <p>Finding times…</p> : slots.length === 0 ? <p>No free times that day — try another date.</p> : (
                        <div className="store-chips">{slots.map((s) => (
                            <button key={s.resource_id + s.start} className={slot === s ? 'on' : ''} onClick={() => setSlot(s)}>
                                {new Date(s.start).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} · {s.with}
                            </button>
                        ))}</div>
                    )}
                    {slot && (
                        <>
                            <input className="store-input" placeholder="Your name" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
                            <input className="store-input" placeholder="Mobile number" inputMode="tel" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} />
                            <input className="store-input" placeholder="Notes (optional)" value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} />
                            {err && <p className="store-err">{err}</p>}
                            <button className="store-btn" disabled={!f.name || f.phone.replace(/\D/g, '').length < 10} onClick={book}>Confirm booking</button>
                        </>
                    )}
                </div>
            )}
        </div>
    );
}
