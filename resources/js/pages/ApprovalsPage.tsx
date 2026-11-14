import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import DataGrid, { Column, MasterDetail } from 'devextreme-react/data-grid';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TabPanel, { Item } from 'devextreme-react/tab-panel';
import TagBox from 'devextreme-react/tag-box';
import Page, { Field } from '../components/Page';
import { statusCell } from '../components/Status';
import { api, getList, notifyError, notifySuccess } from '../lib/api';
import { can } from '../lib/store';

/** Approval inbox, my requests, and the rules that decide what needs approval. */
export default function ApprovalsPage() {
    return (
        <Page title="Approvals" subtitle="Payments, stock adjustments, invoices and advances above set amounts wait here until approved.">
            <TabPanel className="erp-tabs" deferRendering animationEnabled={false}>
                <Item title="Waiting for me"><Requests box="waiting" /></Item>
                <Item title="My requests"><Requests box="mine" /></Item>
                {can('workflow.rules.manage') && <Item title="All"><Requests box="all" /></Item>}
                {can('workflow.rules.manage') && <Item title="Rules"><Rules /></Item>}
            </TabPanel>
        </Page>
    );
}

function Requests({ box }: { box: 'waiting' | 'mine' | 'all' }) {
    const client = useQueryClient();
    const rows = useQuery({ queryKey: ['approvals', box], queryFn: () => getList<any>('/approval-requests', { box }), refetchInterval: 30000 });
    const decide = async (r: any, decision: 'approve' | 'reject') => {
        const note = decision === 'reject' ? window.prompt('Why is it rejected?') : (window.prompt('Note (optional)') ?? undefined);
        if (decision === 'reject' && !note) return;
        try {
            const { data } = await api.post(`/approval-requests/${r.id}/decide`, { decision, note: note || null });
            notifySuccess(data.data.status === 'executed' ? `Approved and done: ${data.data.result_label}` : data.data.status === 'failed' ? `Approved, but it failed: ${data.data.error}` : decision === 'approve' ? 'Approved — passed to the next level.' : 'Rejected.');
            client.invalidateQueries({ queryKey: ['approvals'] });
        } catch (e) {
            notifyError(e);
        }
    };
    const cancel = async (r: any) => {
        try { await api.post(`/approval-requests/${r.id}/cancel`); client.invalidateQueries({ queryKey: ['approvals'] }); } catch (e) { notifyError(e); }
    };

    return (
        <div className="erp-card">
            <DataGrid dataSource={rows.data ?? []} keyExpr="id" showRowLines columnAutoWidth height="calc(100vh - 260px)" noDataText={box === 'waiting' ? 'Nothing waiting for you.' : 'No requests.'}>
                <Column dataField="created_at" caption="Asked" dataType="datetime" width={150} />
                <Column dataField="summary" />
                <Column dataField="amount" dataType="number" format="#,##0.00" width={130} />
                <Column dataField="requested_by_name" caption="By" />
                <Column caption="Step" width={160} calculateCellValue={(r: any) => (r.status === 'pending' ? `${r.current_level + 1}/${r.levels.length}: ${String(r.waiting_for).replace(/_/g, ' ')}` : '')} />
                <Column dataField="result_label" caption="Result" />
                <Column dataField="error" />
                <Column dataField="status" width={110} cellRender={statusCell} />
                <Column caption="" width={220} cellRender={({ data }) => (
                    <>
                        {data.can_decide && <Button text="Approve" type="success" stylingMode="text" onClick={() => decide(data, 'approve')} />}
                        {data.can_decide && <Button text="Reject" type="danger" stylingMode="text" onClick={() => decide(data, 'reject')} />}
                        {box === 'mine' && data.status === 'pending' && <Button text="Withdraw" stylingMode="text" onClick={() => cancel(data)} />}
                    </>
                )} />
                <MasterDetail enabled render={({ data }) => (
                    <div>{(data.history ?? []).map((h: any, i: number) => <div key={i}>Level {h.level} ({h.role.replace(/_/g, ' ')}): <b>{h.decision}</b> by {h.user} · {new Date(h.at).toLocaleString()}{h.note ? ` — ${h.note}` : ''}</div>)}
                        {(data.history ?? []).length === 0 && <span className="erp-muted">No decisions yet.</span>}</div>
                )} />
            </DataGrid>
        </div>
    );
}

function Rules() {
    const client = useQueryClient();
    const q = useQuery({ queryKey: ['approval-rules'], queryFn: () => api.get('/approval-rules').then((r) => r.data) });
    const roles = useQuery({ queryKey: ['roles'], queryFn: () => getList<any>('/roles') }).data ?? [];
    const types: Record<string, string> = q.data?.meta?.types ?? {};
    const [f, setF] = useState<any | null>(null);

    const save = async () => {
        try {
            if (f.id) await api.put(`/approval-rules/${f.id}`, f); else await api.post('/approval-rules', f);
            notifySuccess('Rule saved.');
            setF(null);
            client.invalidateQueries({ queryKey: ['approval-rules'] });
        } catch (e) {
            notifyError(e);
        }
    };

    return (
        <div className="erp-card">
            <div className="erp-actions" style={{ marginTop: 0, justifyContent: 'flex-start' }}>
                <Button text="New rule" icon="add" type="default" onClick={() => setF({ document_type: 'supplier_payment', min_amount: 100000, levels: ['finance_controller'] })} />
                <span className="erp-muted">The rule with the highest amount that a request reaches is the one used. Approvers cannot approve their own requests.</span>
            </div>
            <DataGrid dataSource={q.data?.data ?? []} keyExpr="id" showRowLines hoverStateEnabled onRowClick={(e) => e.rowType === 'data' && setF({ ...e.data })}>
                <Column caption="What" calculateCellValue={(r: any) => types[r.document_type] ?? r.document_type} />
                <Column dataField="min_amount" caption="From amount" dataType="number" format="#,##0" />
                <Column caption="Approved by, in order" calculateCellValue={(r: any) => r.levels.map((l: string) => l.replace(/_/g, ' ')).join(' → ')} />
                <Column dataField="status" width={110} cellRender={statusCell} />
            </DataGrid>
            {f && (
                <Popup visible title="Approval rule" width={560} height="auto" onHiding={() => setF(null)} showCloseButton>
                    <Field label="What needs approval"><SelectBox dataSource={Object.entries(types).map(([id, name]) => ({ id, name }))} valueExpr="id" displayExpr="name" value={f.document_type} onValueChanged={(e) => setF({ ...f, document_type: e.value })} /></Field>
                    <Field label="When the amount is at least"><NumberBox min={0} format="#,##0" value={Number(f.min_amount)} onValueChanged={(e) => setF({ ...f, min_amount: e.value ?? 0 })} /></Field>
                    <Field label="Approved by (in this order)"><TagBox dataSource={roles} valueExpr="name" displayExpr={(r: any) => (r ? r.name.replace(/_/g, ' ') : '')} value={f.levels} onValueChanged={(e) => setF({ ...f, levels: e.value })} /></Field>
                    {f.id && <SelectBox dataSource={['active', 'inactive']} value={f.status} onValueChanged={(e) => setF({ ...f, status: e.value })} />}
                    <div className="erp-actions"><Button text="Cancel" onClick={() => setF(null)} /><Button text="Save" type="default" disabled={!f.levels?.length} onClick={save} /></div>
                </Popup>
            )}
        </div>
    );
}

