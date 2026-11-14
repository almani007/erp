import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TextBox from 'devextreme-react/text-box';
import { api, errorMessage, getList, getOne, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { money, qty } from '../lib/format';
import { useAppConfig } from '../lib/config';
import { useCan } from '../lib/store';

interface CartLine {
    /** product + pack: the same product can be in the cart singly and by the carton. */
    key: string;
    product_id: string;
    /** Pack being sold (carton, dozen); null = single units. */
    unit_id: string | null;
    packs: { id: string; name: string; factor: string }[];
    /** Product needs one serial / IMEI per unit sold. */
    track_serial: boolean;
    /** Comma or space separated, as typed or scanned. */
    serials?: string;
    name: string;
    sku: string;
    quantity: number;
    /** Set only when the cashier keyed a price; otherwise the server prices the line. */
    unit_price?: number;
    discount?: number;
}

interface Tender {
    method: string;
    amount: number;
    reference?: string;
}

interface HeldCart {
    id: string;
    label: string;
    heldAt: string;
    cart: CartLine[];
    customerId: string | null;
    coupon: string;
}

const METHODS = [
    { id: 'cash', name: 'Cash' },
    { id: 'card', name: 'Card' },
    { id: 'bank_transfer', name: 'Bank transfer' },
    { id: 'wallet', name: 'Wallet' },
    { id: 'credit', name: 'On account' },
];

const round2 = (n: number) => Math.round(n * 100) / 100;

/** Lines as the API expects them: price and discount only when the cashier set them. */
const splitSerials = (text?: string) => (text ?? '').split(/[\s,;]+/).map((v) => v.trim()).filter(Boolean);

const toLines = (cart: CartLine[]) => cart.map(({ product_id, unit_id, quantity, unit_price, discount, track_serial, serials }) => ({
    product_id,
    quantity,
    ...(track_serial ? { serials: splitSerials(serials) } : {}),
    ...(unit_id ? { unit_id } : {}),
    ...(unit_price !== undefined ? { unit_price } : {}),
    ...(discount ? { discount } : {}),
}));

export default function PosPage() {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const shift = useQuery({ queryKey: ['pos-shift'], queryFn: () => getOne('/pos/shifts/current') });
    const refresh = () => queryClient.invalidateQueries({ queryKey: ['pos-shift'] });

    if (shift.isLoading) {
        return <div className="pos-empty">Loading…</div>;
    }
    if (shift.error) {
        return <div className="pos-empty erp-error">{errorMessage(shift.error)}</div>;
    }
    if (!shift.data) {
        return <OpenShift onOpened={refresh} onBack={() => navigate('/')} />;
    }

    return <Terminal shift={shift.data} onShiftChanged={refresh} onBack={() => navigate('/')} />;
}

function OpenShift({ onOpened, onBack }: { onOpened: () => void; onBack: () => void }) {
    const terminals = useQuery({ queryKey: ['pos-terminals'], queryFn: () => getList('/pos/terminals') });
    const [terminalId, setTerminalId] = useState<string | null>(null);
    const [openingCash, setOpeningCash] = useState(0);
    const [busy, setBusy] = useState(false);

    const open = async () => {
        setBusy(true);
        try {
            await api.post('/pos/shifts/open', { terminal_id: terminalId, opening_cash: openingCash });
            onOpened();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="erp-center">
            <div className="erp-card" style={{ width: 400, padding: 28, display: 'flex', flexDirection: 'column', gap: 16 }}>
                <div>
                    <h2 className="erp-page-title">Open a shift</h2>
                    <div className="erp-muted">Count the cash float in the drawer before you start selling.</div>
                </div>
                <SelectBox
                    label="Terminal" dataSource={terminals.data ?? []} valueExpr="id" value={terminalId} stylingMode="outlined"
                    displayExpr={(t: any) => (t ? `${t.name} — ${t.branch?.name ?? ''}` : '')} onValueChanged={(e) => setTerminalId(e.value)}
                />
                <NumberBox label="Opening cash" value={openingCash} min={0} format="#,##0.00" stylingMode="outlined" onValueChanged={(e) => setOpeningCash(e.value ?? 0)} />
                <Button text={busy ? 'Opening…' : 'Open shift'} type="default" height={44} disabled={busy || !terminalId} onClick={open} />
                <Button text="Back to ERP" stylingMode="text" onClick={onBack} />
            </div>
        </div>
    );
}

function Terminal({ shift, onShiftChanged, onBack }: { shift: any; onShiftChanged: () => void; onBack: () => void }) {
    const canOverride = useCan('sales.price.override');
    const heldKey = `erp-pos-held-${shift.terminal_id}`;
    const products = useQuery({ queryKey: ['products', 'active'], queryFn: () => getList('/products', { status: 'active', limit: 1000 }) });
    const categories = useQuery({ queryKey: ['categories'], queryFn: () => getList('/categories') });
    const customers = useQuery({ queryKey: ['customers'], queryFn: () => getList('/customers') });

    const [cart, setCart] = useState<CartLine[]>([]);
    const [quote, setQuote] = useState<any>(null);
    const [quoteError, setQuoteError] = useState<string | null>(null);
    const [customerId, setCustomerId] = useState<string | null>(null);
    const [coupon, setCoupon] = useState('');
    const [wholesale, setWholesale] = useState(false);
    const { config, features } = useAppConfig();
    const [code, setCode] = useState('');
    const [search, setSearch] = useState('');
    const [categoryId, setCategoryId] = useState<string | null>(null);
    const [dialog, setDialog] = useState<'pay' | 'close' | 'cash' | 'held' | null>(null);
    const [receipt, setReceipt] = useState<any>(null);
    const [held, setHeld] = useState<HeldCart[]>(() => {
        try {
            return JSON.parse(localStorage.getItem(heldKey) ?? '[]');
        } catch {
            return [];
        }
    });
    const quoteSeq = useRef(0);
    const scanRef = useRef<any>(null);

    const saveHeld = (next: HeldCart[]) => {
        setHeld(next);
        try {
            localStorage.setItem(heldKey, JSON.stringify(next));
        } catch {
            // Storage may be unavailable; held carts then last only while this page is open.
        }
    };

    // Totals always come from the server, so the screen matches what will be posted.
    useEffect(() => {
        if (cart.length === 0) {
            setQuote(null);
            setQuoteError(null);
            return;
        }
        const seq = ++quoteSeq.current;
        const timer = setTimeout(() => {
            api.post('/pos/quote', { lines: toLines(cart), coupon_code: coupon.trim() || null, customer_id: customerId, price_tier: wholesale ? 'wholesale' : null })
                .then(({ data }) => {
                    if (seq === quoteSeq.current) {
                        setQuote(data.data);
                        setQuoteError(null);
                    }
                })
                .catch((e) => {
                    if (seq === quoteSeq.current) {
                        setQuoteError(errorMessage(e));
                    }
                });
        }, 150);
        return () => clearTimeout(timer);
    }, [cart, coupon, customerId, wholesale]);

    const addProduct = useCallback((product: any, unitId: string | null = null) => {
        const key = `${product.id}|${unitId ?? ''}`;
        setCart((lines) => {
            const existing = lines.find((l) => l.key === key);
            if (existing) {
                return lines.map((l) => (l === existing ? { ...l, quantity: l.quantity + 1 } : l));
            }
            return [...lines, { key, product_id: product.id, unit_id: unitId, packs: product.units ?? [], track_serial: !!product.track_serial, name: product.name, sku: product.sku, quantity: 1 }];
        });
    }, []);

    const scan = async () => {
        const value = code.trim();
        if (!value) {
            return;
        }
        setCode('');
        try {
            const product = await getOne('/products/lookup', { code: value });
            addProduct(product, product.scanned_unit_id ?? null); // a carton barcode adds the carton
        } catch (e) {
            notifyError(e);
        }
    };

    const update = (key: string, patch: Partial<CartLine>) =>
        setCart((lines) => lines.map((l) => (l.key === key ? { ...l, ...patch } : l)));

    /** Switch a line between single units and a pack; merges with an identical line if there is one. */
    const changePack = (line: CartLine, unitId: string | null) =>
        setCart((lines) => {
            const key = `${line.product_id}|${unitId ?? ''}`;
            const twin = lines.find((l) => l.key === key && l !== line);
            if (twin) {
                return lines.filter((l) => l !== line).map((l) => (l === twin ? { ...l, quantity: l.quantity + line.quantity } : l));
            }
            return lines.map((l) => (l === line ? { ...l, key, unit_id: unitId, unit_price: undefined } : l));
        });

    const clear = () => {
        setCart([]);
        setCustomerId(null);
        setCoupon('');
        setWholesale(false);
    };

    const hold = () => {
        if (cart.length === 0) {
            return;
        }
        saveHeld([...held, {
            id: newIdempotencyKey(),
            label: customer?.name ?? `${cart.length} item${cart.length === 1 ? '' : 's'}`,
            heldAt: new Date().toISOString(),
            cart, customerId, coupon,
        }]);
        clear();
        notifySuccess('Sale put on hold');
    };

    const resume = (item: HeldCart) => {
        if (cart.length > 0) {
            notifyError(new Error('Finish or hold the current sale before resuming another.'));
            return;
        }
        setCart(item.cart);
        setCustomerId(item.customerId);
        setCoupon(item.coupon);
        saveHeld(held.filter((h) => h.id !== item.id));
        setDialog(null);
    };

    const serialsComplete = cart.every((l) => !l.track_serial || splitSerials(l.serials).length === l.quantity);
    const canPay = cart.length > 0 && !!quote && !quoteError && serialsComplete;

    // Keyboard shortcuts for the checkout lane.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (dialog || receipt) {
                return;
            }
            if (event.key === 'F2') {
                event.preventDefault();
                scanRef.current?.focus();
            } else if (event.key === 'F4' && canPay) {
                event.preventDefault();
                setDialog('pay');
            } else if (event.key === 'F8') {
                event.preventDefault();
                hold();
            } else if (event.key === 'F9') {
                event.preventDefault();
                setDialog('held');
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [dialog, receipt, canPay, cart, customerId, coupon, held]);

    const tiles = useMemo(() => {
        const term = search.trim().toLowerCase();
        return (products.data ?? [])
            .filter((p: any) => !categoryId || p.category_id === categoryId)
            .filter((p: any) => !term || p.name.toLowerCase().includes(term) || p.sku.toLowerCase().includes(term))
            .slice(0, 120);
    }, [products.data, categoryId, search]);

    // The server returns priced lines in cart order.
    const quoted = (index: number) => (quote?.lines?.length === cart.length ? quote.lines[index] : undefined);
    const customer = (customers.data ?? []).find((c: any) => c.id === customerId);
    const grandTotal = Number(quote?.grand_total ?? 0);

    return (
        <div className="pos">
            <div className="pos-left">
                <div className="pos-bar">
                    <Button icon="back" stylingMode="text" hint="Back to ERP" onClick={onBack} />
                    <div style={{ flex: 1 }}>
                        <TextBox
                            placeholder="Scan a barcode or type a SKU, then Enter" value={code} valueChangeEvent="input" stylingMode="outlined"
                            onInitialized={(e) => { scanRef.current = e.component; }} onValueChanged={(e) => setCode(e.value)} onEnterKey={scan}
                            inputAttr={{ autoFocus: true, 'aria-label': 'Barcode or SKU' }}
                        />
                    </div>
                    <div style={{ width: 240 }}>
                        <TextBox placeholder="Filter products…" value={search} valueChangeEvent="input" mode="search" stylingMode="outlined" onValueChanged={(e) => setSearch(e.value)} />
                    </div>
                </div>
                <div className="pos-cats">
                    <button type="button" className={`pos-chip${categoryId === null ? ' active' : ''}`} onClick={() => setCategoryId(null)}>All</button>
                    {(categories.data ?? []).map((c: any) => (
                        <button type="button" key={c.id} className={`pos-chip${categoryId === c.id ? ' active' : ''}`} onClick={() => setCategoryId(c.id)}>{c.name}</button>
                    ))}
                </div>
                <div className="pos-tiles">
                    {tiles.map((p: any) => (
                        <button type="button" className="pos-tile" key={p.id} onClick={() => addProduct(p)}>
                            <span className="pos-tile-name">{p.name}</span>
                            <span className="pos-tile-sku">{p.sku}</span>
                            <span className="pos-tile-price">{p.pricing_mode === 'rate' ? 'Today\'s rate' : money(p.sale_price)}</span>
                        </button>
                    ))}
                    {tiles.length === 0 && <div className="erp-muted">No products match.</div>}
                </div>
                <div className="pos-keys">
                    <kbd>F2</kbd>Scan&nbsp;&nbsp; <kbd>F4</kbd>Pay&nbsp;&nbsp; <kbd>F8</kbd>Hold&nbsp;&nbsp; <kbd>F9</kbd>Held sales
                </div>
            </div>

            <div className="pos-right">
                <div className="pos-shiftbar">
                    <div>
                        <div style={{ fontWeight: 650 }}>{shift.terminal?.name}</div>
                        <div className="erp-muted">Shift {shift.shift_no} · {shift.summary.sales_count} sales · {money(shift.summary.sales_total)}</div>
                    </div>
                    <div style={{ display: 'flex', gap: 4 }}>
                        <Button icon="money" stylingMode="text" hint="Cash in / out" onClick={() => setDialog('cash')} />
                        <Button text="Close shift" stylingMode="outlined" onClick={() => setDialog('close')} />
                    </div>
                </div>

                {features.wholesale && (
                    <div style={{ padding: '12px 16px 0', display: 'flex', gap: 8, alignItems: 'center' }}>
                        <button type="button" className={`pos-chip${!wholesale ? ' active' : ''}`} onClick={() => setWholesale(false)}>Retail</button>
                        <button type="button" className={`pos-chip${wholesale ? ' active' : ''}`} onClick={() => setWholesale(true)}>Wholesale</button>
                        <span className="erp-muted">
                            {quote?.price_tier === 'wholesale' ? 'Wholesale prices apply where the minimum quantity is met' : 'Retail prices'}
                        </span>
                    </div>
                )}
                <div style={{ padding: '12px 16px 4px', display: 'grid', gridTemplateColumns: '1fr 140px', gap: 8 }}>
                    <SelectBox
                        placeholder="Walk-in customer" dataSource={customers.data ?? []} valueExpr="id" displayExpr="name" value={customerId}
                        searchEnabled showClearButton stylingMode="outlined" onValueChanged={(e) => setCustomerId(e.value ?? null)}
                    />
                    <TextBox placeholder="Coupon" value={coupon} stylingMode="outlined" onValueChanged={(e) => setCoupon((e.value ?? '').toUpperCase())} />
                </div>

                <div className="pos-cart">
                    {cart.length === 0 && <div className="pos-empty">The cart is empty.<br />Scan an item or tap a product.</div>}
                    {cart.map((line, index) => {
                        const q = quoted(index);
                        return (
                            <div className="pos-line" key={line.key}>
                                <div className="pos-line-top">
                                    <div>
                                        <div className="pos-line-name">{line.name}</div>
                                        {line.packs.length > 0 && (
                                            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', margin: '4px 0' }}>
                                                <button type="button" className={`pos-chip${line.unit_id === null ? ' active' : ''}`} style={{ padding: '2px 10px' }} onClick={() => changePack(line, null)}>Single</button>
                                                {line.packs.map((pack) => (
                                                    <button type="button" key={pack.id} className={`pos-chip${line.unit_id === pack.id ? ' active' : ''}`} style={{ padding: '2px 10px' }} onClick={() => changePack(line, pack.id)}>{pack.name}</button>
                                                ))}
                                            </div>
                                        )}
                                        {q?.promotion_name && <div className="pos-promo">{q.promotion_name} · −{money(q.promotion_discount)}</div>}
                                        {line.track_serial && (
                                            <div style={{ marginTop: 4 }}>
                                                <TextBox
                                                    placeholder={`Serial / IMEI — ${line.quantity} needed`} value={line.serials ?? ''} valueChangeEvent="change"
                                                    onValueChanged={(e) => update(line.key, { serials: e.value })}
                                                    isValid={splitSerials(line.serials).length === line.quantity}
                                                    validationError={{ message: 'Enter one serial per unit, separated by commas or spaces' }}
                                                />
                                            </div>
                                        )}
                                    </div>
                                    <div className="pos-line-total">{q ? money(q.total) : '…'}</div>
                                </div>
                                <div className="pos-line-controls">
                                    <NumberBox value={line.quantity} min={0.001} showSpinButtons step={1} onValueChanged={(e) => update(line.key, { quantity: e.value || 1 })} />
                                    <NumberBox
                                        value={line.unit_price ?? Number(q?.unit_price ?? 0)} min={0} format="#,##0.00" readOnly={!canOverride} hint="Unit price"
                                        onValueChanged={(e) => e.event && update(line.key, { unit_price: e.value ?? 0 })}
                                    />
                                    <NumberBox
                                        value={line.discount ?? 0} min={0} format="#,##0.00" readOnly={!canOverride} hint="Extra discount on this line" placeholder="Discount"
                                        onValueChanged={(e) => e.event && update(line.key, { discount: e.value ?? 0 })}
                                    />
                                    <Button icon="trash" stylingMode="text" hint="Remove" onClick={() => setCart((lines) => lines.filter((l) => l !== line))} />
                                </div>
                            </div>
                        );
                    })}
                </div>

                <div className="pos-totals">
                    <div className="pos-total-row"><span>Subtotal</span><span>{money(quote?.subtotal)}</span></div>
                    <div className="pos-total-row"><span>Discount{quote?.coupon ? ` (${quote.coupon})` : ''}</span><span>−{money(quote?.discount_total)}</span></div>
                    <div className="pos-total-row"><span>Tax</span><span>{money(quote?.tax_total)}</span></div>
                    <div className="pos-total-row grand"><span>Total</span><span>{money(grandTotal)}</span></div>
                    {quoteError && <div className="erp-error" role="alert">{quoteError}</div>}
                </div>
                <div className="pos-actions">
                    <Button text="Clear" stylingMode="outlined" disabled={cart.length === 0} onClick={clear} />
                    <Button text="Hold" stylingMode="outlined" disabled={cart.length === 0} onClick={hold} />
                    <Button text={`Held (${held.length})`} stylingMode="outlined" disabled={held.length === 0} onClick={() => setDialog('held')} />
                    <div className="pos-pay">
                        <Button text={`Pay ${money(grandTotal)}`} type="default" width="100%" height={52} disabled={!canPay} onClick={() => setDialog('pay')} />
                    </div>
                </div>
            </div>

            {dialog === 'pay' && (
                <Payment
                    cart={cart} total={grandTotal} customerId={customerId} coupon={coupon} wholesale={wholesale}
                    points={Number(customer?.loyalty_points ?? 0)} pointValue={config?.loyalty.enabled ? Number(config.loyalty.point_value) : 0}
                    onClose={() => setDialog(null)}
                    onDone={(sale) => {
                        setDialog(null);
                        clear();
                        setReceipt(sale);
                        onShiftChanged();
                    }}
                />
            )}
            {dialog === 'close' && <CloseShift shift={shift} onClose={() => setDialog(null)} onClosed={() => { setDialog(null); onShiftChanged(); }} />}
            {dialog === 'cash' && <CashMovement onClose={() => setDialog(null)} onDone={() => { setDialog(null); onShiftChanged(); }} />}
            {dialog === 'held' && (
                <Popup visible title="Held sales" width={460} height="auto" onHiding={() => setDialog(null)} showCloseButton>
                    <div className="erp-muted" style={{ marginBottom: 8 }}>Held sales are kept on this device only.</div>
                    {held.length === 0 && <div className="erp-muted">Nothing on hold.</div>}
                    {held.map((item) => (
                        <div className="erp-list-row" key={item.id}>
                            <div>
                                <div style={{ fontWeight: 600 }}>{item.label}</div>
                                <div className="erp-muted">{item.cart.length} lines · {new Date(item.heldAt).toLocaleTimeString()}</div>
                            </div>
                            <div style={{ display: 'flex', gap: 4 }}>
                                <Button text="Resume" type="default" stylingMode="outlined" onClick={() => resume(item)} />
                                <Button icon="trash" stylingMode="text" hint="Discard" onClick={() => saveHeld(held.filter((h) => h.id !== item.id))} />
                            </div>
                        </div>
                    ))}
                </Popup>
            )}
            {receipt && <Receipt sale={receipt} onClose={() => setReceipt(null)} />}
        </div>
    );
}

function Payment({ cart, total, customerId, coupon, wholesale, points, pointValue, onClose, onDone }: {
    cart: CartLine[]; total: number; customerId: string | null; coupon: string; wholesale: boolean;
    /** The customer's loyalty points and what one point is worth (0 = loyalty off). */
    points: number; pointValue: number;
    onClose: () => void; onDone: (sale: any) => void;
}) {
    const pointsWorth = round2(points * pointValue);
    const methods = pointsWorth > 0 ? [...METHODS, { id: 'loyalty', name: `Loyalty points (${points} = ${money(pointsWorth)})` }] : METHODS;
    const [tenders, setTenders] = useState<Tender[]>([]);
    const [method, setMethod] = useState('cash');
    const [amount, setAmount] = useState(total);
    const [reference, setReference] = useState('');
    const [busy, setBusy] = useState(false);
    // One key for this payment attempt: retrying after a timeout cannot create a second sale.
    const key = useRef(newIdempotencyKey());

    const paid = round2(tenders.reduce((sum, t) => sum + t.amount, 0));
    const remaining = Math.max(0, round2(total - paid));
    const change = Math.max(0, round2(paid - total));
    const quickCash = [...new Set([total, Math.ceil(total / 100) * 100, Math.ceil(total / 500) * 500, Math.ceil(total / 1000) * 1000])].filter((v) => v > 0);

    const addTender = (tender: Tender) => {
        if (tender.amount <= 0) {
            return;
        }
        const next = [...tenders, tender];
        setTenders(next);
        setReference('');
        setAmount(Math.max(0, round2(total - next.reduce((s, t) => s + t.amount, 0))));
    };

    const complete = async () => {
        setBusy(true);
        try {
            const { data } = await api.post('/sales', {
                customer_id: customerId, coupon_code: coupon.trim() || null, price_tier: wholesale ? 'wholesale' : null, lines: toLines(cart), tenders,
            }, idempotent(key.current));
            onDone(data.data);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title={`Payment — ${money(total)}`} width={580} height="auto" onHiding={onClose} showCloseButton>
            {tenders.length === 0 && (
                <div className="erp-row">
                    {quickCash.map((value) => (
                        <Button key={value} text={`Cash ${money(value)}`} stylingMode="outlined" onClick={() => addTender({ method: 'cash', amount: value })} />
                    ))}
                </div>
            )}
            <div className="erp-row">
                <SelectBox
                    label="Method" width={200} dataSource={methods} valueExpr="id" displayExpr="name" value={method}
                    onValueChanged={(e) => {
                        setMethod(e.value);
                        if (e.value === 'loyalty') {
                            setAmount((current) => Math.min(current, pointsWorth)); // cannot spend more than the points are worth
                        }
                    }}
                />
                <NumberBox label="Amount" width={140} value={amount} min={0} max={method === 'loyalty' ? pointsWorth : undefined} format="#,##0.00" onValueChanged={(e) => setAmount(e.value ?? 0)} />
                {method !== 'cash' && method !== 'credit' && method !== 'loyalty' && (
                    <TextBox label="Reference" width={130} value={reference} onValueChanged={(e) => setReference(e.value)} />
                )}
                <Button text="Add" stylingMode="outlined" onClick={() => addTender({ method, amount, reference: reference || undefined })} />
            </div>
            {method === 'credit' && !customerId && <div className="erp-error">Select a customer on the sale to sell on account.</div>}
            {tenders.map((t, i) => (
                <div className="pos-total-row" key={i}>
                    <span>{t.method === 'loyalty' ? 'Loyalty points' : METHODS.find((m) => m.id === t.method)?.name}{t.reference ? ` (${t.reference})` : ''}</span>
                    <span>
                        {money(t.amount)}
                        <Button icon="close" stylingMode="text" hint="Remove" onClick={() => setTenders(tenders.filter((_, j) => j !== i))} />
                    </span>
                </div>
            ))}
            <div className="pos-total-row grand">
                <span>{remaining > 0 ? 'Remaining' : 'Change'}</span>
                <span>{money(remaining > 0 ? remaining : change)}</span>
            </div>
            <div className="erp-actions">
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Posting…' : 'Complete sale'} type="success" height={44} disabled={busy || tenders.length === 0 || remaining > 0} onClick={complete} />
            </div>
        </Popup>
    );
}

function Receipt({ sale, onClose }: { sale: any; onClose: () => void }) {
    return (
        <Popup visible title="Sale completed" width={420} height="auto" onHiding={onClose} showCloseButton>
            <div className="erp-receipt">
                <div style={{ textAlign: 'center', marginBottom: 12 }}>
                    <div style={{ fontWeight: 700 }}>{sale.sale_no}</div>
                    <div className="erp-muted">{new Date(sale.sold_at).toLocaleString()}</div>
                    {sale.customer && <div className="erp-muted">{sale.customer.name}</div>}
                </div>
                {sale.items.map((item: any) => (
                    <div className="pos-total-row" key={item.id}>
                        <span>{qty(item.quantity)} {item.unit_name ?? '×'} {item.product_name}</span>
                        <span>{money(item.line_total)}</span>
                    </div>
                ))}
                {Number(sale.discount_total) > 0 && <div className="pos-total-row" style={{ marginTop: 8 }}><span>You saved</span><span>{money(sale.discount_total)}</span></div>}
                <div className="pos-total-row"><span>Tax (included in total)</span><span>{money(sale.tax_total)}</span></div>
                <div className="pos-total-row grand"><span>Total</span><span>{money(sale.grand_total)}</span></div>
                <div className="pos-total-row"><span>Paid</span><span>{money(sale.paid_total)}</span></div>
                <div className="pos-total-row"><span>Change</span><span>{money(sale.change_total)}</span></div>
                {(sale.points_earned > 0 || sale.points_redeemed > 0) && (
                    <div className="pos-total-row"><span>Loyalty points</span><span>+{sale.points_earned} earned{sale.points_redeemed > 0 ? `, ${sale.points_redeemed} used` : ''}</span></div>
                )}
            </div>
            <div className="erp-actions">
                <Button text="Print" icon="print" onClick={() => window.print()} />
                <Button text="New sale" type="default" onClick={onClose} />
            </div>
        </Popup>
    );
}

function CashMovement({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
    const [type, setType] = useState('paid_out');
    const [amount, setAmount] = useState(0);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    const save = async () => {
        setBusy(true);
        try {
            await api.post('/pos/shifts/current/cash-movements', { type, amount, reason });
            notifySuccess('Cash movement recorded');
            onDone();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title="Cash in / out" width={440} height="auto" onHiding={onClose} showCloseButton>
            <div className="erp-row">
                <SelectBox
                    label="Type" width={170} value={type} valueExpr="id" displayExpr="name" onValueChanged={(e) => setType(e.value)}
                    dataSource={[{ id: 'paid_out', name: 'Cash out of drawer' }, { id: 'paid_in', name: 'Cash into drawer' }]}
                />
                <NumberBox label="Amount" width={150} value={amount} min={0} format="#,##0.00" onValueChanged={(e) => setAmount(e.value ?? 0)} />
            </div>
            <TextBox label="Reason (required)" value={reason} valueChangeEvent="input" onValueChanged={(e) => setReason(e.value)} />
            <div className="erp-muted" style={{ marginTop: 10 }}>This changes the expected drawer cash and is audited. It does not post a journal yet.</div>
            <div className="erp-actions">
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Saving…' : 'Record'} type="default" disabled={busy || amount <= 0 || reason.trim().length < 3} onClick={save} />
            </div>
        </Popup>
    );
}

function CloseShift({ shift, onClose, onClosed }: { shift: any; onClose: () => void; onClosed: () => void }) {
    const [counted, setCounted] = useState(0);
    const [notes, setNotes] = useState('');
    const [busy, setBusy] = useState(false);
    const s = shift.summary;
    const variance = round2(counted - Number(s.expected_cash));

    const close = async () => {
        setBusy(true);
        try {
            const { data } = await api.post('/pos/shifts/current/close', { counted_cash: counted, notes: notes || null });
            notifySuccess(`Shift closed. Variance: ${money(data.data.variance)}`);
            onClosed();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title={`Close shift ${shift.shift_no}`} width={460} height="auto" onHiding={onClose} showCloseButton>
            <div className="pos-total-row"><span>Opening cash</span><span>{money(s.opening_cash)}</span></div>
            <div className="pos-total-row"><span>Cash sales (net of change)</span><span>{money(s.cash_sales)}</span></div>
            <div className="pos-total-row"><span>Cash refunds</span><span>−{money(s.cash_refunds)}</span></div>
            <div className="pos-total-row"><span>Paid in / out</span><span>{money(s.paid_in)} / {money(s.paid_out)}</span></div>
            <div className="pos-total-row grand"><span>Expected in drawer</span><span>{money(s.expected_cash)}</span></div>
            <div className="erp-row" style={{ marginTop: 16 }}>
                <NumberBox label="Counted cash" width={180} value={counted} min={0} format="#,##0.00" onValueChanged={(e) => setCounted(e.value ?? 0)} />
                <TextBox label="Notes" width={220} value={notes} onValueChanged={(e) => setNotes(e.value)} />
            </div>
            <div className="pos-total-row" style={{ color: variance === 0 ? undefined : 'var(--erp-danger)', fontWeight: 600 }}>
                <span>Variance</span><span>{money(variance)}</span>
            </div>
            <div className="erp-muted">Any difference is posted to the Cash over/short account.</div>
            <div className="erp-actions">
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Closing…' : 'Close shift'} type="default" disabled={busy} onClick={close} />
            </div>
        </Popup>
    );
}
