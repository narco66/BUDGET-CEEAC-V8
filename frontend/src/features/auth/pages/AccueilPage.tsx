import { Link } from 'react-router-dom';
import CeeacMark from '../../../components/brand/CeeacMark';

export default function AccueilPage() {
    return (
        <main className="login-page">
            <aside className="login-aside" aria-hidden="true">
                <div className="login-brand" style={{ color: '#fff' }}>
                    <CeeacMark />
                    <div>
                        <strong>BUDGET-CEEAC</strong>
                        <small style={{ color: 'rgba(255,255,255,.6)' }}>GESBUDEP · Commission de la CEEAC</small>
                    </div>
                </div>
            </aside>
            <div className="login-panel">
                <article className="login-card stack">
                    <h1 className="page-title">Système intégré de gestion budgétaire</h1>
                    <p>BUDGET-CEEAC relie le budget voté, la chaîne de dépense, les pièces, les validations et le suivi de la performance de la Commission de la CEEAC.</p>
                    <p>L’accès aux dossiers exige un compte actif. Un acte déjà émis se vérifie avec son code, sans ouvrir le dossier.</p>
                    <div className="cluster">
                        <Link className="btn btn-primary" to="/connexion">Se connecter</Link>
                    </div>
                </article>
            </div>
        </main>
    );
}
