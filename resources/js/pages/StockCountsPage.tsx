import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import NumberBox from 'devextreme-react/number-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TextBox from 'devextreme-react/text-box';
import { Column } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import { StatusBadge, statusCell } from '../components/Status';
import Page, { Field } from '../components/Page';
import { api, getList, getOne, notifyError, notifySuccess } from '../lib/api';
import { qty } from '../lib/format';
import { useCan } from '../lib/store';

export default function StockCountsPage() {
    const canManage = useCan('inventory.count.manage');
    const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: () => getList('/warehouses') });
    const [warehouseId, setWarehouseId] = useState<string | null>(null);
    const [selected, setSelected] = useState<string | null>(null);
    const [version, setVersion] = useState(0);
    const [busy, setBusy] = useState(false);

    const start = async () => {
        setBusy(true);
        try {
            const { data } = await api.post('/stock-counts', { warehouse_id: warehouseId });
            setVersion((v) => v + 1);
            setSelected(data.data.id);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Page title="Stock counts" subtitle="Count what is on the shelf; posting adjusts stock and the ledger for the differences only">
            {canManage && (
                <div className="erp-row">
                    <Field label="Start a count for">
                        <SelectBox width={260} dataSource={warehouses.data ?? []} valueExpr="id" displayExpr="name" value={warehouseId} placeholder="Choose a warehouse" onValueChanged={(e) => setWarehouseId(e.value)} />
                    </Field>
                    <Button text={busy ? 'Starting…' : 'Start count'} icon="plus" type="default" disabled={busy || !warehouseId} onClick={start} />
                </div>
            )}
            <CrudGrid key={version} resource="/stock-counts" height="calc(100vh - 270px)" onRowClick={(row) => setSelected(row.id)}>
                <Column dataField="count_no" caption="Count" width={140} />
                <Column dataField="warehouse.name" caption="Warehouse" />
                <Column dataField="status" width={140} cellRender={statusCell} />
                <Column caption="Counted" width={130} calculateCellValue={(row: any) => `${row.counted_lines_count} of ${row.lines_count}`} />
                <Column dataField="started_at" caption="Started" dataType="datetime" width={180} />
                <Column dataField="posted_at" caption="Posted" dataType="datetime" width={180} />
            </CrudGrid>
            {selected && <CountSheet countId={selected} canManage={canManage} onClose={() => { setSelected(null); setVersion((v) => v + 1); }} />}
        </Page>
    );
}

function CountSheet({ countId, canManage, onClose }: { countId: string; canManage: boolean; onClose: () => void }) {
    const count = useQuery({ queryKey: ['stock-count', countId], queryFn: () => getOne(`/stock-counts/${countId}`) });
    const [counted, setCounted] = useState<Record<string, number | null>>({});
    const [filter, setFilter] = useState('');
    const [busy, setBusy] = useState(false);
    const data = count.data;
    const open = data?.status === 'in_progress' && canManage;

    useEffect(() => {
        if (data) {
            setCounted(Object.fromEntries(data.lines.map((l: any) => [l.id, l.counted_qty === null ? null : Number(l.counted_qty)])));
        }
    }, [data]);

    const save = async () => {
        await api.put(`/stock-counts/${countId}/lines`, { lines: Object.entries(counted).map(([id, counted_qty]) => ({ id, counted_qty })) });
    };

    const run = async (action: () => Promise<unknown>, message: string, close = false) => {
        setBusy(true);
        try {
            await action();
            notifySuccess(message);
            if (close) {
                onClose();
            } else {
                await count.refetch();
            }
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    const term = filter.trim().toLowerCase();
    const lines = (data?.lines ?? []).filter((l: any) => !term || l.product?.name.toLowerCase().includes(term) || l.product?.sku.toLowerCase().includes(term));
    const done = Object.values(counted).filter((v) => v !== null).length;

    return (
        <Popup visible title={data ? `Stock count ${data.count_no} — ${data.warehouse?.name}` : 'Stock count'} width={860} height="85vh" onHiding={onClose} showCloseButton>
            {data && (
                <div style={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
                    <div className="erp-row">
                        <StatusBadge value={data.status} />
                        <span className="erp-muted">{done} of {data.lines.length} lines counted</span>
                        <div style={{ flex: 1 }} />
                        <TextBox width={240} mode="search" placeholder="Find a product…" value={filter} valueChangeEvent="input" onValueChanged={(e) => setFilter(e.value)} />
                    </div>
                    <div style={{ flex: 1, overflowY: 'auto', border: '1px solid var(--erp-line)', borderRadius: 8 }}>
                        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.9rem' }}>
                            <thead>
                                <tr className="erp-muted" style={{ textAlign: 'left', position: 'sticky', top: 0, background: '#f8fafc' }}>
                                    <th style={{ padding: '8px 10px' }}>Product</th>
                                    <th className="erp-num">System qty</th>
                                    <th className="erp-num" style={{ width: 150, paddingRight: 10 }}>Counted</th>
                                    <th className="erp-num" style={{ width: 110, paddingRight: 10 }}>Difference</th>
                                </tr>
                            </thead>
                            <tbody>
                                {lines.map((line: any) => {
                                    const value = counted[line.id];
                                    const diff = data.status === 'posted' ? Number(line.variance_qty ?? 0) : value === null || value === undefined ? null : value - Number(line.system_qty);
                                    return (
                                        <tr key={line.id} style={{ borderTop: '1px solid var(--erp-line)' }}>
                                            <td style={{ padding: '6px 10px' }}>{line.product?.sku} — {line.product?.name}</td>
                                            <td className="erp-num">{qty(line.system_qty)}</td>
                                            <td style={{ padding: '4px 10px' }}>
                                                {open
                                                    ? <NumberBox value={value ?? undefined} min={0} placeholder="—" showClearButton onValueChanged={(e) => setCounted((c) => ({ ...c, [line.id]: e.value ?? null }))} />
                                                    : <div className="erp-num">{value === null || value === undefined ? '—' : qty(value)}</div>}
                                            </td>
                                            <td className="erp-num" style={{ paddingRight: 10, fontWeight: 600, color: diff ? 'var(--erp-danger)' : undefined }}>
                                                {diff === null ? '' : diff > 0 ? `+${qty(diff)}` : qty(diff)}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    {open && (
                        <>
                            <div className="erp-muted" style={{ marginTop: 10 }}>
                                Lines left blank are not adjusted. Differences are worked out against stock at the moment of posting, so sales made during the count are accounted for.
                            </div>
                            <div className="erp-actions" style={{ marginTop: 12 }}>
                                <Button text="Cancel count" stylingMode="outlined" type="danger" disabled={busy} onClick={() => run(() => api.post(`/stock-counts/${countId}/cancel`), 'Count cancelled', true)} />
                                <Button text="Save progress" stylingMode="outlined" disabled={busy} onClick={() => run(save, 'Progress saved')} />
                                <Button text={busy ? 'Working…' : 'Post count'} type="default" disabled={busy || done === 0}
                                    onClick={() => run(async () => { await save(); await api.post(`/stock-counts/${countId}/post`); }, 'Count posted — stock adjusted for the differences', true)} />
                            </div>
                        </>
                    )}
                </div>
            )}
        </Popup>
    );
}
