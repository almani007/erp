import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Button from 'devextreme-react/button';
import CheckBox from 'devextreme-react/check-box';
import Popup from 'devextreme-react/popup';
import SelectBox from 'devextreme-react/select-box';
import TagBox from 'devextreme-react/tag-box';
import TextBox from 'devextreme-react/text-box';
import { Column } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import Page from '../components/Page';
import { api, getList, notifyError, notifySuccess } from '../lib/api';
import { useCan } from '../lib/store';

export default function UsersPage() {
    const canManage = useCan('identity.user.manage');
    const [editing, setEditing] = useState<any | null>(null);
    const [version, setVersion] = useState(0);

    return (
        <Page
            title="Users & roles"
            subtitle={canManage ? 'Click a user to edit. Roles are predefined in this release.' : 'Read-only'}
            actions={canManage && <Button text="New user" icon="plus" type="default" onClick={() => setEditing({})} />}
        >
            <CrudGrid key={version} resource="/users" onRowClick={canManage ? setEditing : undefined}>
                <Column dataField="name" />
                <Column dataField="email" />
                <Column caption="Roles" calculateCellValue={(u: any) => (u.roles ?? []).join(', ')} />
                <Column caption="Branches" calculateCellValue={(u: any) => (u.all_branches ? 'All branches' : `${u.branch_ids?.length ?? 0} selected`)} width={160} />
                <Column dataField="status" width={120} />
                <Column dataField="last_login_at" caption="Last sign-in" dataType="datetime" width={190} />
            </CrudGrid>
            {editing && <UserForm user={editing} onClose={(saved) => { setEditing(null); if (saved) setVersion((v) => v + 1); }} />}
        </Page>
    );
}

function UserForm({ user, onClose }: { user: any; onClose: (saved: boolean) => void }) {
    const isNew = !user.id;
    const roles = useQuery({ queryKey: ['roles'], queryFn: () => getList('/roles') });
    const branches = useQuery({ queryKey: ['branches'], queryFn: () => getList('/branches') });
    const [form, setForm] = useState({
        name: user.name ?? '',
        email: user.email ?? '',
        password: '',
        status: user.status ?? 'active',
        all_branches: user.all_branches ?? false,
        roles: user.roles ?? [],
        branch_ids: user.branch_ids ?? [],
    });
    const [busy, setBusy] = useState(false);
    const set = (patch: Partial<typeof form>) => setForm((f) => ({ ...f, ...patch }));

    const save = async () => {
        setBusy(true);
        try {
            const { email, password, ...rest } = form;
            if (isNew) {
                await api.post('/users', { ...rest, email, password });
            } else {
                await api.put(`/users/${user.id}`, password ? { ...rest, password } : rest);
            }
            notifySuccess(isNew ? 'User created' : 'User updated');
            onClose(true);
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popup visible title={isNew ? 'New user' : `Edit ${user.name}`} width={520} height="auto" onHiding={() => onClose(false)} showCloseButton>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                <TextBox label="Name" value={form.name} valueChangeEvent="input" onValueChanged={(e) => set({ name: e.value })} />
                <TextBox label="Email" mode="email" value={form.email} readOnly={!isNew} valueChangeEvent="input" onValueChanged={(e) => set({ email: e.value })} />
                <TextBox
                    label={isNew ? 'Password (min 10 characters)' : 'New password (leave blank to keep; changing it signs the user out)'}
                    mode="password" value={form.password} valueChangeEvent="input" onValueChanged={(e) => set({ password: e.value })}
                />
                <TagBox label="Roles" dataSource={(roles.data ?? []).map((r: any) => r.name)} value={form.roles} onValueChanged={(e) => set({ roles: e.value })} />
                <CheckBox text="Access to all branches" value={form.all_branches} onValueChanged={(e) => set({ all_branches: !!e.value })} />
                {!form.all_branches && (
                    <TagBox label="Branches" dataSource={branches.data ?? []} valueExpr="id" displayExpr="name" value={form.branch_ids} onValueChanged={(e) => set({ branch_ids: e.value })} />
                )}
                {!isNew && <SelectBox label="Status" dataSource={['active', 'disabled']} value={form.status} onValueChanged={(e) => set({ status: e.value })} />}
            </div>
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
                <Button text="Cancel" onClick={() => onClose(false)} />
                <Button text={busy ? 'Saving…' : 'Save'} type="default" disabled={busy || !form.name || !form.email || (isNew && form.password.length < 10)} onClick={save} />
            </div>
        </Popup>
    );
}
