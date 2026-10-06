import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, ICON, PageHeader, SectionCard, StatCard } from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';
import { PreparationNav } from '../preparation/shell';

function Tableau({ titre, rows }: { titre: string; rows: any[] }) {
    return (
        <SectionCard title={titre} icon={ICON.report} flush>
            <DataTable columns={[
                { key: 'cle', header: 'Regroupement', render: (row) => row.cle || '—' },
                { key: 'montant', header: 'Retenu', align: 'right', className: 'mono', render: (row) => fcfa(row.montant) },
            ]} rows={rows} rowKey={(row) => row.cle || titre} empty={<EmptyState icon={ICON.report} title="Aucune ligne retenue" compact />} />
        </SectionCard>
    );
}

export default function ConsolidationPage() {
    const { id } = useParams();
    const [portrait, setPortrait] = useState<any>(null);
    const [error, setError] = useState('');

    useEffect(() => {
        api.get(`/preparation/campagnes/${id}/consolidation`).then((response) => setPortrait(response.data)).catch((caught) => setError(errorsOf(caught)));
    }, [id]);

    return (
        <main className="app-content">
            <PageHeader title="Consolidation" subtitle="Agrégats calculés depuis les propositions retenues, sans saisie manuelle." actions={<Button to={`/preparation/campagnes/${id}`} icon={ICON.back}>Campagne</Button>} />
            <PreparationNav />
            <ErrorMessage error={error} onClose={() => setError('')} />
            {portrait && (
                <div className="grid-kpi">
                    <StatCard label="Dépenses retenues" value={fcfa(portrait.depenses)} icon={ICON.budget} />
                    <StatCard label="Recettes validées" value={fcfa(portrait.recettes)} icon={ICON.revenue} />
                    <StatCard label="Plafonds feuilles" value={fcfa(portrait.plafonds)} icon={ICON.calendar} />
                    <StatCard label="Équilibre" value={fcfa(portrait.equilibre)} icon={ICON.report} tone={portrait.equilibre < 0 ? 'danger' : 'success'} />
                </div>
            )}
            <Tableau titre="Par structure" rows={portrait?.par_structure ?? []} />
            <Tableau titre="Par imputation" rows={portrait?.par_imputation ?? []} />
            <Tableau titre="Fonctionnement et investissement" rows={portrait?.par_classification ?? []} />
            <Tableau titre="Par activité" rows={portrait?.par_activite ?? []} />
            <Tableau titre="Par financement" rows={portrait?.par_financement ?? []} />
            <Tableau titre="Par période" rows={portrait?.par_periode ?? []} />
        </main>
    );
}
