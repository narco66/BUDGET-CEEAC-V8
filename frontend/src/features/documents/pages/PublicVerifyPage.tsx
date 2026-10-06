import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import CeeacMark from '../../../components/brand/CeeacMark';
import { Alert, Badge, Button, ICON, KeyValueList, PageHeader, SectionCard, Skeleton } from '../../../components/ui';
import { dateHeure } from '../../../utils/format';

const TYPES: Record<string, string> = {
    expression_besoin: 'Expression de besoin',
    engagement: 'Engagement',
    liquidation: 'Liquidation',
    ordonnancement: 'Ordonnancement',
    paiement: 'Paiement',
};

type Resultat = {
    authentique: boolean;
    integre?: boolean;
    reference?: string;
    version?: number;
    emis_le?: string;
    type?: string;
    archivage?: string;
    message?: string;
};

/** Page publique : vérifie l’authenticité d’un acte à partir du code imprimé, sans accès au dossier. */
export default function PublicVerifyPage() {
    const { code } = useParams();
    const [resultat, setResultat] = useState<Resultat | null>(null);
    const [erreur, setErreur] = useState('');

    useEffect(() => {
        const base = import.meta.env.VITE_API_URL ?? '/api/v1';
        fetch(`${base}/public/documents/${encodeURIComponent(code ?? '')}`, { headers: { Accept: 'application/json' } })
            .then(async (response) => {
                const body = await response.json() as Resultat;
                if (!response.ok) {
                    setErreur(body.message ?? 'Aucun document officiel ne porte ce code.');
                    return;
                }
                setResultat(body);
            })
            .catch(() => setErreur('Vérification impossible.'));
    }, [code]);

    const courant = resultat?.archivage === 'courant';
    const enCours = !resultat && !erreur;

    return (
        <main className="verify-page">
            <header className="verify-bar">
                <CeeacMark size={44} />
                <div>
                    <strong>COMMISSION DE LA CEEAC</strong>
                    <small>BUDGET-CEEAC / GESBUDEP</small>
                </div>
            </header>
            <div className="app-content" style={{ maxWidth: 760, margin: '0 auto' }}>
                <PageHeader
                    eyebrow={<><span>Authenticité des actes</span><span className="mono">{code}</span></>}
                    title="Vérification du document"
                    subtitle="Ce contrôle confirme l’existence de la révision archivée. Il ne donne pas accès au contenu du dossier."
                />

                {enCours && (
                    <SectionCard>
                        <div className="stack-sm">
                            {[60, 90, 75, 85, 70].map((largeur) => <Skeleton key={largeur} width={`${largeur}%`} />)}
                        </div>
                    </SectionCard>
                )}

                {erreur && (
                    <Alert tone="danger" title="Document non reconnu">{erreur} Vérifiez le code imprimé en pied de page de l’acte.</Alert>
                )}

                {resultat?.authentique && (
                    <SectionCard
                        title={resultat.integre ? 'Document authentique' : 'Document connu, fichier archivé altéré'}
                        icon={resultat.integre ? ICON.success : ICON.danger}
                        tone={resultat.integre ? 'success' : 'danger'}
                        tag={<Badge tone={courant ? 'success' : 'warning'} size="sm">{courant ? 'Révision courante' : 'Révision remplacée'}</Badge>}
                    >
                        <div className="stack">
                            {!courant && <Alert tone="warning">Cette révision a été remplacée. La révision courante est une édition ultérieure.</Alert>}
                            {!resultat.integre && <Alert tone="danger">L’empreinte du fichier archivé ne correspond plus à celle enregistrée à l’émission.</Alert>}
                            <KeyValueList items={[
                                { label: 'Type d’acte', value: TYPES[resultat.type ?? ''] ?? resultat.type },
                                { label: 'Référence', value: resultat.reference, mono: true, strong: true },
                                { label: 'Révision', value: resultat.version },
                                { label: 'Émis le', value: dateHeure(resultat.emis_le) },
                                { label: 'Archive', value: courant ? 'Révision courante' : 'Révision remplacée' },
                                { label: 'Intégrité du fichier', value: resultat.integre ? 'Empreinte conforme' : 'Empreinte différente', warning: !resultat.integre },
                            ]} />
                        </div>
                    </SectionCard>
                )}

                <div>
                    <Button to="/connexion" icon={ICON.open}>Accéder à BUDGET-CEEAC</Button>
                </div>
            </div>
        </main>
    );
}
