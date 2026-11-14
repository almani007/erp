import { useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import DateBox from 'devextreme-react/date-box';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TextBox from 'devextreme-react/text-box';
import DataGrid, { Column, Lookup, RequiredRule, Summary, TotalItem } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import LinesGrid, { cleanLines } from '../components/LinesGrid';
import { StatusBadge, statusCell } from '../components/Status';
import Page, { Field } from '../components/Page';
import { api, getList, getOne, idempotent, newIdempotencyKey, notifyError, notifySuccess } from '../lib/api';
import { numberColumn, startOfMonth, today } from '../lib/format';
import { useCan, useCompanyId } from '../lib/store';

const sumFormat = { type: 'fixedPoint' as const, precision: 2 };
/** Entries keyed in directly; anything else is corrected through its source document. */
const REVERSIBLE = ['manual', 'expense_voucher', 'income_voucher'];

export default function JournalsPage() {
    const companyId = useCompanyId();
    const canCreate = useCan('finance.journal.create');
    const canVoucher = useCan('finance.voucher.create');
    const [voucher, setVoucher] = useState<'expense' | 'income' | null>(null);
    const [from, setFrom] = useState(startOfMonth());
    const [to, setTo] = useState(today());
    const [creating, setCreating] = useState(false);
    const [selected, setSelected] = useState<string | null>(null);
    const [version, setVersion] = useState(0);
    const reload = () => setVersion((v) => v + 1);

    if (!companyId) {
        return <Page title="Journal entries"><div className="erp-card">No company is available for your account.</div></Page>;
    }

    return (
        <Page
            title="Journal entries"
            subtitle="Posted entries are immutable; corrections are made by reversal"
            actions={(
                <>
                    {canVoucher && <Button text="Record expense" icon="minus" stylingMode="outlined" onClick={() => setVoucher('expense')} />}
                    {canVoucher && <Button text="Record income" icon="plus" stylingMode="outlined" onClick={() => setVoucher('income')} />}
                    {canCreate && <Button text="New journal voucher" icon="plus" type="default" onClick={() => setCreating(true)} />}
                </>
            )}
        >
            <div className="erp-row">
                <Field label="From"><DateBox type="date" value={from} dateSerializationFormat="yyyy-MM-dd" onValueChanged={(e) => setFrom(e.value)} /></Field>
                <Field label="To"><DateBox type="date" value={to} dateSerializationFormat="yyyy-MM-dd" onValueChanged={(e) => setTo(e.value)} /></Field>
            </div>
            <CrudGrid key={version} resource="/journal-entries" params={{ company_id: companyId, from, to }} height="calc(100vh - 270px)" onRowClick={(row) => setSelected(row.id)}>
                <Column dataField="journal_no" caption="No." width={130} />
                <Column dataField="posting_date" caption="Date" dataType="date" width={130} />
                <Column dataField="source_type" caption="Source" width={160} />
                <Column dataField="description" />
                <Column dataField="status" width={120} cellRender={statusCell} />
                <Column {...numberColumn('total')} caption="Amount" />
            </CrudGrid>
            {creating && <JournalForm companyId={companyId} onClose={(saved) => { setCreating(false); if (saved) reload(); }} />}
            {voucher && <VoucherForm type={voucher} companyId={companyId} onClose={(saved) => { setVoucher(null); if (saved) reload(); }} />}
            {selected && <JournalDetail journalId={selected} onClose={(changed) => { setSelected(null); if (changed) reload(); }} />}
        </Page>
    );
}

function JournalForm({ companyId, onClose }: { companyId: string; onClose: (saved: boolean) => void }) {
    const accounts = useQuery({ queryKey: ['accounts', companyId], queryFn: () => getList('/accounts', { company_id: companyId }) });
    const [date, setDate] = useState(today());
    const [description, setDescription] = useState('');
    const [busy, setBusy] = useState(false);
    const lines = useRef<any[]>([]);
    const key = useRef(newIdempotencyKey());

    const save = async () => {
        const payload = cleanLines(lines.current)
            .filter((l) => l.account_id && (Number(l.debit) > 0 || Number(l.credit) > 0))
            .map((l) => ({ account_id: l.account_id, debit: Number(l.debit) || 0, credit: Number(l.credit) || 0, description: l.description || null }));
        const debit = payload.reduce((s, l) => s + l.debit, 0);
        const credit = payload.reduce((s, l) => s + l.credit, 0);

        if (payload.length < 2 || !description.trim()) {
            notifyError(new Error('Enter a description and at least two lines.'));
            return;
        }
        if (Math.abs(debit - credit) > 0.004) {
            notifyError(new Error(`Debits (${debit.toFixed(2)}) and credits (${credit.toFixed(2)}) must be equal.`));
            return;
        }
        setBusy(true);
        try {
            await api.post('/journal-entries', { company_id: companyId, posting_date: date, description, lines: payload }, idempotent(key.current));
            notifySuccess('Journal posted');
            onClose(true);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title="New journal voucher" width={900} height="auto" onHiding={() => onClose(false)} showCloseButton>
            <div className="erp-row">
                <Field label="Posting date"><DateBox type="date" value={date} dateSerializationFormat="yyyy-MM-dd" onValueChanged={(e) => setDate(e.value)} /></Field>
                <Field label="Description"><TextBox width={520} value={description} valueChangeEvent="input" onValueChanged={(e) => setDescription(e.value)} /></Field>
            </div>
            <LinesGrid linesRef={lines}>
                <Column dataField="account_id" caption="Account">
                    <Lookup dataSource={(accounts.data ?? []).filter((a: any) => a.status === 'active')} valueExpr="id" displayExpr={(a: any) => (a ? `${a.code} — ${a.name}` : '')} />
                    <RequiredRule />
                </Column>
                <Column dataField="description" caption="Line note" />
                <Column dataField="debit" dataType="number" width={140} format={sumFormat} />
                <Column dataField="credit" dataType="number" width={140} format={sumFormat} />
                <Summary recalculateWhileEditing>
                    <TotalItem column="debit" summaryType="sum" valueFormat={sumFormat} displayFormat="{0}" />
                    <TotalItem column="credit" summaryType="sum" valueFormat={sumFormat} displayFormat="{0}" />
                </Summary>
            </LinesGrid>
            <div className="erp-actions">
                <Button text="Cancel" onClick={() => onClose(false)} />
                <Button text={busy ? 'Posting…' : 'Post journal'} type="default" disabled={busy} onClick={save} />
            </div>
        </Popup>
    );
}

function JournalDetail({ journalId, onClose }: { journalId: string; onClose: (changed: boolean) => void }) {
    const canReverse = useCan('finance.journal.reverse');
    const entry = useQuery({ queryKey: ['journal', journalId], queryFn: () => getOne(`/journal-entries/${journalId}`) });
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const data = entry.data;

    const reverse = async () => {
        setBusy(true);
        try {
            await api.post(`/journal-entries/${journalId}/reverse`, { reason });
            notifySuccess('Reversal posted');
            onClose(true);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title={data ? `Journal ${data.journal_no}` : 'Journal'} width={820} height="auto" onHiding={() => onClose(false)} showCloseButton>
            {data && (
                <>
                    <div className="erp-row">
                        <StatusBadge value={data.status} />
                        <span className="erp-muted">{data.posting_date}</span>
                        <span className="erp-muted">Source: {data.source_type}</span>
                        <span>{data.description}</span>
                    </div>
                    <DataGrid dataSource={data.lines} showBorders columnAutoWidth>
                        <Column dataField="account.code" caption="Code" width={110} />
                        <Column dataField="account.name" caption="Account" />
                        <Column dataField="description" caption="Note" />
                        <Column {...numberColumn('debit')} />
                        <Column {...numberColumn('credit')} />
                        <Summary>
                            <TotalItem column="debit" summaryType="sum" valueFormat={sumFormat} displayFormat="{0}" />
                            <TotalItem column="credit" summaryType="sum" valueFormat={sumFormat} displayFormat="{0}" />
                        </Summary>
                    </DataGrid>
                    {canReverse && data.status === 'posted' && REVERSIBLE.includes(data.source_type) && (
                        <div className="erp-row" style={{ marginTop: 24, marginBottom: 0 }}>
                            <Field label="Reason for reversal (required)">
                                <TextBox width={420} value={reason} valueChangeEvent="input" onValueChanged={(e) => setReason(e.value)} />
                            </Field>
                            <Button text={busy ? 'Reversing…' : 'Reverse entry'} type="danger" disabled={busy || reason.trim().length < 3} onClick={reverse} />
                        </div>
                    )}
                    {!REVERSIBLE.includes(data.source_type) && (
                        <div className="erp-muted" style={{ marginTop: 16 }}>
                            Generated from a {data.source_type.replace('_', ' ')} document — correct it there (for example by voiding the sale).
                        </div>
                    )}
                </>
            )}
        </Popup>
    );
}

function VoucherForm({ type, companyId, onClose }: { type: 'expense' | 'income'; companyId: string; onClose: (saved: boolean) => void }) {
    const accounts = useQuery({ queryKey: ['accounts', companyId], queryFn: () => getList('/accounts', { company_id: companyId }) });
    const [accountId, setAccountId] = useState<string | null>(null);
    const [method, setMethod] = useState('cash');
    const [amount, setAmount] = useState(0);
    const [date, setDate] = useState(today());
    const [party, setParty] = useState('');
    const [description, setDescription] = useState('');
    const [busy, setBusy] = useState(false);
    const key = useRef(newIdempotencyKey());
    const isExpense = type === 'expense';
    const choices = (accounts.data ?? []).filter((a: any) => a.status === 'active' && a.account_type === (isExpense ? 'expense' : 'revenue'));

    const save = async () => {
        setBusy(true);
        try {
            await api.post('/vouchers', { company_id: companyId, type, account_id: accountId, method, amount, posting_date: date, party: party || null, description }, idempotent(key.current));
            notifySuccess(isExpense ? 'Expense recorded' : 'Income recorded');
            onClose(true);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title={isExpense ? 'Record an expense' : 'Record other income'} width={620} height="auto" onHiding={() => onClose(false)} showCloseButton>
            <div className="erp-muted" style={{ marginBottom: 12 }}>
                {isExpense ? 'Money paid out for rent, utilities, wages and the like.' : 'Money received that is not a sale.'} Posts a balanced journal for you.
            </div>
            <div className="erp-row">
                <SelectBox label={isExpense ? 'Expense account' : 'Income account'} width={300} dataSource={choices} valueExpr="id" searchEnabled value={accountId}
                    displayExpr={(a: any) => (a ? `${a.code} — ${a.name}` : '')} onValueChanged={(e) => setAccountId(e.value)} />
                <NumberBox label="Amount" width={150} value={amount} min={0} format="#,##0.00" onValueChanged={(e) => setAmount(e.value ?? 0)} />
            </div>
            <div className="erp-row">
                <SelectBox label={isExpense ? 'Paid from' : 'Received into'} width={150} dataSource={[{ id: 'cash', name: 'Cash' }, { id: 'bank', name: 'Bank' }]} valueExpr="id" displayExpr="name" value={method} onValueChanged={(e) => setMethod(e.value)} />
                <Field label="Date"><DateBox type="date" width={150} value={date} dateSerializationFormat="yyyy-MM-dd" onValueChanged={(e) => setDate(e.value)} /></Field>
                <TextBox label={isExpense ? 'Paid to' : 'Received from'} width={220} value={party} onValueChanged={(e) => setParty(e.value)} />
            </div>
            <TextBox label="What for (required)" value={description} valueChangeEvent="input" onValueChanged={(e) => setDescription(e.value)} />
            <div className="erp-actions">
                <Button text="Cancel" onClick={() => onClose(false)} />
                <Button text={busy ? 'Posting…' : 'Post'} type="default" disabled={busy || !accountId || amount <= 0 || description.trim().length < 3} onClick={save} />
            </div>
        </Popup>
    );
}
