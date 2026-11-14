import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TextBox from 'devextreme-react/text-box';
import { api, errorMessage, getList, getOne, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { money, qty } from '../lib/format';
import { useCan } from '../lib/store';

interface CartLine {
    product_id: string;
    name: string;
    sku: string;
    quantity: number;
    unit_price: number;
}

interface Tender {
    method: string;
    amount: number;
    reference?: string;
}

const METHODS = [
    { id: 'cash', name: 'Cash' },
    { id: 'card', name: 'Card' },
    { id: 'bank_transfer', name: 'Bank transfer' },
    { id: 'wallet', name: 'Wallet' },
    { id: 'credit', name: 'On account' },
];

export default function PosPage() {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const shift = useQuery({ queryKey: ['pos-shift'], queryFn: () => getOne('/pos/shifts/current') });

    if (shift.isLoading) {
        return <div className="pos-empty">Loading…</div>;
    }
    if (shift.error) {
        return <div className="pos-empty erp-error">{errorMessage(shift.error)}</div>;
    }
    if (!shift.data) {
        return <OpenShift onOpened={() => queryClient.invalidateQueries({ queryKey: ['pos-shift'] })} onBack={() => navigate('/')} />;
    }

    return <Terminal shift={shift.data} onShiftChanged={() => queryClient.invalidateQueries({ queryKey: ['pos-shift'] })} onBack={() => navigate('/')} />;
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
        <div className="erp-login">
            <div className="erp-card erp-login-card">
                <div>
                    <div className="erp-brand" style={{ fontSize: '1.3rem' }}>Open a shift</div>
                    <div className="erp-muted">Count the cash float in the drawer before you start selling.</div>
                </div>
                <SelectBox
                    label="Terminal" dataSource={terminals.data ?? []} valueExpr="id" value={terminalId} stylingMode="outlined"
                    displayExpr={(t: any) => (t ? `${t.name} — ${t.branch?.name ?? ''}` : '')} onValueChanged={(e) => setTerminalId(e.value)}
                />
                <NumberBox label="Opening cash" value={openingCash} min={0} format="#,##0.00" stylingMode="outlined" onValueChanged={(e) => setOpeningCash(e.value ?? 0)} />
                <Button text={busy ? 'Opening…' : 'Open shift'} type="default" disabled={busy || !terminalId} onClick={open} />
                <Button text="Back to ERP" stylingMode="text" onClick={onBack} />
            </div>
        </div>
    );
}

