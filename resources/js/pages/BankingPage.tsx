import { useEffect, useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import DataGrid, { Column, FilterRow, Lookup, SearchPanel, Selection, Summary, TotalItem } from 'devextreme-react/data-grid';
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
import { can } from '../lib/store';

interface BankAccount { id: string; kind: 'bank' | 'cash'; name: string; bank_name: string | null; account_no: string | null; iban: string | null; balance: string; reconciled_to: string | null; status: string; is_default: boolean }
interface Cheque { id: string; direction: 'received' | 'issued'; party_id: string; party_name: string | null; cheque_no: string; drawer_bank: string | null; cheque_date: string; amount: string; status: string; bank_account_id: string | null; bounce_reason: string | null }
interface BookLine { id: number; posting_date: string; journal_no: string; source_type: string; entry: string | null; description: string | null; debit: string; credit: string; ticked: boolean }

const useAccounts = () => useQuery({ queryKey: ['bank-accounts'], queryFn: () => getList<BankAccount>('/bank-accounts') });

/** Bank and cash accounts, post-dated cheques, transfers and bank reconciliation. */
export default function BankingPage() {
    return (
        <Page title="Banking" subtitle="Accounts, cheques in hand and issued, transfers, and reconciliation with the bank statement.">
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="Accounts"><Accounts /></Item>
                {can('banking.cheque.manage') && <Item title="Cheques received"><Cheques direction="received" /></Item>}
                {can('banking.cheque.manage') && <Item title="Cheques issued"><Cheques direction="issued" /></Item>}
                {can('banking.view') && <Item title="Transfers"><Transfers /></Item>}
                {can('banking.reconcile') && <Item title="Reconciliation"><Reconciliation /></Item>}
            </TabPanel>
        </Page>
    );
}

function Accounts() {
    const client = useQueryClient();
    const accounts = useAccounts();
    const [adding, setAdding] = useState(false);
    const [f, setF] = useState<Record<string, string>>({ kind: 'bank' });

    const save = async () => {
        try {
            await api.post('/bank-accounts', f);
            notifySuccess('Account added with its own ledger account.');
            setAdding(false);
            client.invalidateQueries({ queryKey: ['bank-accounts'] });
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                {can('banking.manage') && <Button text="Add bank account / cash box" icon="add" type="default" onClick={() => setAdding(true)} />}
            </div>
            <DataGrid dataSource={accounts.data ?? []} keyExpr="id" showRowLines columnAutoWidth>
                <Column dataField="name" />
                <Column dataField="kind" width={80} />
                <Column dataField="bank_name" caption="Bank" />
                <Column dataField="account_no" caption="Account no." />
                <Column dataField="iban" caption="IBAN" />
                <Column {...numberColumn('balance')} caption="Ledger balance" cssClass="erp-strong" />
                <Column dataField="reconciled_to" caption="Reconciled to" dataType="date" width={120} />
                <Column dataField="status" width={100} cellRender={statusCell} />
                <Summary><TotalItem column="balance" summaryType="sum" valueFormat="#,##0.00" displayFormat="{0}" /></Summary>
            </DataGrid>
            {adding && (
                <Popup visible title="Add account" width={620} height="auto" onHiding={() => setAdding(false)} showCloseButton>
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                        <Field label="Type"><SelectBox dataSource={[{ id: 'bank', name: 'Bank account' }, { id: 'cash', name: 'Cash box / petty cash' }]} valueExpr="id" displayExpr="name" value={f.kind} onValueChanged={(e) => setF({ ...f, kind: e.value })} /></Field>
                        <Field label="Name"><TextBox value={f.name ?? ''} placeholder="Meezan current account" onValueChanged={(e) => setF({ ...f, name: e.value })} /></Field>
                        {f.kind === 'bank' && <>
                            <Field label="Bank"><TextBox value={f.bank_name ?? ''} onValueChanged={(e) => setF({ ...f, bank_name: e.value })} /></Field>
                            <Field label="Branch"><TextBox value={f.branch_name ?? ''} onValueChanged={(e) => setF({ ...f, branch_name: e.value })} /></Field>
                            <Field label="Account no."><TextBox value={f.account_no ?? ''} onValueChanged={(e) => setF({ ...f, account_no: e.value })} /></Field>
                            <Field label="IBAN"><TextBox value={f.iban ?? ''} onValueChanged={(e) => setF({ ...f, iban: e.value })} /></Field>
                        </>}
                    </div>
                    <div className="erp-actions"><Button text="Cancel" onClick={() => setAdding(false)} /><Button text="Save" type="default" disabled={!f.name} onClick={save} /></div>
                </Popup>
            )}
        </div>
    );
}

function Cheques({ direction }: { direction: 'received' | 'issued' }) {
    const client = useQueryClient();
    const rows = useQuery({ queryKey: ['cheques', direction], queryFn: () => getList<Cheque>('/cheques', { direction }) });
    const accounts = (useAccounts().data ?? []).filter((a) => a.kind === 'bank' && a.status === 'active');
    const parties = useQuery({
        queryKey: [direction === 'received' ? 'customers' : 'suppliers'],
        queryFn: () => getList<{ id: string; name: string }>(direction === 'received' ? '/customers' : '/suppliers'),
    }).data ?? [];
    const [adding, setAdding] = useState(false);
    const [f, setF] = useState<Record<string, any>>({ cheque_date: today() });
    const [key, setKey] = useState(newIdempotencyKey);
    const refresh = () => { client.invalidateQueries({ queryKey: ['cheques'] }); client.invalidateQueries({ queryKey: ['bank-accounts'] }); };
    const in7 = toDateString(new Date(Date.now() + 7 * 864e5));

    const act = async (c: Cheque, action: string, body: Record<string, unknown> = {}) => {
        try {
            await api.post(`/cheques/${c.id}/${action}`, body, idempotent(newIdempotencyKey()));
            notifySuccess(`Cheque ${c.cheque_no} ${action === 'clear' ? 'cleared and posted' : action === 'deposit' ? 'deposited' : action === 'bounce' ? 'marked bounced' : 'cancelled'}.`);
            refresh();
        } catch (e) {
            notifyError(e);
        }
    };
    const ask = (c: Cheque, action: 'bounce' | 'cancel') => {
        const reason = window.prompt(action === 'bounce' ? 'Why did it bounce (insufficient funds, signature …)?' : 'Reason for cancelling?');
        if (reason) act(c, action, { reason });
    };
    const deposit = (c: Cheque) => {
        const target = accounts.find((a) => a.is_default) ?? accounts[0];
        if (!target) return notifyError(new Error('Add a bank account first.'));
        act(c, 'deposit', { bank_account_id: target.id, date: today() });
    };

    const save = async () => {
        try {
            await api.post('/cheques', { ...f, direction }, idempotent(key));
            notifySuccess('Cheque recorded.');
            setAdding(false);
            setF({ cheque_date: today() });
            setKey(newIdempotencyKey());
            refresh();
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                <Button text={direction === 'received' ? 'Record cheque received' : 'Record cheque issued'} icon="add" type="default" onClick={() => setAdding(true)} />
                <span className="erp-muted">Nothing is posted until the bank clears the cheque. Highlighted cheques fall due within 7 days.</span>
            </div>
            <DataGrid dataSource={rows.data ?? []} keyExpr="id" showRowLines columnAutoWidth height="calc(100vh - 300px)"
                onRowPrepared={(e) => {
                    if (e.rowType === 'data' && ['in_hand', 'deposited', 'issued'].includes(e.data.status) && e.data.cheque_date <= in7) {
                        e.rowElement.style.background = 'var(--erp-warning-bg, #fef3c7)';
                    }
                }}>
                <SearchPanel visible width={240} />
                <FilterRow visible />
                <Column dataField="cheque_date" caption="Cheque date" dataType="date" width={110} />
                <Column dataField="cheque_no" caption="Cheque no." width={110} />
                <Column dataField="party_name" caption={direction === 'received' ? 'Customer' : 'Supplier'} />
                <Column dataField="drawer_bank" caption={direction === 'received' ? 'Their bank' : 'Note'} />
                <Column dataField="bank_account_id" caption={direction === 'received' ? 'Deposited in' : 'Drawn on'}><Lookup dataSource={accounts} valueExpr="id" displayExpr="name" /></Column>
                <Column {...numberColumn('amount')} />
                <Column dataField="status" width={110} cellRender={statusCell} />
                <Column caption="" width={260} cellRender={({ data }: { data: Cheque }) => (
                    <>
                        {data.status === 'in_hand' && <Button text="Deposit" stylingMode="text" onClick={() => deposit(data)} />}
                        {(data.status === 'deposited' || data.status === 'issued') && <Button text="Cleared" stylingMode="text" type="success" onClick={() => act(data, 'clear')} />}
                        {['in_hand', 'deposited', 'issued'].includes(data.status) && <Button text="Bounced" stylingMode="text" type="danger" onClick={() => ask(data, 'bounce')} />}
                        {['in_hand', 'issued'].includes(data.status) && <Button text="Cancel" stylingMode="text" onClick={() => ask(data, 'cancel')} />}
                    </>
                )} />
                <Summary><TotalItem column="amount" summaryType="sum" valueFormat="#,##0.00" displayFormat="{0}" /></Summary>
            </DataGrid>
            {adding && (
                <Popup visible title={direction === 'received' ? 'Cheque received' : 'Cheque issued'} width={640} height="auto" onHiding={() => setAdding(false)} showCloseButton>
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                        <Field label={direction === 'received' ? 'From customer' : 'To supplier'}>
                            <SelectBox dataSource={parties} valueExpr="id" displayExpr="name" searchEnabled value={f.party_id ?? null} onValueChanged={(e) => setF({ ...f, party_id: e.value })} />
                        </Field>
                        <Field label="Cheque no."><TextBox value={f.cheque_no ?? ''} onValueChanged={(e) => setF({ ...f, cheque_no: e.value })} /></Field>
                        <Field label="Cheque date (post-dated allowed)"><DateBox type="date" value={f.cheque_date} onValueChanged={(e) => e.value && setF({ ...f, cheque_date: toDateString(new Date(e.value)) })} /></Field>
                        <Field label="Amount"><NumberBox min={0} format="#,##0.00" value={f.amount ?? 0} onValueChanged={(e) => setF({ ...f, amount: e.value })} /></Field>
                        {direction === 'received'
                            ? <Field label="Their bank"><TextBox value={f.drawer_bank ?? ''} onValueChanged={(e) => setF({ ...f, drawer_bank: e.value })} /></Field>
                            : <Field label="Drawn on"><SelectBox dataSource={accounts} valueExpr="id" displayExpr="name" value={f.bank_account_id ?? null} onValueChanged={(e) => setF({ ...f, bank_account_id: e.value })} /></Field>}
                        <Field label="Notes"><TextBox value={f.notes ?? ''} onValueChanged={(e) => setF({ ...f, notes: e.value })} /></Field>
                    </div>
                    <div className="erp-actions"><Button text="Cancel" onClick={() => setAdding(false)} />
                        <Button text="Save" type="default" disabled={!f.party_id || !f.cheque_no || !(f.amount > 0) || (direction === 'issued' && !f.bank_account_id)} onClick={save} /></div>
                </Popup>
            )}
        </div>
    );
}

function Transfers() {
    const client = useQueryClient();
    const accounts = (useAccounts().data ?? []).filter((a) => a.status === 'active');
    const rows = useQuery({ queryKey: ['bank-transfers'], queryFn: () => getList('/bank-transfers') });
    const [f, setF] = useState<Record<string, any>>({ transfer_date: today(), amount: 0 });
    const [key, setKey] = useState(newIdempotencyKey);

    const save = async () => {
        try {
            await api.post('/bank-transfers', f, idempotent(key));
            notifySuccess('Transfer posted.');
            setKey(newIdempotencyKey());
            setF({ transfer_date: today(), amount: 0 });
            client.invalidateQueries({ queryKey: ['bank-transfers'] });
            client.invalidateQueries({ queryKey: ['bank-accounts'] });
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <div className="erp-card">
            {can('banking.manage') && (
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 140px 140px 1fr auto', gap: 12, alignItems: 'end', marginBottom: 12 }}>
                    <Field label="From"><SelectBox dataSource={accounts} valueExpr="id" displayExpr="name" value={f.from_account_id ?? null} onValueChanged={(e) => setF({ ...f, from_account_id: e.value })} /></Field>
                    <Field label="To"><SelectBox dataSource={accounts} valueExpr="id" displayExpr="name" value={f.to_account_id ?? null} onValueChanged={(e) => setF({ ...f, to_account_id: e.value })} /></Field>
                    <Field label="Date"><DateBox type="date" value={f.transfer_date} max={new Date()} onValueChanged={(e) => e.value && setF({ ...f, transfer_date: toDateString(new Date(e.value)) })} /></Field>
                    <Field label="Amount"><NumberBox min={0} format="#,##0.00" value={f.amount} onValueChanged={(e) => setF({ ...f, amount: e.value })} /></Field>
                    <Field label="Reference (deposit slip …)"><TextBox value={f.reference ?? ''} onValueChanged={(e) => setF({ ...f, reference: e.value })} /></Field>
                    <Button text="Post transfer" type="default" disabled={!f.from_account_id || !f.to_account_id || !(f.amount > 0)} onClick={save} />
                </div>
            )}
            <DataGrid dataSource={rows.data ?? []} keyExpr="id" showRowLines columnAutoWidth>
                <Column dataField="transfer_no" width={120} />
                <Column dataField="transfer_date" dataType="date" width={110} />
                <Column dataField="from_account_id" caption="From"><Lookup dataSource={accounts} valueExpr="id" displayExpr="name" /></Column>
                <Column dataField="to_account_id" caption="To"><Lookup dataSource={accounts} valueExpr="id" displayExpr="name" /></Column>
                <Column {...numberColumn('amount')} />
                <Column dataField="reference" />
            </DataGrid>
        </div>
    );
}

/** Parses a bank statement CSV: date, description, amount (or debit / credit columns). */
function parseStatement(text: string): { date: string; description: string; amount: number }[] {
    const rows = text.split(/\r?\n/).map((l) => l.split(/,(?=(?:[^"]*"[^"]*")*[^"]*$)/).map((c) => c.replace(/^"|"$/g, '').trim())).filter((r) => r.length >= 3);
    const out: { date: string; description: string; amount: number }[] = [];
    for (const r of rows) {
        const d = new Date(r[0]);
        if (Number.isNaN(d.getTime())) continue; // header
        const num = (v?: string) => Number((v ?? '').replace(/[^\d.-]/g, '')) || 0;
        const amount = r.length >= 4 ? num(r[3]) - num(r[2]) : num(r[2]); // date, description, withdrawal, deposit — or a signed amount
        out.push({ date: toDateString(d), description: r[1], amount });
    }
    return out;
}

function Reconciliation() {
    const accounts = (useAccounts().data ?? []).filter((a) => a.kind === 'bank');
    const [bankId, setBankId] = useState<string | null>(null);
    const [date, setDate] = useState(today());
    const [balance, setBalance] = useState<number>(0);
    const [work, setWork] = useState<any>(null);
    const [selected, setSelected] = useState<number[]>([]);
    const [figures, setFigures] = useState<any>(null);
    const [unmatched, setUnmatched] = useState<{ date: string; description: string; amount: number }[]>([]);

    useEffect(() => {
        if (!bankId) return;
        getList<any>(`/bank-accounts/${bankId}/reconciliations`).then((list) => {
            const draft = list.find((r) => r.status === 'draft');
            if (draft) api.get(`/bank-reconciliations/${draft.id}`).then((r) => load(r.data.data));
            else setWork(null);
        }).catch(notifyError);
    }, [bankId]);

    const load = (w: any) => {
        setWork(w);
        setFigures(w);
        setSelected(w.lines.filter((l: BookLine) => l.ticked).map((l: BookLine) => l.id));
        setDate(w.reconciliation.statement_date);
        setBalance(Number(w.reconciliation.statement_balance));
    };

    const start = async () => {
        try { load((await api.post(`/bank-accounts/${bankId}/reconciliations`, { statement_date: date, statement_balance: balance })).data.data); } catch (e) { notifyError(e); }
    };
    const saveTicks = async (ids: number[]) => {
        setSelected(ids);
        try { setFigures((await api.put(`/bank-reconciliations/${work.reconciliation.id}/lines`, { line_ids: ids })).data.data); } catch (e) { notifyError(e); }
    };
    const complete = async () => {
        try {
            await api.post(`/bank-reconciliations/${work.reconciliation.id}/complete`);
            notifySuccess('Reconciliation completed.');
            setWork(null);
        } catch (e) {
            notifyError(e);
        }
    };

    // Statement import: tick book lines with the same amount within 5 days of the bank's date.
    const importCsv = async (file: File) => {
        const statement = parseStatement(await file.text());
        const free = (work.lines as BookLine[]).filter((l) => !selected.includes(l.id));
        const picked: number[] = [];
        const left: typeof statement = [];
        for (const s of statement) {
            const hit = free.find((l) => !picked.includes(l.id) && Math.abs(Number(l.debit) - Number(l.credit) - s.amount) < 0.005
                && Math.abs(new Date(l.posting_date).getTime() - new Date(s.date).getTime()) <= 5 * 864e5);
            if (hit) picked.push(hit.id); else left.push(s);
        }
        setUnmatched(left);
        await saveTicks([...selected, ...picked]);
        notifySuccess(`Matched ${picked.length} of ${statement.length} statement lines.`);
    };

    const lines: BookLine[] = work?.lines ?? [];
    const diff = Number(figures?.difference ?? 0);
    const uncleared = useMemo(() => lines.filter((l) => !selected.includes(l.id)), [lines, selected]);

    return (
        <div className="erp-card">
            <div style={{ display: 'grid', gridTemplateColumns: '1.4fr 1fr 1fr auto', gap: 12, alignItems: 'end' }}>
                <Field label="Bank account"><SelectBox dataSource={accounts} valueExpr="id" displayExpr={(a: BankAccount) => (a ? `${a.name}${a.reconciled_to ? ` (reconciled to ${a.reconciled_to})` : ''}` : '')} value={bankId} onValueChanged={(e) => setBankId(e.value)} /></Field>
                <Field label="Statement date"><DateBox type="date" value={date} max={new Date()} onValueChanged={(e) => e.value && setDate(toDateString(new Date(e.value)))} /></Field>
                <Field label="Statement closing balance"><NumberBox format="#,##0.00" value={balance} onValueChanged={(e) => setBalance(e.value ?? 0)} /></Field>
                <Button text={work ? 'Update' : 'Start'} type="default" disabled={!bankId} onClick={start} />
            </div>
            {work && (
                <>
                    <div className="erp-actions" style={{ alignItems: 'center' }}>
                        <span>Reconciled before <b>{money(figures?.previously_reconciled)}</b> · ticked <b>{money(figures?.ticked)}</b> · book balance <b>{money(figures?.book_balance)}</b> ·
                            difference <b style={{ color: Math.abs(diff) < 0.005 ? 'var(--erp-success, #15803d)' : 'var(--erp-danger, #b91c1c)' }}>{money(diff)}</b></span>
                        <div style={{ flex: 1 }} />
                        <label className="dx-button dx-button-normal" style={{ padding: '6px 12px', cursor: 'pointer' }}>
                            Import statement CSV<input type="file" accept=".csv,text/csv" hidden onChange={(e) => e.target.files?.[0] && importCsv(e.target.files[0])} />
                        </label>
                        <Button text="Complete" type="success" disabled={Math.abs(diff) >= 0.005} onClick={complete} />
                    </div>
                    <DataGrid dataSource={lines} keyExpr="id" showRowLines columnAutoWidth height="calc(100vh - 430px)" selectedRowKeys={selected}
                        onSelectionChanged={(e) => saveTicks(e.selectedRowKeys as number[])}>
                        <Selection mode="multiple" showCheckBoxesMode="always" />
                        <Column dataField="posting_date" caption="Date" dataType="date" width={110} />
                        <Column dataField="journal_no" caption="Journal" width={130} />
                        <Column caption="Details" calculateCellValue={(l: BookLine) => l.description || l.entry} />
                        <Column {...numberColumn('debit')} caption="Money in" />
                        <Column {...numberColumn('credit')} caption="Money out" />
                    </DataGrid>
                    <div className="erp-muted" style={{ marginTop: 6 }}>
                        Uncleared: {uncleared.length} items ({money(uncleared.reduce((s, l) => s + Number(l.debit) - Number(l.credit), 0))}).
                        {unmatched.length > 0 && <> Statement lines with no match in the books: {unmatched.slice(0, 8).map((u) => `${u.date} ${u.description} ${money(u.amount)}`).join(' · ')}{unmatched.length > 8 ? ' …' : ''} — post them (bank charges, profit) as vouchers.</>}
                    </div>
                </>
            )}
        </div>
    );
}
