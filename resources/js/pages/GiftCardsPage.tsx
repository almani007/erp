import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import DataGrid, { Column, FilterRow, SearchPanel, Summary, TotalItem } from 'devextreme-react/data-grid';
import DateBox from 'devextreme-react/date-box';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TextBox from 'devextreme-react/text-box';
import QRCode from 'qrcode';
import Page, { Field } from '../components/Page';
import { statusCell } from '../components/Status';
import { api, getList, getOne, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { money, numberColumn, toDateString } from '../lib/format';
import { esc, printHtml } from '../lib/print';
import { can } from '../lib/store';

/** Gift cards and vouchers: sell, give away, check balances, block lost cards. */
export default function GiftCardsPage() {
    const client = useQueryClient();
    const rows = useQuery({ queryKey: ['gift-cards'], queryFn: () => getList<any>('/gift-cards') });
    const [issuing, setIssuing] = useState(false);
    const [viewing, setViewing] = useState<string | null>(null);
    const refresh = () => client.invalidateQueries({ queryKey: ['gift-cards'] });

    return (
        <Page title="Gift cards & vouchers" subtitle="Unspent value is held as a liability until it is redeemed at the till or expires."
            actions={<Button text="Issue cards" icon="add" type="default" onClick={() => setIssuing(true)} />}>
            <div className="erp-card">
                <DataGrid dataSource={rows.data ?? []} keyExpr="id" showRowLines columnAutoWidth height="calc(100vh - 240px)" onRowDblClick={(e) => setViewing(e.data.id)}>
                    <SearchPanel visible width={260} />
                    <FilterRow visible />
                    <Column dataField="code" width={190} />
                    <Column dataField="kind" width={100} />
                    <Column dataField="customer.name" caption="Customer" />
                    <Column {...numberColumn('initial_value')} caption="Value" />
                    <Column {...numberColumn('balance')} caption="Balance" cssClass="erp-strong" />
                    <Column dataField="paid_by" caption="Funded by" width={100} />
                    <Column dataField="expires_on" dataType="date" width={110} />
                    <Column dataField="created_at" caption="Issued" dataType="date" width={110} />
                    <Column dataField="status" width={100} cellRender={statusCell} />
                    <Column caption="" width={80} cellRender={({ data }) => <Button text="Open" stylingMode="text" onClick={() => setViewing(data.id)} />} />
                    <Summary><TotalItem column="balance" summaryType="sum" valueFormat="#,##0.00" displayFormat="Outstanding {0}" /></Summary>
                </DataGrid>
            </div>
            {issuing && <IssueCards onClose={() => setIssuing(false)} onDone={() => { setIssuing(false); refresh(); }} />}
            {viewing && <CardDetail id={viewing} onClose={() => setViewing(null)} onChanged={refresh} />}
        </Page>
    );
}

function IssueCards({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
    const customers = useQuery({ queryKey: ['customers'], queryFn: () => getList<any>('/customers') }).data ?? [];
    const [f, setF] = useState<any>({ kind: 'gift_card', paid_by: 'cash', value: 1000, quantity: 1 });
    const [key] = useState(newIdempotencyKey);
    const set = (p: any) => setF((x: any) => ({ ...x, ...p }));
    const paidBy = [{ id: 'cash', n: 'Sold for cash' }, { id: 'bank', n: 'Sold — paid to bank / card' }, ...(can('sales.giftcard.promote') ? [{ id: 'promotion', n: 'Free (promotion / goodwill)' }] : [])];

    const save = async () => {
        try {
            const { data } = await api.post('/gift-cards', f, idempotent(key));
            const cards = Array.isArray(data.data) ? data.data : [data.data];
            notifySuccess(`${cards.length} card(s) issued.`);
            await printCards(cards);
            onDone();
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <Popup visible title="Issue gift cards / vouchers" width={500} height="auto" onHiding={onClose} showCloseButton>
            <div className="erp-row">
                <Field label="Kind"><SelectBox width={150} dataSource={[{ id: 'gift_card', n: 'Gift card' }, { id: 'voucher', n: 'Voucher' }]} valueExpr="id" displayExpr="n" value={f.kind} onValueChanged={(e) => set({ kind: e.value })} /></Field>
                <Field label="Value each"><NumberBox width={130} value={f.value} min={1} format="#,##0.00" onValueChanged={(e) => set({ value: e.value })} /></Field>
                <Field label="How many"><NumberBox width={90} value={f.quantity} min={1} max={500} showSpinButtons onValueChanged={(e) => set({ quantity: e.value })} /></Field>
            </div>
            <Field label="Funded by"><SelectBox dataSource={paidBy} valueExpr="id" displayExpr="n" value={f.paid_by} onValueChanged={(e) => set({ paid_by: e.value })} /></Field>
            <div className="erp-row">
                <Field label="Expires on (optional)"><DateBox type="date" showClearButton min={new Date()} value={f.expires_on ?? null} onValueChanged={(e) => set({ expires_on: e.value ? toDateString(new Date(e.value)) : null })} /></Field>
                {f.quantity === 1 && <Field label="Card number (blank = generate)"><TextBox value={f.code ?? ''} onValueChanged={(e) => set({ code: e.value || null })} /></Field>}
            </div>
            <Field label="Customer (optional)"><SelectBox dataSource={customers} valueExpr="id" displayExpr="name" searchEnabled showClearButton value={f.customer_id ?? null} onValueChanged={(e) => set({ customer_id: e.value })} /></Field>
            <Field label="Note"><TextBox value={f.note ?? ''} onValueChanged={(e) => set({ note: e.value })} /></Field>
            <div className="erp-muted">Total {money((f.value ?? 0) * (f.quantity ?? 1))}. {f.paid_by === 'cash' ? 'Cash goes into your open till shift, if any.' : f.paid_by === 'promotion' ? 'Booked as promotional expense.' : ''}</div>
            <div className="erp-actions"><Button text="Cancel" onClick={onClose} /><Button text="Issue and print" type="default" disabled={!f.value} onClick={save} /></div>
        </Popup>
    );
}

function CardDetail({ id, onClose, onChanged }: { id: string; onClose: () => void; onChanged: () => void }) {
    const card = useQuery({ queryKey: ['gift-card', id], queryFn: () => getOne<any>(`/gift-cards/${id}`) });
    const c = card.data;

    const toggleBlock = async () => {
        const reason = window.prompt(c.status === 'blocked' ? 'Why unblock it?' : 'Why block it? (lost, stolen …)');
        if (!reason) return;
        try {
            await api.post(`/gift-cards/${id}/block`, { blocked: c.status !== 'blocked', reason });
            card.refetch();
            onChanged();
        } catch (e) { notifyError(e); }
    };

    return (
        <Popup visible title={c ? `${c.code} — ${money(c.balance)} left` : 'Gift card'} width={640} height="auto" onHiding={onClose} showCloseButton>
            {c && (
                <>
                    <div className="erp-muted">{c.kind === 'voucher' ? 'Voucher' : 'Gift card'} of {money(c.initial_value)}{c.customer ? ` for ${c.customer.name}` : ''}{c.expires_on ? `, expires ${c.expires_on}` : ''}. {c.note}</div>
                    <DataGrid dataSource={c.history} keyExpr="id" showRowLines columnAutoWidth>
                        <Column dataField="created_at" caption="When" dataType="datetime" width={160} />
                        <Column dataField="kind" width={90} />
                        <Column dataField="source_type" caption="For" />
                        <Column {...numberColumn('amount')} />
                        <Column {...numberColumn('balance_after')} caption="Balance" />
                    </DataGrid>
                    <div className="erp-actions">
                        <Button text="Reprint" icon="print" onClick={() => printCards([c])} />
                        {['active', 'blocked'].includes(c.status) && <Button text={c.status === 'blocked' ? 'Unblock' : 'Block card'} type={c.status === 'blocked' ? 'default' : 'danger'} onClick={toggleBlock} />}
                        <Button text="Close" onClick={onClose} />
                    </div>
                </>
            )}
        </Popup>
    );
}

/** One A4 sheet of printable cards, each with its number as a QR code for scanning at the till. */
async function printCards(cards: any[]) {
    const items = await Promise.all(cards.map(async (c) => {
        const qr = await QRCode.toDataURL(c.code, { margin: 1, width: 120 });
        return `<div class="gc"><div class="gc-title">${c.kind === 'voucher' ? 'VOUCHER' : 'GIFT CARD'}</div>
            <div class="gc-value">${money(c.initial_value)}</div><img src="${qr}" alt="" /><div class="gc-code">${esc(c.code)}</div>
            ${c.expires_on ? `<div class="gc-exp">Valid until ${esc(c.expires_on)}</div>` : ''}</div>`;
    }));
    printHtml('Gift cards', `<style>.gcs{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.gc{border:1px dashed #888;border-radius:10px;padding:12px;text-align:center}
        .gc-title{font-weight:700;letter-spacing:2px}.gc-value{font-size:22px;font-weight:700;margin:4px 0}.gc-code{font-family:monospace;font-size:14px}.gc-exp{font-size:11px;color:#555}</style>
        <div class="gcs">${items.join('')}</div>`);
}