function Terminal({ shift, onShiftChanged, onBack }: { shift: any; onShiftChanged: () => void; onBack: () => void }) {
    const canOverride = useCan('sales.price.override');
    const customers = useQuery({ queryKey: ['customers'], queryFn: () => getList('/customers') });
    const [cart, setCart] = useState<CartLine[]>([]);
    const [quote, setQuote] = useState<any>(null);
    const [quoteError, setQuoteError] = useState<string | null>(null);
    const [customerId, setCustomerId] = useState<string | null>(null);
    const [code, setCode] = useState('');
    const [paying, setPaying] = useState(false);
    const [closing, setClosing] = useState(false);
    const [receipt, setReceipt] = useState<any>(null);
    const quoteSeq = useRef(0);

    // Totals always come from the server, so the screen matches what will be posted.
    useEffect(() => {
        if (cart.length === 0) {
            setQuote(null);
            setQuoteError(null);
            return;
        }
        const seq = ++quoteSeq.current;
        api.post('/pos/quote', { lines: cart.map(({ product_id, quantity, unit_price }) => ({ product_id, quantity, unit_price })) })
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
    }, [cart]);

    const addProduct = useCallback((product: any) => {
        setCart((lines) => {
            const existing = lines.find((l) => l.product_id === product.id);
            if (existing) {
                return lines.map((l) => (l === existing ? { ...l, quantity: l.quantity + 1 } : l));
            }
            return [...lines, { product_id: product.id, name: product.name, sku: product.sku, quantity: 1, unit_price: Number(product.sale_price) }];
        });
    }, []);

    const scan = async () => {
        const value = code.trim();
        if (!value) {
            return;
        }
        setCode('');
        try {
            addProduct(await getOne('/products/lookup', { code: value }));
        } catch (e) {
            notifyError(e);
        }
    };

    const update = (productId: string, patch: Partial<CartLine>) =>
        setCart((lines) => lines.map((l) => (l.product_id === productId ? { ...l, ...patch } : l)));

    const quotedTotal = (productId: string) => quote?.lines?.find((l: any) => l.product_id === productId)?.total;
    const grandTotal = Number(quote?.grand_total ?? 0);

    return (
        <div className="pos-terminal">
            <div className="pos-cart">
                <div className="pos-cart-header">
                    <Button icon="back" stylingMode="text" hint="Back to ERP" onClick={onBack} />
                    <div style={{ flex: 1 }}>
                        <TextBox
                            placeholder="Scan a barcode or type a SKU, then press Enter" value={code} valueChangeEvent="input"
                            onValueChanged={(e) => setCode(e.value)} onEnterKey={scan} stylingMode="outlined"
                            inputAttr={{ autoFocus: true }}
                        />
                    </div>
                    <ProductSearch onPick={addProduct} />
                </div>
                <div className="pos-cart-items">
                    {cart.length === 0 && <div className="pos-empty">The cart is empty. Scan an item to begin.</div>}
                    {cart.map((line) => (
                        <div className="pos-line" key={line.product_id}>
                            <div>
                                <div className="pos-line-name">{line.name}</div>
                                <div className="erp-muted">{line.sku}</div>
                            </div>
                            <NumberBox value={line.quantity} min={0.001} showSpinButtons step={1} onValueChanged={(e) => update(line.product_id, { quantity: e.value || 1 })} />
                            <NumberBox value={line.unit_price} min={0} format="#,##0.00" readOnly={!canOverride} onValueChanged={(e) => update(line.product_id, { unit_price: e.value ?? 0 })} />
                            <div className="erp-num">{money(quotedTotal(line.product_id) ?? line.quantity * line.unit_price)}</div>
                            <Button icon="trash" stylingMode="text" onClick={() => setCart((lines) => lines.filter((l) => l !== line))} />
                        </div>
                    ))}
                </div>
            </div>
            <div className="pos-sidebar">
                <div className="pos-shiftbar">
                    <div>
                        <div style={{ fontWeight: 600 }}>{shift.terminal?.name}</div>
                        <div className="erp-muted">Shift {shift.shift_no} · {shift.summary.sales_count} sales · {money(shift.summary.sales_total)}</div>
                    </div>
                    <Button text="Close shift" stylingMode="outlined" onClick={() => setClosing(true)} />
                </div>
                <div style={{ padding: 16 }}>
                    <SelectBox
                        label="Customer (optional)" dataSource={customers.data ?? []} valueExpr="id" displayExpr="name" value={customerId}
                        searchEnabled showClearButton stylingMode="outlined" onValueChanged={(e) => setCustomerId(e.value ?? null)}
                    />
                </div>
                <div className="pos-totals">
                    <div className="pos-total-row"><span>Subtotal</span><span>{money(quote?.subtotal)}</span></div>
                    <div className="pos-total-row"><span>Discount</span><span>{money(quote?.discount_total)}</span></div>
                    <div className="pos-total-row"><span>Tax</span><span>{money(quote?.tax_total)}</span></div>
                    <div className="pos-total-row grand"><span>Total</span><span>{money(grandTotal)}</span></div>
                    {quoteError && <div className="erp-error">{quoteError}</div>}
                </div>
                <div style={{ flex: 1 }} />
                <div className="pos-action-bar">
                    <Button text="Clear" stylingMode="outlined" disabled={cart.length === 0} onClick={() => setCart([])} />
                    <div style={{ flex: 1 }} />
                    <Button text="Pay" type="default" width={160} height={48} disabled={cart.length === 0 || !quote || !!quoteError} onClick={() => setPaying(true)} />
                </div>
            </div>

            {paying && (
                <Payment
                    cart={cart} total={grandTotal} customerId={customerId}
                    onClose={() => setPaying(false)}
                    onDone={(sale) => {
                        setPaying(false);
                        setCart([]);
                        setCustomerId(null);
                        setReceipt(sale);
                        onShiftChanged();
                    }}
                />
            )}
            {receipt && <Receipt sale={receipt} onClose={() => setReceipt(null)} />}
            {closing && <CloseShift shift={shift} onClose={() => setClosing(false)} onClosed={() => { setClosing(false); onShiftChanged(); }} />}
        </div>
    );
}

function ProductSearch({ onPick }: { onPick: (product: any) => void }) {
    const products = useQuery({ queryKey: ['products', 'active'], queryFn: () => getList('/products', { status: 'active', limit: 1000 }) });

    return (
        <SelectBox
            width={300} dataSource={products.data ?? []} valueExpr="id" value={null} searchEnabled searchExpr={['name', 'sku']}
            placeholder="Search products…" displayExpr={(p: any) => (p ? `${p.name} (${p.sku})` : '')} stylingMode="outlined"
            onValueChanged={(e) => {
                const product = (products.data ?? []).find((p: any) => p.id === e.value);
                if (product) {
                    onPick(product);
                    e.component.reset();
                }
            }}
        />
    );
}

