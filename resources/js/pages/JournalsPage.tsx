import { useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import DateBox from 'devextreme-react/date-box';
import Popup from 'devextreme-react/popup';
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

export default function JournalsPage() {
    const companyId = useCompanyId();
    const canCreate = useCan('finance.journal.create');
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
            actions={canCreate && <Button text="New journal voucher" icon="plus" type="default" onClick={() => setCreating(true)} />}
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
                    {canReverse && data.status === 'posted' && data.source_type === 'manual' && (
                        <div className="erp-row" style={{ marginTop: 24, marginBottom: 0 }}>
                            <Field label="Reason for reversal (required)">
                                <TextBox width={420} value={reason} valueChangeEvent="input" onValueChanged={(e) => setReason(e.value)} />
                            </Field>
                            <Button text={busy ? 'Reversing…' : 'Reverse entry'} type="danger" disabled={busy || reason.trim().length < 3} onClick={reverse} />
                        </div>
                    )}
                    {data.source_type !== 'manual' && (
                        <div className="erp-muted" style={{ marginTop: 16 }}>
                            Generated from a {data.source_type.replace('_', ' ')} document — correct it there (for example by voiding the sale).
                        </div>
                    )}
                </>
            )}
        </Popup>
    );
}
