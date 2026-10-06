import { faCircleCheck, faFileShield, faQrcode, faShieldHalved, faTriangleExclamation } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { FormEvent, useState } from 'react';
import api from '../../../api/httpClient';
import { Alert, Button, ErrorMessage, FormField, InputGroup, KeyValueList, PageHeader, SectionCard } from '../../../components/ui';

export default function VerifyDocumentPage() {
    const [code, setCode] = useState('');
    const [result, setResult] = useState<any>(null);
    const [error, setError] = useState('');
    const [pending, setPending] = useState(false);

    async function verify(event: FormEvent) {
        event.preventDefault();
        setError('');
        setResult(null);
        setPending(true);
        try {
            const response = await api.get(`/documents/verifier/${encodeURIComponent(code.trim())}`);
            setResult(response.data);
        } catch (caught: any) {
            setError(caught?.response?.data?.message ?? 'Vérification impossible.');
        } finally {
            setPending(false);
        }
    }

    const document = result?.document;

    return (
        <main className="app-content" style={{ maxWidth: 920 }}>
            <PageHeader
                eyebrow={<><FontAwesomeIcon icon={faShieldHalved} /> Authenticité des actes</>}
                title="Vérifier un document officiel"
                subtitle="Saisissez le code de vérification imprimé en pied de page de l’acte. Le contrôle vérifie l’intégrité du fichier archivé à partir de son empreinte SHA-256."
            />

            <SectionCard>
                <form onSubmit={verify} className="cluster" style={{ alignItems: 'flex-end' }}>
                    <FormField label="Code de vérification" required style={{ flex: '1 1 320px' }}>
                        <InputGroup icon={faQrcode}>
                            <input className="inp mono" value={code} onChange={(event) => setCode(event.target.value)} autoComplete="off" spellCheck={false} />
                        </InputGroup>
                    </FormField>
                    <Button variant="primary" type="submit" icon={faFileShield} disabled={!code.trim()} loading={pending}>Vérifier</Button>
                </form>
            </SectionCard>

            <ErrorMessage error={error} title="Document non vérifié" />

            {document && (
                <SectionCard
                    tone={result.integre ? 'success' : 'danger'}
                    icon={result.integre ? faCircleCheck : faTriangleExclamation}
                    title={result.integre ? 'Document authentique et intègre' : 'Document connu mais fichier archivé altéré'}
                    subtitle={`${document.type} · version ${document.version}`}
                >
                    {!result.version_courante && <Alert tone="warning">Une version plus récente de cet acte existe.</Alert>}
                    <KeyValueList items={[
                        { label: 'Type', value: document.type },
                        { label: 'Référence', value: document.reference, mono: true },
                        { label: 'Version', value: document.version },
                        { label: 'Événement', value: document.evenement },
                        { label: 'Généré le', value: document.genere_le },
                        { label: 'Généré par', value: document.genere_par, hidden: !document.genere_par },
                        { label: 'Empreinte SHA-256', value: <span style={{ fontSize: 'var(--text-xs)', wordBreak: 'break-all' }}>{document.sha256}</span>, mono: true },
                    ]} />
                </SectionCard>
            )}
        </main>
    );
}
