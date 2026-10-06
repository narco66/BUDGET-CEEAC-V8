import { Link } from 'react-router-dom';
import api from '../../../api/httpClient';
import { DataTable, EmptyState, ErrorMessage, ICON, KeyValueList, PageError, PageHeader, PageSkeleton, ProgressBar, SectionCard, StatCard, type Column } from '../../../components/ui';
import { dateFr, fcfa } from '../../../utils/format';
import useResource from '../../../utils/useResource';

type Mois = { mois: number; libelle: string; ordonnance: number; decaisse: number; ecart: number };
type Decaissement = { paiement_id: number; reference?: string; reglement?: string; date?: string | null; montant: number };

export default function TresoreriePage() {
    const { data, error, reload } = useResource(() => api.get('/chaine/tresorerie').then((response) => response.data.data), []);

    if (!data) {
        return error ? <PageError message={error} onRetry={reload} /> : <PageSkeleton />;
    }

    const mois: Mois[] = data.mois ?? [];
    const decaissements: Decaissement[] = data.decaissements ?? [];
    const taux = data.total_ordonnance > 0 ? (data.total_decaisse / data.total_ordonnance) * 100 : null;

    const colonnesMois: Column<Mois>[] = [
        { key: 'mois', header: 'Mois', render: (ligne) => <span className="strong">{ligne.libelle}</span> },
        { key: 'ordonnance', header: 'Ordonnancé signé', align: 'right', className: 'cell-amount', render: (ligne) => fcfa(ligne.ordonnance) },
        { key: 'decaisse', header: 'Décaissé', align: 'right', className: 'cell-amount', render: (ligne) => fcfa(ligne.decaisse) },
        {
            key: 'ecart',
            header: 'Écart',
            align: 'right',
            className: 'cell-amount',
            render: (ligne) => <span className={ligne.ecart > 0 ? 'text-warning' : undefined}>{fcfa(ligne.ecart)}</span>,
        },
        {
            key: 'taux',
            header: 'Décaissé / signé',
            width: 180,
            render: (ligne) => ligne.ordonnance > 0
                ? <ProgressBar value={(ligne.decaisse / ligne.ordonnance) * 100} label={`Taux de décaissement ${ligne.libelle}`} size="thin" />
                : <span className="subtle">—</span>,
        },
    ];

    const colonnesDecaissements: Column<Decaissement>[] = [
        { key: 'reference', header: 'Paiement', render: (ligne) => <Link className="cell-ref" to={`/paiements/${ligne.paiement_id}`}>{ligne.reference}</Link> },
        { key: 'reglement', header: 'Référence de règlement', render: (ligne) => <span className="mono">{ligne.reglement || '—'}</span> },
        { key: 'date', header: 'Date de valeur', render: (ligne) => (ligne.date ? dateFr(ligne.date) : <span className="subtle">sans date</span>) },
        { key: 'montant', header: 'Montant (FCFA)', align: 'right', className: 'cell-amount', render: (ligne) => fcfa(ligne.montant) },
    ];

    return (
        <main className="app-content">
            <PageHeader eyebrow="Pilotage de la chaîne" title={`Trésorerie réalisée ${data.exercice}`} subtitle={data.precision} />
            <ErrorMessage error={error} />

            <div className="grid-kpi">
                <StatCard label="Ordonnancé signé" value={fcfa(data.total_ordonnance)} unit="FCFA" icon={ICON.order} />
                <StatCard label="Décaissé exécuté" value={fcfa(data.total_decaisse)} unit="FCFA" icon={ICON.payment} tone="success" hint={taux === null ? undefined : `${Math.round(taux * 10) / 10} % de l’ordonnancé signé`} />
                <StatCard label="Écart" value={fcfa(data.ecart)} unit="FCFA" icon={ICON.pending} tone={data.ecart > 0 ? 'orange' : 'neutral'} hint="Signé, non encore décaissé" />
            </div>

            <SectionCard title="Mois de l’exercice" icon={ICON.calendar} subtitle="Ordres signés et décaissements exécutés, à leur date." flush>
                <DataTable columns={colonnesMois} rows={mois} rowKey={(ligne) => ligne.mois} compact caption="Trésorerie par mois" />
            </SectionCard>

            <div className="grid-2">
                <SectionCard title="Hors des mois de l’exercice" icon={ICON.info}>
                    <KeyValueList items={[
                        { label: 'Ordonnancements hors année', value: `${fcfa(data.hors_exercice?.ordonnance)} FCFA` },
                        { label: 'Décaissements hors année', value: `${fcfa(data.hors_exercice?.decaisse)} FCFA` },
                        { label: 'Décaissements sans date de valeur', value: `${fcfa(data.sans_date)} FCFA` },
                        { label: 'Rejets bancaires, non décaissés', value: `${fcfa(data.rejets)} FCFA` },
                    ]} />
                </SectionCard>
                <SectionCard title="Décaissements exécutés" icon={ICON.payment} subtitle={`${decaissements.length} règlement(s)`} flush>
                    <DataTable
                        columns={colonnesDecaissements}
                        rows={decaissements}
                        rowKey={(ligne) => `${ligne.paiement_id}-${ligne.reglement}`}
                        compact
                        empty={<EmptyState icon={ICON.payment} title="Aucun décaissement" compact>Les règlements exécutés apparaîtront ici.</EmptyState>}
                    />
                </SectionCard>
            </div>
        </main>
    );
}
