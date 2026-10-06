import { Link } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Badge, DataTable, EmptyState, ErrorMessage, ICON, Meter, PageError, PageHeader, PageSkeleton, SectionCard, StatCard, type Column } from '../../../components/ui';
import { dateFr, fcfa } from '../../../utils/format';
import useResource from '../../../utils/useResource';

type Tranche = { code: string; libelle: string; nombre: number; reste: number };
type Obligation = {
    ordonnancement_id: number;
    reference: string;
    signe_le?: string | null;
    jours?: number | null;
    reste: number;
    qualifie: boolean;
};

export default function ObligationsPage() {
    const { data, error, reload } = useResource(() => api.get('/chaine/obligations').then((response) => response.data.data), []);

    if (!data) {
        return error ? <PageError message={error} onRetry={reload} /> : <PageSkeleton />;
    }

    const tranches: Tranche[] = data.tranches ?? [];
    const obligations: Obligation[] = data.obligations ?? [];
    const total = Number(data.reste) || 0;

    const colonnes: Column<Obligation>[] = [
        { key: 'reference', header: 'Ordonnancement', render: (ligne) => <Link className="cell-ref" to={`/ordonnancements/${ligne.ordonnancement_id}`}>{ligne.reference}</Link> },
        { key: 'signe', header: 'Signé le', render: (ligne) => (ligne.signe_le ? dateFr(ligne.signe_le) : <span className="subtle">sans date</span>) },
        { key: 'jours', header: 'Ancienneté', align: 'right', render: (ligne) => (ligne.jours == null ? '—' : `${ligne.jours} j`) },
        { key: 'reste', header: 'Reste à payer (FCFA)', align: 'right', className: 'cell-amount', render: (ligne) => fcfa(ligne.reste) },
        { key: 'qualifie', header: 'Qualification', render: (ligne) => (ligne.qualifie ? <Badge tone="danger" icon={ICON.warning} size="sm">Arriéré</Badge> : <Badge tone="neutral" size="sm">Obligation courante</Badge>) },
    ];

    return (
        <main className="app-content">
            <PageHeader eyebrow="Pilotage de la chaîne" title={`Restes à payer ${data.exercice}`} subtitle={data.precision} />
            <ErrorMessage error={error} />

            <div className="grid-kpi">
                <StatCard label="Obligations ouvertes" value={data.nombre} icon={ICON.order} hint="Ordres signés non soldés" />
                <StatCard label="Reste à payer" value={fcfa(data.reste)} unit="FCFA" icon={ICON.pending} tone={total > 0 ? 'orange' : 'neutral'} />
                <StatCard label="Qualifiées arriéré" value={data.qualifiees} icon={ICON.warning} tone={data.qualifiees > 0 ? 'danger' : 'neutral'} hint={`${fcfa(data.montant_qualifie)} FCFA`} />
            </div>

            <div className="grid-2">
                <SectionCard title="Ancienneté depuis la signature" icon={ICON.clock} subtitle="Ces tranches ne fixent pas un seuil d’arriéré.">
                    <div className="stack">
                        {tranches.map((tranche) => (
                            <Meter
                                key={tranche.code}
                                label={tranche.libelle}
                                value={`${fcfa(tranche.reste)} FCFA`}
                                ratio={total > 0 ? (tranche.reste / total) * 100 : 0}
                                hint={`${tranche.nombre} ordre(s)`}
                            />
                        ))}
                    </div>
                </SectionCard>
                <SectionCard title="Ordonnancements non soldés" icon={ICON.order} subtitle={`${obligations.length} ordre(s)`} flush>
                    <DataTable
                        columns={colonnes}
                        rows={obligations}
                        rowKey={(ligne) => ligne.ordonnancement_id}
                        compact
                        rowClassName={(ligne) => (ligne.qualifie ? 'is-danger' : undefined)}
                        empty={<EmptyState icon={ICON.success} title="Aucun reste à payer" compact>Tous les ordres signés sont soldés.</EmptyState>}
                    />
                </SectionCard>
            </div>
        </main>
    );
}
