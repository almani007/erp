import { FormEvent, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import TextBox from 'devextreme-react/text-box';
import Button from 'devextreme-react/button';
import { api, errorMessage } from '../lib/api';
import { useSession } from '../lib/store';

export default function LoginPage() {
    const navigate = useNavigate();
    const signIn = useSession((s) => s.signIn);
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const { data } = await api.post('/auth/login', { email, password });
            signIn(data.data);
            navigate('/', { replace: true });
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="erp-login">
            <form className="erp-card erp-login-card" onSubmit={submit}>
                <div>
                    <div className="erp-brand" style={{ fontSize: '1.5rem' }}>Brixel ERP</div>
                    <div className="erp-muted">Sign in to continue</div>
                </div>
                <TextBox label="Email" mode="email" value={email} valueChangeEvent="input" onValueChanged={(e) => setEmail(e.value)} stylingMode="outlined" />
                <TextBox label="Password" mode="password" value={password} valueChangeEvent="input" onValueChanged={(e) => setPassword(e.value)} stylingMode="outlined" />
                {error && <div className="erp-error">{error}</div>}
                <Button text={busy ? 'Signing in…' : 'Sign in'} type="default" useSubmitBehavior disabled={busy || !email || !password} />
            </form>
        </div>
    );
}
