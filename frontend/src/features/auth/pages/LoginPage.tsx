import { faChartColumn, faEnvelope, faEye, faEyeSlash, faFileContract, faKey, faLock, faShieldHalved } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { FormEvent, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import api, { ensureCsrfCookie, forgetActor } from '../../../api/httpClient';
import CeeacMark from '../../../components/brand/CeeacMark';
import { Alert, Button, FormField, InputGroup } from '../../../components/ui';

export default function LoginPage() {
    const [params] = useSearchParams();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [code, setCode] = useState('');
    const [secret, setSecret] = useState('');
    const [mfa, setMfa] = useState(false);
    const [visible, setVisible] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [pending, setPending] = useState(false);
    const [sso, setSso] = useState(false);

    useEffect(() => {
        api.get('/auth/sso').then((response) => setSso(Boolean(response.data.actif))).catch(() => setSso(false));
    }, []);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setPending(true);
        setError(null);
        try {
            await ensureCsrfCookie();
            const response = await api.post('/auth/login', { email, password, code: code || undefined, secret: secret || undefined });
            if (response.status === 202 || response.data?.mfa === 'enrolement') {
                setSecret(response.data.secret ?? secret);
                setMfa(true);
                setPending(false);
                return;
            }
            forgetActor();
            const next = params.get('suite');
            window.location.assign(next && next.startsWith('/') && !next.startsWith('//') ? next : '/taches');
        } catch (exception: any) {
            const errors = exception?.response?.data?.errors;
            if (errors?.code) {
                setMfa(true);
            }
            setError(errors?.code?.[0] ?? errors?.email?.[0] ?? exception?.response?.data?.message ?? 'Connexion impossible.');
            setPending(false);
        }
    }

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
                <div>
                    <h2>Gestion budgétaire, exécution de la dépense et pilotage de la performance.</h2>
                    <p>De l’expression de besoin au paiement, chaque acte est tracé, contrôlé et rattaché au budget voté et au Plan d’action prioritaire.</p>
                </div>
                <div className="login-pillars">
                    <div className="login-pillar"><FontAwesomeIcon icon={faFileContract} /><strong>Chaîne de dépense</strong>EB, engagement, liquidation, ordonnancement, paiement.</div>
                    <div className="login-pillar"><FontAwesomeIcon icon={faChartColumn} /><strong>Suivi-évaluation</strong>Exécution physique et financière, écarts, rapports.</div>
                    <div className="login-pillar"><FontAwesomeIcon icon={faShieldHalved} /><strong>Contrôle interne</strong>Séparation des fonctions, visas, signatures, audit.</div>
                </div>
            </aside>

            <div className="login-panel">
                <form className="login-card" onSubmit={submit} noValidate>
                    <div className="login-brand">
                        <CeeacMark />
                        <div>
                            <strong>BUDGET-CEEAC</strong>
                            <small className="muted">GESBUDEP · Commission de la CEEAC</small>
                        </div>
                    </div>
                    <div className="stack-sm">
                        <h1 className="page-title login-title">Connexion</h1>
                        <p className="muted">Accédez à votre espace avec votre adresse institutionnelle.</p>
                    </div>
                    <FormField label="Adresse électronique" required>
                        <InputGroup icon={faEnvelope}>
                            <input
                                className="inp"
                                type="email"
                                autoComplete="username"
                                value={email}
                                onChange={(event) => setEmail(event.target.value)}
                                required
                                autoFocus
                            />
                        </InputGroup>
                    </FormField>
                    <FormField label="Mot de passe" required>
                        <div className="input-group has-icon">
                            <FontAwesomeIcon icon={faKey} className="input-icon" />
                            <input
                                className="inp"
                                type={visible ? 'text' : 'password'}
                                autoComplete="current-password"
                                value={password}
                                onChange={(event) => setPassword(event.target.value)}
                                style={{ paddingRight: 42 }}
                                required
                            />
                            <button type="button" className="input-clear" onClick={() => setVisible(!visible)} aria-label={visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'} aria-pressed={visible}>
                                <FontAwesomeIcon icon={visible ? faEyeSlash : faEye} />
                            </button>
                        </div>
                    </FormField>
                    {mfa && (
                        <>
                            {secret && (
                                <FormField label="Clé d’enrôlement" hint="Saisissez cette clé dans votre application d’authentification, puis le code à 6 chiffres.">
                                    <input className="inp" value={secret} readOnly />
                                </FormField>
                            )}
                            <FormField label="Code d’authentification" required>
                                <InputGroup icon={faShieldHalved}>
                                    <input
                                        className="inp"
                                        inputMode="numeric"
                                        autoComplete="one-time-code"
                                        value={code}
                                        onChange={(event) => setCode(event.target.value)}
                                        required
                                    />
                                </InputGroup>
                            </FormField>
                        </>
                    )}
                    {params.get('erreur') === 'sso' && <Alert tone="danger" title="Connexion institutionnelle refusée">Le fournisseur d’identité n’a pas reconnu un compte actif de BUDGET-CEEAC.</Alert>}
                    {error && <Alert tone="danger" title="Connexion refusée">{error}</Alert>}
                    <Button variant="brand" size="lg" type="submit" block icon={faLock} loading={pending} disabled={!email || !password || (mfa && code.trim().length < 6)}>
                        {pending ? 'Connexion…' : 'Se connecter'}
                    </Button>
                    {sso && <a className="btn btn-lg btn-block" href="/api/v1/auth/sso/rediriger">Connexion institutionnelle</a>}
                    <p className="login-footer"><FontAwesomeIcon icon={faShieldHalved} />Connexion sécurisée. Toute action est journalisée.</p>
                    <p className="login-footer"><a href="/accueil">Présentation</a> · <a href="/mot-de-passe-oublie">Mot de passe oublié</a></p>
                </form>
            </div>
        </main>
    );
}