function Payment({ cart, total, customerId, onClose, onDone }: { cart: CartLine[]; total: number; customerId: string | null; onClose: () => void; onDone: (sale: any) => void }) {
    const [tenders, setTenders] = useState<Tender[]>([]);
    const [method, setMethod] = useState('cash');
    const [amount, setAmount] = useState(total);
    const [reference, setReference] = useState('');
    const [busy, setBusy] = useState(false);
    // One key for this payment attempt: retrying after a timeout cannot create a second sale.
    const key = useRef(newIdempotencyKey());

    const paid = tenders.reduce((sum, t) => sum + t.amount, 0);
    const remaining = Math.max(0, Math.round((total - paid) * 100) / 100);
    const change = Math.max(0, Math.round((paid - total) * 100) / 100);

    const addTender = () => {
        if (amount <= 0) {
            return;
        }
        const next = [...tenders, { method, amount, reference: reference || undefined }];
        setTenders(next);
        setReference('');
        setAmount(Math.max(0, Math.round((total - next.reduce((s, t) => s + t.amount, 0)) * 100) / 100));
    };

    const complete = async () => {
        setBusy(true);
        try {
            const { data } = await api.post('/sales', {
                customer_id: customerId,
                lines: cart.map(({ product_id, quantity, unit_price }) => ({ product_id, quantity, unit_price })),
                tenders,
            }, idempotent(key.current));
            onDone(data.data);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title={`Payment — total ${money(total)}`} width={560} height="auto" onHiding={onClose} showCloseButton>
            <div className="erp-row">
                <SelectBox label="Method" width={160} dataSource={METHODS} valueExpr="id" displayExpr="name" value={method} onValueChanged={(e) => setMethod(e.value)} />
                <NumberBox label="Amount" width={140} value={amount} min={0} format="#,##0.00" onValueChanged={(e) => setAmount(e.value ?? 0)} />
                {method !== 'cash' && method !== 'credit' && (
                    <TextBox label="Reference" width={130} value={reference} onValueChanged={(e) => setReference(e.value)} />
                )}
                <Button text="Add" stylingMode="outlined" onClick={addTender} />
            </div>
            {method === 'credit' && !customerId && <div className="erp-error">Select a customer on the sale to sell on account.</div>}
            {tenders.map((t, i) => (
                <div className="pos-total-row" key={i}>
                    <span>{METHODS.find((m) => m.id === t.method)?.name}{t.reference ? ` (${t.reference})` : ''}</span>
                    <span>
                        {money(t.amount)}
                        <Button icon="close" stylingMode="text" onClick={() => setTenders(tenders.filter((_, j) => j !== i))} />
                    </span>
                </div>
            ))}
            <div className="pos-total-row grand" style={{ marginTop: 12 }}>
                <span>{remaining > 0 ? 'Remaining' : 'Change'}</span>
                <span>{money(remaining > 0 ? remaining : change)}</span>
            </div>
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Posting…' : 'Complete sale'} type="success" disabled={busy || tenders.length === 0 || remaining > 0} onClick={complete} />
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
                </div>
                {sale.items.map((item: any) => (
                    <div className="pos-total-row" key={item.id}>
                        <span>{qty(item.quantity)} × {item.product_name}</span>
                        <span>{money(item.line_total)}</span>
                    </div>
                ))}
                <div className="pos-total-row" style={{ marginTop: 8 }}><span>Tax included above</span><span>{money(sale.tax_total)}</span></div>
                <div className="pos-total-row grand"><span>Total</span><span>{money(sale.grand_total)}</span></div>
                <div className="pos-total-row"><span>Paid</span><span>{money(sale.paid_total)}</span></div>
                <div className="pos-total-row"><span>Change</span><span>{money(sale.change_total)}</span></div>
            </div>
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
                <Button text="Print" icon="print" onClick={() => window.print()} />
                <Button text="New sale" type="default" onClick={onClose} />
            </div>
        </Popup>
    );
}

function CloseShift({ shift, onClose, onClosed }: { shift: any; onClose: () => void; onClosed: () => void }) {
    const [counted, setCounted] = useState(0);
    const [notes, setNotes] = useState('');
    const [busy, setBusy] = useState(false);
    const s = shift.summary;

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
            <div className="pos-total-row"><span>Paid in / out</span><span>{money(s.paid_in)} / {money(s.paid_out)}</span></div>
            <div className="pos-total-row grand"><span>Expected in drawer</span><span>{money(s.expected_cash)}</span></div>
            <div className="erp-row" style={{ marginTop: 16 }}>
                <NumberBox label="Counted cash" width={180} value={counted} min={0} format="#,##0.00" onValueChanged={(e) => setCounted(e.value ?? 0)} />
                <TextBox label="Notes" width={220} value={notes} onValueChanged={(e) => setNotes(e.value)} />
            </div>
            <div className="erp-muted">Any difference is posted to the Cash over/short account.</div>
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
                <Button text="Cancel" onClick={onClose} />
                <Button text={busy ? 'Closing…' : 'Close shift'} type="default" disabled={busy} onClick={close} />
            </div>
        </Popup>
    );
}
