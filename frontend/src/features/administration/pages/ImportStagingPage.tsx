import { FormEvent, useEffect, useRef, useState } from 'react';
import api from '../../../api/httpClient';
import { Alert, Badge, Button, DataTable, EmptyState, ErrorMessage, FormField, ICON, PageHeader, SectionCard } from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

export default function ImportStagingPage() {
    const [fichier, setFichier] = useState('import.csv');
    const [contenu, setContenu] = useState('code,montant\n');
    const [lot, setLot] = useState<any>(null);
    const [lots, setLots] = useState<any[]>([]);
    const [pending, setPending] = useState(false);
    const [error, setError] = useState('');
    const demande = useRef(0);

    function charger() {
        const ticket = ++demande.current;
        api.get('/imports/preparation').then((response) => {
            if (ticket === demande.current) {
                setLots(response.data.data ?? []);
            }
        }).catch(() => {
            if (ticket === demande.current) {
                setLots([]);
            }
        });
    }

    useEffect(() => {
        charger();
    }, []);

    async function preparer(event: FormEvent) {
        event.preventDefault();
        setPending(true);
        setError('');
        try {
            const response = await api.post('/imports/preparation', { fichier, contenu });
            setLot(response.data.data);
            charger();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    const historique = lots.flatMap((batch) => (batch.lignes ?? []).map((row: any) => ({ ...row, reference: batch.reference, fichier: batch.fichier })));

    return (
        <main className="app-content">
            <PageHeader
                eyebrow="Intégration"
                title="Zone de préparation des imports"
                subtitle="Le fichier est contrôlé avant toute écriture. Une ligne officielle ou déjà présente n’est pas modifiée, et une ligne inconnue n’est pas créée."
            />
            <SectionCard title="Fichier" icon={ICON.document}>
                <form className="stack" onSubmit={preparer}>
                    <FormField label="Nom du fichier" required>
                        <input className="inp" value={fichier} onChange={(event) => setFichier(event.target.value)} required />
                    </FormField>
                    <FormField label="Contenu CSV" required hint="Colonnes code et montant. La première ligne d’en-tête est ignorée.">
                        <textarea className="inp mono" rows={8} value={contenu} onChange={(event) => setContenu(event.target.value)} required />
                    </FormField>
                    <div className="form-actions">
                        <Button variant="primary" type="submit" icon={ICON.validate} loading={pending}>Contrôler</Button>
                    </div>
                </form>
            </SectionCard>
            <ErrorMessage error={error} title="Contrôle refusé" />
            {lot && (
                <SectionCard title={lot.reference} icon={ICON.budget} subtitle="Résultat du contrôle préalable" flush>
                    <Alert tone="info">Aucune ligne de ce lot n’a été intégrée au budget voté.</Alert>
                    <Resultat lignes={lot.lignes} />
                </SectionCard>
            )}
            <SectionCard title="Lots déjà contrôlés" icon={ICON.archive} subtitle="La zone conserve le verdict. Elle ne devient jamais le budget voté." flush>
                <DataTable
                    columns={[
                        { key: 'reference', header: 'Lot', render: (row: any) => <span className="mono">{row.reference}</span> },
                        { key: 'code', header: 'Code', render: (row: any) => <span className="mono">{row.code}</span> },
                        { key: 'verdict', header: 'Verdict', render: (row: any) => <Badge tone={row.verdict === 'bloque' ? 'danger' : 'warning'} size="sm">{row.verdict}</Badge> },
                        { key: 'message', header: 'Contrôle', render: (row: any) => row.message },
                    ]}
                    rows={historique}
                    rowKey={(row: any) => `${row.reference}-${row.ligne}`}
                    empty={<EmptyState icon={ICON.document} title="Aucun lot contrôlé" compact />}
                />
            </SectionCard>
        </main>
    );
}

function Resultat({ lignes }: { lignes: any[] }) {
    return (
        <DataTable
            columns={[
                { key: 'code', header: 'Code', render: (row: any) => <span className="mono">{row.code}</span> },
                { key: 'verdict', header: 'Verdict', render: (row: any) => <Badge tone={row.verdict === 'bloque' ? 'danger' : 'warning'} size="sm">{row.verdict}</Badge> },
                { key: 'message', header: 'Contrôle', render: (row: any) => row.message },
            ]}
            rows={lignes}
            rowKey={(row: any) => row.ligne}
            empty={<EmptyState icon={ICON.document} title="Fichier vide" compact />}
        />
    );
}
