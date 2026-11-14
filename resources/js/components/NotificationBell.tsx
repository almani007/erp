import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import Popover from 'devextreme-react/popover';
import { api } from '../lib/api';

interface Note { id: string; kind: string; title: string; body: string | null; link: string | null; created_at: string; read: boolean }

/** Approvals waiting, decisions, low stock, FBR failures, overdue invoices — refreshed every minute. */
export default function NotificationBell() {
    const client = useQueryClient();
    const navigate = useNavigate();
    const [open, setOpen] = useState(false);
    const q = useQuery({ queryKey: ['notifications'], queryFn: () => api.get('/notifications').then((r) => r.data as { data: Note[]; meta: { unread: number } }), refetchInterval: 60000 });
    const unread = q.data?.meta.unread ?? 0;

    const read = async (ids: string[]) => {
        if (ids.length === 0) return;
        await api.post('/notifications/read', { ids }).catch(() => undefined);
        client.invalidateQueries({ queryKey: ['notifications'] });
    };
    const go = (n: Note) => {
        read([n.id]);
        setOpen(false);
        if (n.link) navigate(n.link);
    };

    return (
        <div style={{ position: 'relative' }}>
            <Button id="erp-bell" icon="bell" stylingMode="text" hint="Notifications" onClick={() => setOpen(!open)} />
            {unread > 0 && <span style={{ position: 'absolute', top: 2, right: 2, background: '#dc2626', color: '#fff', borderRadius: 9, fontSize: 11, minWidth: 18, textAlign: 'center', padding: '0 4px', pointerEvents: 'none' }}>{unread > 99 ? '99+' : unread}</span>}
            <Popover target="#erp-bell" visible={open} onHiding={() => setOpen(false)} width={380} position="bottom" hideOnOutsideClick>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                    <b>Notifications</b>
                    {unread > 0 && <Button text="Mark all read" stylingMode="text" onClick={() => read((q.data?.data ?? []).filter((n) => !n.read).map((n) => n.id))} />}
                </div>
                <div style={{ maxHeight: 420, overflowY: 'auto' }}>
                    {(q.data?.data ?? []).map((n) => (
                        <div key={n.id} role="button" tabIndex={0} onClick={() => go(n)} onKeyDown={(e) => e.key === 'Enter' && go(n)}
                            style={{ padding: '8px 4px', borderBottom: '1px solid var(--erp-border, #eee)', cursor: 'pointer', fontWeight: n.read ? 400 : 600 }}>
                            <div>{n.title}</div>
                            {n.body && <div className="erp-muted" style={{ fontWeight: 400 }}>{n.body}</div>}
                            <div className="erp-muted" style={{ fontSize: 11, fontWeight: 400 }}>{new Date(n.created_at).toLocaleString()}</div>
                        </div>
                    ))}
                    {(q.data?.data ?? []).length === 0 && <div className="erp-muted">Nothing new.</div>}
                </div>
            </Popover>
        </div>
    );
}
