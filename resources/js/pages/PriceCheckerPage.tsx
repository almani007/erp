import { useRef, useState } from 'react';
import TextBox from 'devextreme-react/text-box';
import Page from '../components/Page';
import { api, errorMessage, getOne } from '../lib/api';
import { money } from '../lib/format';

/** Scan-and-see price lookup for a customer-facing kiosk or the shop floor. */
export default function PriceCheckerPage() {
    const [code, setCode] = useState('');
    const [result, setResult] = useState<any>(null);
    const [error, setError] = useState<string | null>(null);
    const seq = useRef(0);

    const check = async () => {
        const value = code.trim();
        if (!value) {
            return;
        }
        setCode('');
        const current = ++seq.current;
        try {
            const product = await getOne('/products/lookup', { code: value });
            // Priced by the same engine as the till: branch price, running deals and tax.
            const { data } = await api.post('/pos/quote', { lines: [{ product_id: product.id, quantity: 1 }] });
            if (current === seq.current) {
                setResult({ product, line: data.data.lines[0] });
                setError(null);
            }
        } catch (e) {
            if (current === seq.current) {
                setResult(null);
                setError(errorMessage(e));
            }
        }
    };

    return (
        <Page title="Price checker" subtitle="Prices are for the selected branch and include running deals and tax">
            <div className="checker">
                <TextBox
                    placeholder="Scan a barcode or type a SKU, then press Enter" value={code} valueChangeEvent="input" stylingMode="outlined" height={56}
                    onValueChanged={(e) => setCode(e.value)} onEnterKey={check} inputAttr={{ autoFocus: true, 'aria-label': 'Barcode or SKU' }}
                />
                <div className="erp-card" style={{ marginTop: 24, padding: 40, minHeight: 260 }} aria-live="polite">
                    {!result && !error && <div className="erp-muted">Waiting for a scan…</div>}
                    {error && <div className="erp-error" role="alert">{error}</div>}
                    {result && (
                        <>
                            <div className="checker-price">{money(result.line.total)}</div>
                            <div className="checker-name">{result.product.name}</div>
                            <div className="erp-muted" style={{ marginTop: 6 }}>{result.product.sku}</div>
                            {result.line.promotion_name && (
                                <div className="pos-promo" style={{ marginTop: 14, fontSize: '1rem' }}>
                                    {result.line.promotion_name} — was {money(Number(result.line.total) + Number(result.line.promotion_discount))}
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>
        </Page>
    );
}
