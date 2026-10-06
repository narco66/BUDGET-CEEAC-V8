import { config } from '@fortawesome/fontawesome-svg-core';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import AppRouter from './app/router/AppRouter';
import { DialogProvider, ToastProvider } from './components/ui';
import './styles/app.css';

/* La feuille de style Font Awesome est importée par app.css : pas d’injection à l’exécution. */
config.autoAddCss = false;

const root = document.getElementById('app');

if (root) {
    createRoot(root).render(
        <StrictMode>
            <ToastProvider>
                <DialogProvider>
                    <AppRouter />
                </DialogProvider>
            </ToastProvider>
        </StrictMode>,
    );
}
