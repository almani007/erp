import { ReactNode } from 'react';

interface PageProps {
    title: string;
    subtitle?: string;
    actions?: ReactNode;
    children: ReactNode;
}

export default function Page({ title, subtitle, actions, children }: PageProps) {
    return (
        <div className="erp-page">
            <div className="erp-page-header">
                <div>
                    <h1 className="erp-page-title">{title}</h1>
                    {subtitle && <div className="erp-muted">{subtitle}</div>}
                </div>
                {actions && <div style={{ display: 'flex', gap: 8 }}>{actions}</div>}
            </div>
            {children}
        </div>
    );
}

export function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="erp-field">
            <label>{label}</label>
            {children}
        </div>
    );
}
