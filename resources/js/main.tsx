import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import config from 'devextreme/core/config';
import 'devextreme/dist/css/dx.material.blue.light.css';
import '../css/app.css';
import App from './App';

// DevExtreme is a commercial library: without a key it runs in trial mode
// and shows a notice. Set VITE_DEVEXTREME_LICENSE_KEY in .env and rebuild.
const licenseKey = import.meta.env.VITE_DEVEXTREME_LICENSE_KEY as string | undefined;
if (licenseKey) {
    config({ licenseKey });
}

const queryClient = new QueryClient({
    defaultOptions: { queries: { refetchOnWindowFocus: false, retry: false, staleTime: 30_000 } },
});

createRoot(document.getElementById('root')!).render(
    <React.StrictMode>
        <QueryClientProvider client={queryClient}>
            <BrowserRouter>
                <App />
            </BrowserRouter>
        </QueryClientProvider>
    </React.StrictMode>,
);
