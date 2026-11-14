import { useState } from 'react';
import TextBox from 'devextreme-react/text-box';
import Page from '../components/Page';
import { errorMessage, getList } from '../lib/api';

/** Warranty / service desk: find when and to whom a serial or IMEI was sold. */
export default function SerialLookupPage() {
    const [serial, setSerial] = useState('');
    const [rows, setRows] = useState<any[] | null>(null);
    const [error, setError] = useState<string | null>(null);

    const search = async () => {
        const value = serial.trim();
        if (!value) {
            return;
        }
        try {
            setRows(await getList('/serials', { serial: value }));
            setError(null);
        } catch (e) {
            setRows(null);
            setError(errorMessage(e));
        }
    };

    return (
        <Page title="Serial & warranty lookup" subtitle="Scan or type a serial number or IMEI recorded at the time of sale">
            <div style={{ maxWidth: 720 }}>
                <TextBox
                    placeholder="Serial number or IMEI, then press Enter" value={serial} valueChangeEvent="input" stylingMode="outlined" height={52}
                    onValueChanged={(e) => setSerial(e.value)} onEnterKey={search} inputAttr={{ autoFocus: true, 'aria-label': 'Serial number' }}
                />
                {error && <div className="erp-card erp-error" style={{ marginTop: 16 }} role="alert">{error}</div>}
                {rows && rows.length === 0 && <div className="erp-card" style={{ marginTop: 16 }}>No sale is recorded for that serial number.</div>}
                {(rows ?? []).map((row) => (
                    <div className="erp-card" key={`${row.sale_id}-${row.sku}`} style={{ marginTop: 16 }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'flex-start' }}>
                            <div>
                                <div className="erp-card-title" style={{ marginBottom: 2 }}>{row.product_name}</div>
                                <div className="erp-muted">{row.sku} · serial {row.serial}</div>
                            </div>
                            {row.warranty_until === null
                                ? <span className="erp-badge">No warranty</span>
                                : <span className={`erp-badge ${row.in_warranty ? 'good' : 'bad'}`}>{row.in_warranty ? 'In warranty' : 'Warranty ended'}</span>}
                        </div>
                        <div className="erp-list-row" style={{ marginTop: 12 }}><span>Sold</span><span>{new Date(row.sold_at).toLocaleString()} · invoice {row.sale_no}</span></div>
                        <div className="erp-list-row"><span>Customer</span><span>{row.customer_name ? `${row.customer_name}${row.customer_phone ? ` · ${row.customer_phone}` : ''}` : 'Walk-in'}</span></div>
                        <div className="erp-list-row"><span>Warranty until</span><span>{row.warranty_until ?? '—'}</span></div>
                    </div>
                ))}
            </div>
        </Page>
    );
}
