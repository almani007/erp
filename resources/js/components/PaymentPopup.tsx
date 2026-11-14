import { useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TextBox from 'devextreme-react/text-box';
import { api, getOne, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { money } from '../lib/format';

interface PaymentPopupProps {
    /** "customers" (money received) or "suppliers" (money paid out). */
    party: 'customers' | 'suppliers';
    partyId: string;
    companyId: string;
    canPay: boolean;
    onClose: (changed: boolean) => void;
}

/** Balance, payment entry and payment history for a customer or a supplier. */
export default function PaymentPopup({ party, partyId, companyId, canPay, onClose }: PaymentPopupProps) {
    const isCustomer = party === 'customers';
    const query = useQuery({ queryKey: [party, partyId, 'payments'], queryFn: () => getOne(`/${party}/${partyId}/payments`) });
    const [method, setMethod] = useState('cash');
    const [amount, setAmount] = useState(0);
    const [reference, setReference] = useState('');
    const [busy, setBusy] = useState(false);
    const [changed, setChanged] = useState(false);
    const key = useRef(newIdempotencyKey());

    const who = isCustomer ? query.data?.customer : query.data?.supplier;
    const balance = Number(who?.balance ?? 0);

    const save = async () => {
        setBusy(true);
        try {
            await api.post(`/${party}/${partyId}/payments`, { company_id: companyId, method, amount, reference: reference || null }, idempotent(key.current));
            key.current = newIdempotencyKey();
            notifySuccess(isCustomer ? 'Payment received' : 'Payment recorded');
            setAmount(0);
            setReference('');
            setChanged(true);
            query.refetch();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title={who?.name ?? ''} width={560} height="auto" onHiding={() => onClose(changed)} showCloseButton>
            <div className="pos-total-row grand" style={{ borderTop: 0, marginTop: 0, paddingTop: 0 }}>
                <span>{isCustomer ? 'Owes us' : 'We owe'}</span>
                <span>{money(balance)}</span>
            </div>
            {canPay && balance > 0 && (
                <div className="erp-row" style={{ marginTop: 16 }}>
                    <SelectBox label="Method" width={130} dataSource={[{ id: 'cash', name: 'Cash' }, { id: 'bank', name: 'Bank' }]} valueExpr="id" displayExpr="name" value={method} onValueChanged={(e) => setMethod(e.value)} />
                    <NumberBox label="Amount" width={140} value={amount} min={0} max={balance} format="#,##0.00" onValueChanged={(e) => setAmount(e.value ?? 0)} />
                    <TextBox label="Reference" width={130} value={reference} onValueChanged={(e) => setReference(e.value)} />
                    <Button text={busy ? 'Saving…' : isCustomer ? 'Receive' : 'Pay'} type="default" disabled={busy || amount <= 0 || amount > balance} onClick={save} />
                </div>
            )}
            <div className="erp-card-title" style={{ marginTop: 16 }}>Payment history</div>
            {(query.data?.payments ?? []).length === 0 && <div className="erp-muted">No payments yet.</div>}
            {(query.data?.payments ?? []).map((p: any) => (
                <div className="erp-list-row" key={p.id}>
                    <div>
                        <div style={{ fontWeight: 600 }}>{p.receipt_no ?? p.payment_no}</div>
                        <div className="erp-muted">{new Date(p.received_at ?? p.paid_at).toLocaleString()} · {p.method}{p.reference ? ` · ${p.reference}` : ''}</div>
                    </div>
                    <div className="erp-num" style={{ fontWeight: 600 }}>{money(p.amount)}</div>
                </div>
            ))}
        </Popup>
    );
}
