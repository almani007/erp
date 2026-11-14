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
            <div className="erp-login-hero">
                <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                    <div className="erp-logo">B</div>
                    <span style={{ fontWeight: 650, fontSize: '1.1rem' }}>Brixel ERP</span>
                </div>
                <div>
                    <h1>Every branch, every sale, one set of books.</h1>
                    <p>Point of sale, stock, purchasing and accounting that stay in step — each sale moves stock and posts its journal in the same instant.</p>
                </div>
                <div className="erp-login-points">
                    <span>✓ Multi-company, multi-branch, multi-warehouse</span>
                    <span>✓ Branch-wise pricing, deals and coupons</span>
                    <span>✓ Immutable ledgers with a full audit trail</span>
                </div>
            </div>
            <div className="erp-login-panel">
                <form className="erp-login-card" onSubmit={submit}>
                    <div>
                        <h2 className="erp-page-title">Sign in</h2>
                        <div className="erp-muted">Use your work email and password.</div>
                    </div>
                    <TextBox label="Email" mode="email" value={email} valueChangeEvent="input" onValueChanged={(e) => setEmail(e.value)} stylingMode="outlined" />
                    <TextBox label="Password" mode="password" value={password} valueChangeEvent="input" onValueChanged={(e) => setPassword(e.value)} stylingMode="outlined" />
                    {error && <div className="erp-error" role="alert">{error}</div>}
                    <Button text={busy ? 'Signing in…' : 'Sign in'} type="default" height={44} useSubmitBehavior disabled={busy || !email || !password} />
                </form>
            </div>
        </div>
    );
}
