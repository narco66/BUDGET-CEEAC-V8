import { FormEvent, useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import { Badge, Button, DataTable, EmptyState, ErrorMessage, FormField, ICON, PageHeader, SectionCard } from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

const GRAVITE: Record<string, 'danger' | 'warning' | 'neutral'> = {
    majeur: 'danger',
    mineur: 'warning',
};

type Anomalie = {
    code: string;
    module: string;
    sujet: string;
    gravite: string;
    detail: string;
    statut: string;
    origine: string;
    motif_cloture?: string | null;
    peut_cloturer: boolean;
};

export default function AnomaliesPage() {
    const [rows, setRows] = useState<Anomalie[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [reference, setReference] = useState('');
    const [gravite, setGravite] = useState('mineur');
    const [constat, setConstat] = useState('');
    const [motifs, setMotifs] = useState<Record<string, string>>({});

    function charger() {
        setLoading(true);
        api.get('/controles/anomalies')
            .then((response) => setRows(response.data.data))
            .catch((caught) => setError(errorsOf(caught)))
            .finally(() => setLoading(false));
    }

    useEffect(() => {
        charger();
    }, []);

    async function ouvrir(event: FormEvent) {
        event.preventDefault();
        setError('');
        try {
            await api.post('/controles/anomalies', { reference, gravite, constat });
            setReference('');
            setConstat('');
            charger();
        } catch (caught) {
            setError(errorsOf(caught));
        }
    }

    async function cloturer(code: string) {
        setError('');
        try {
            await api.post('/controles/anomalies/cloturer', { code, motif: motifs[code] ?? '' });
            charger();
        } catch (caught) {
            setError(errorsOf(caught));
        }
    }

    return (
        <main className="app-content">
            <PageHeader
                eyebrow="Contrôle interne"
                title="Registre d’anomalies"
                subtitle="Une clôture enregistre un motif. Elle ne réécrit pas le budget et ne supprime pas le constat."
            />
            <ErrorMessage error={error} title="Registre indisponible" onClose={() => setError('')} />
            <SectionCard title="Ouvrir une anomalie" icon={ICON.warning}>
                <form onSubmit={ouvrir} className="stack">
                    <FormField label="Référence de la pièce" required>
                        <input className="inp" value={reference} onChange={(event) => setReference(event.target.value)} placeholder="EB, ENG, LIQ, ORD ou PAY" />
                    </FormField>
                    <FormField label="Gravité" required>
                        <select className="inp" value={gravite} onChange={(event) => setGravite(event.target.value)}>
                            <option value="mineur">Mineur</option>
                            <option value="majeur">Majeur</option>
                        </select>
                    </FormField>
                    <FormField label="Constat" required>
                        <textarea className="inp" value={constat} onChange={(event) => setConstat(event.target.value)} rows={3} />
                    </FormField>
                    <Button type="submit">Ouvrir l’anomalie</Button>
                </form>
            </SectionCard>
            <SectionCard title="Anomalies" icon={ICON.warning} flush>
                <DataTable
                    columns={[
                        { key: 'code', header: 'Code', render: (row: Anomalie) => <span className="mono">{row.code}</span> },
                        { key: 'sujet', header: 'Pièce', render: (row: Anomalie) => row.sujet },
                        { key: 'gravite', header: 'Gravité', render: (row: Anomalie) => <Badge tone={GRAVITE[row.gravite] ?? 'neutral'} size="sm">{row.gravite}</Badge> },
                        { key: 'detail', header: 'Constat', render: (row: Anomalie) => row.detail },
                        { key: 'statut', header: 'Statut', render: (row: Anomalie) => row.statut === 'clos' ? `clos — ${row.motif_cloture ?? ''}` : row.statut },
                        {
                            key: 'suite',
                            header: 'Suite',
                            render: (row: Anomalie) => row.peut_cloturer ? (
                                <form onSubmit={(event) => { event.preventDefault(); cloturer(row.code); }} className="cluster" style={{ flexWrap: 'nowrap' }}>
                                    <input className="inp inp-sm" aria-label={`Motif de clôture ${row.code}`} value={motifs[row.code] ?? ''} onChange={(event) => setMotifs((actuel) => ({ ...actuel, [row.code]: event.target.value }))} placeholder="Motif de clôture" />
                                    <Button type="submit" size="sm" icon={ICON.check}>Clôturer</Button>
                                </form>
                            ) : null,
                        },
                    ]}
                    rows={rows}
                    rowKey={(row: Anomalie) => row.code}
                    loading={loading}
                    empty={<EmptyState icon={ICON.success} title="Aucune anomalie" compact />}
                />
            </SectionCard>
        </main>
    );
}
