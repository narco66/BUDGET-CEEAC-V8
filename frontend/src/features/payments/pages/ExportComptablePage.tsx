import { useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Alert, Button, DataTable, EmptyState, ErrorMessage, ICON, PageError, PageHeader, PageSkeleton, SectionCard, StatCard, type Column } from '../../../components/ui';
import { telecharger } from '../../../utils/download';
import { dateFr, errorsOf, fcfa } from '../../../utils/format';
import useResource from '../../../utils/useResource';

type Evenement = { evenement: string; libelle: string; nombre: number; montant: number };
type Rejet = {
    evenement: string;
    libelle: string;
    reference: string;
    montant: number;
    motif: string;
    date?: string | null;
    cible?: string | null;
    cible_id?: number | null;
};

function lien(rejet: Rejet): string | null {
    if (rejet.cible_id == null) {
        return null;
    }
    if (rejet.cible === 'paiement') {
        return `/paiements/${rejet.cible_id}`;
    }
    if (rejet.cible === 'engagement') {
        return `/engagements/${rejet.cible_id}`;
    }
    if (rejet.cible === 'liquidation') {
        return `/liquidations/${rejet.cible_id}`;
    }

    return null;
}

export default function ExportComptablePage() {
    const { data, error, reload } = useResource(() => api.get('/chaine/comptabilite').then((response) => response.data.data), []);
    const [exportError, setExportError] = useState('');
    const [export_, setExport] = useState(false);

    if (!data) {
        return error ? <PageError message={error} onRetry={reload} /> : <PageSkeleton />;
    }

    async function exporter() {
        setExportError('');
        setExport(true);
        try {
            await telecharger('/chaine/comptabilite/export', `rejets-comptables-${data.exercice}.csv`);
        } catch (caught) {
            setExportError(errorsOf(caught));
        } finally {
            setExport(false);
        }
    }

    const evenements: Evenement[] = data.par_evenement ?? [];
    const rejets: Rejet[] = data.rejets ?? [];

    const colonnesEvenements: Column<Evenement>[] = [
        { key: 'libelle', header: 'Événement', render: (ligne) => ligne.libelle },
        { key: 'nombre', header: 'Messages', align: 'right', render: (ligne) => <span className="num">{ligne.nombre}</span> },
        { key: 'montant', header: 'Montant (FCFA)', align: 'right', className: 'cell-amount', render: (ligne) => fcfa(ligne.montant) },
    ];

    const colonnesRejets: Column<Rejet>[] = [
        {
            key: 'reference',
            header: 'Dossier',
            render: (rejet) => {
                const href = lien(rejet);
                return href ? <Link className="cell-ref" to={href}>{rejet.reference}</Link> : <span className="mono">{rejet.reference}</span>;
            },
        },
        { key: 'evenement', header: 'Événement', render: (rejet) => rejet.libelle },
        { key: 'date', header: 'Date', render: (rejet) => (rejet.date ? dateFr(rejet.date) : <span className="subtle">sans date</span>) },
        { key: 'montant', header: 'Montant (FCFA)', align: 'right', className: 'cell-amount', render: (rejet) => fcfa(rejet.montant) },
        { key: 'motif', header: 'Motif du rejet', render: (rejet) => <span className="cell-sub">{rejet.motif}</span> },
    ];

    return (
        <main className="app-content">
            <PageHeader
                eyebrow="Pilotage de la chaîne"
                title={`Export comptable ${data.exercice}`}
                subtitle={data.precision}
                actions={<Button icon={ICON.export} onClick={exporter} loading={export_} disabled={rejets.length === 0}>Exporter les rejets</Button>}
            />
            <ErrorMessage error={error || exportError} onClose={() => setExportError('')} />

            {!data.schema_enregistre && (
                <Alert tone="warning" title="Aucun schéma comptable enregistré">
                    Tant qu’aucun schéma n’est enregistré, les messages d’interface restent en attente et ne deviennent pas des écritures.
                </Alert>
            )}

            <div className="grid-kpi">
                <StatCard label="Schéma comptable" value={data.schema_enregistre ? 'Enregistré' : 'Absent'} icon={ICON.settings} tone={data.schema_enregistre ? 'success' : 'warning'} />
                <StatCard label="Écritures produites" value={(data.ecritures ?? []).length} icon={ICON.document} hint={`Débit ${fcfa(data.debit)} · crédit ${fcfa(data.credit)} FCFA`} />
                <StatCard label="Messages rejetés" value={data.nombre_rejets} icon={ICON.danger} tone={data.nombre_rejets > 0 ? 'danger' : 'neutral'} />
            </div>

            <SectionCard title="Messages par événement" icon={ICON.history} flush>
                <DataTable columns={colonnesEvenements} rows={evenements} rowKey={(ligne) => ligne.evenement} compact empty={<EmptyState icon={ICON.history} title="Aucun message" compact />} />
            </SectionCard>

            <SectionCard title="File de rejets" icon={ICON.danger} subtitle={`${rejets.length} message(s) en attente d’un schéma ou d’un compte`} flush>
                <DataTable
                    columns={colonnesRejets}
                    rows={rejets}
                    rowKey={(rejet) => `${rejet.evenement}-${rejet.cible}-${rejet.cible_id}-${rejet.reference}`}
                    compact
                    empty={<EmptyState icon={ICON.success} title="Aucun rejet" compact>Tous les messages ont produit une écriture.</EmptyState>}
                />
            </SectionCard>
        </main>
    );
}
