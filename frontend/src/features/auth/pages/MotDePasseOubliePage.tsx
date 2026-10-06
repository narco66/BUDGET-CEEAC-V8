import { FormEvent, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { ensureCsrfCookie } from '../../../api/httpClient';
import { Alert, Button, FormField } from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

export default function MotDePasseOubliePage() {
    const [email, setEmail] = useState('');
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [pending, setPending] = useState(false);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setPending(true);
        setError('');
        setMessage('');
        try {
            await ensureCsrfCookie();
            const response = await api.post('/auth/mot-de-passe/oublie', { email });
            setMessage(response.data.message);
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    return (
        <main className="login-page">
            <div className="login-panel">
                <form className="login-card stack" onSubmit={submit}>
                    <h1 className="page-title">Mot de passe oublié</h1>
                    <p className="muted">Indiquez l’adresse du compte. Le message part par le canal de messagerie configuré. Aucune information n’indique si le compte existe.</p>
                    <FormField label="Adresse électronique" required>
                        <input className="inp" type="email" required value={email} onChange={(event) => setEmail(event.target.value)} autoComplete="username" />
                    </FormField>
                    {message && <Alert tone="success">{message}</Alert>}
                    {error && <Alert tone="danger">{error}</Alert>}
                    <Button type="submit" variant="brand" loading={pending}>Envoyer le lien</Button>
                    <Link to="/connexion">Retour à la connexion</Link>
                </form>
            </div>
        </main>
    );
}
