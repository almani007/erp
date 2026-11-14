import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import SelectBox from 'devextreme-react/select-box';
import Page from '../components/Page';
import { api, getList, notifyError } from '../lib/api';
import { useSession } from '../lib/store';

interface Ticket { kot_no: number; order_id: string; order_no: string; order_type: string; table: string | null; sent_at: string; lines: { id: string; product_name: string; quantity: string; modifiers: { name: string }[] | null; note: string | null; station: string | null; kitchen_status: string }[] }

/** Kitchen display: tickets in the order they came in, refreshed every few seconds. */
export default function KitchenPage() {
    const client = useQueryClient();
    const branchId = useSession((s) => s.activeBranchId);
    const [station, setStation] = useState<string | null>(null);
    const tickets = useQuery({ queryKey: ['kitchen', branchId, station], queryFn: () => getList<Ticket>('/restaurant/kitchen', { station: station ?? undefined }), enabled: !!branchId, refetchInterval: 5000 });
    const [now, setNow] = useState(Date.now());
    useEffect(() => { const t = setInterval(() => setNow(Date.now()), 15000); return () => clearInterval(t); }, []);
    const stations = Array.from(new Set((tickets.data ?? []).flatMap((t) => t.lines.map((l) => l.station).filter(Boolean)))) as string[];

    const set = async (lineId: string, status: string) => {
        try { await api.post(`/restaurant/kitchen/lines/${lineId}`, { status }); client.invalidateQueries({ queryKey: ['kitchen'] }); } catch (e) { notifyError(e); }
    };

    if (!branchId) return <Page title="Kitchen"><div className="erp-card">Choose a branch at the top first.</div></Page>;

    return (
        <Page title="Kitchen display" subtitle="Tap Start when cooking begins and Ready when it can go out."
            actions={<SelectBox width={200} dataSource={[...new Set([...(station ? [station] : []), ...stations])]} value={station} placeholder="All stations" showClearButton onValueChanged={(e) => setStation(e.value)} />}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))', gap: 12 }}>
                {(tickets.data ?? []).map((t) => {
                    const minutes = Math.floor((now - new Date(t.sent_at).getTime()) / 60000);
                    const late = minutes >= 15;
                    return (
                        <div key={`${t.order_id}-${t.kot_no}`} className="erp-card" style={{ borderTop: `6px solid ${late ? '#dc2626' : minutes >= 8 ? '#d97706' : '#16a34a'}`, padding: 12 }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                                <b style={{ fontSize: 18 }}>{t.table ? `Table ${t.table}` : t.order_type}</b>
                                <span style={{ color: late ? '#dc2626' : undefined, fontWeight: 600 }}>{minutes} min</span>
                            </div>
                            <div className="erp-muted">KOT {t.kot_no} · {t.order_no}</div>
                            {t.lines.map((l) => (
                                <div key={l.id} style={{ padding: '8px 0', borderBottom: '1px dashed var(--erp-border, #ddd)', opacity: l.kitchen_status === 'ready' ? 0.55 : 1 }}>
                                    <div style={{ fontSize: 16 }}><b>{Number(l.quantity)} ×</b> {l.product_name}</div>
                                    {(l.modifiers ?? []).map((m, i) => <div key={i}>+ {m.name}</div>)}
                                    {l.note && <div style={{ color: '#b91c1c', fontWeight: 600 }}>** {l.note}</div>}
                                    <div style={{ display: 'flex', gap: 6, marginTop: 4 }}>
                                        {l.kitchen_status === 'sent' && <Button text="Start" onClick={() => set(l.id, 'preparing')} />}
                                        {['sent', 'preparing'].includes(l.kitchen_status) && <Button text="Ready" type="success" onClick={() => set(l.id, 'ready')} />}
                                        {l.kitchen_status === 'ready' && <Button text="Picked up" stylingMode="outlined" onClick={() => set(l.id, 'served')} />}
                                    </div>
                                </div>
                            ))}
                        </div>
                    );
                })}
                {(tickets.data ?? []).length === 0 && <div className="erp-card">No orders waiting.</div>}
            </div>
        </Page>
    );
}
