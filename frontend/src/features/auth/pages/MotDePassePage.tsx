import { FormEvent, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import api, { ensureCsrfCookie } from '../../../api/httpClient';
import { Alert, Button, FormField } from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

export default function MotDePassePage() {
    const { token = '' } = useParams();
    const [params] = useSearchParams();
    const [email, setEmail] = useState(params.get('email') ?? '');
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [pending, setPending] = useState(false);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setPending(true);
        setError('');
        try {
            await ensureCsrfCookie();
            const response = await api.post('/auth/mot-de-passe/reinitialiser', {
                email,
                token,
                password,
                password_confirmation: confirmation,
            });
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
                    <h1 className="page-title">Nouveau mot de passe</h1>
                    <p className="muted">Douze caractères au minimum, avec des lettres majuscules, des lettres minuscules et des chiffres.</p>
                    <FormField label="Adresse électronique" required>
                        <input className="inp" type="email" required value={email} onChange={(event) => setEmail(event.target.value)} autoComplete="username" />
                    </FormField>
                    <FormField label="Nouveau mot de passe" required>
                        <input className="inp" type="password" required minLength={12} value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="new-password" />
                    </FormField>
                    <FormField label="Confirmation" required>
                        <input className="inp" type="password" required minLength={12} value={confirmation} onChange={(event) => setConfirmation(event.target.value)} autoComplete="new-password" />
                    </FormField>
                    {message && <Alert tone="success">{message}</Alert>}
                    {error && <Alert tone="danger">{error}</Alert>}
                    <Button type="submit" variant="brand" loading={pending} disabled={message !== ''}>Enregistrer</Button>
                    <Link to="/connexion">Se connecter</Link>
                </form>
            </div>
        </main>
    );
}
