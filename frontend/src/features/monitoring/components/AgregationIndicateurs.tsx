import { useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import { Badge, DataTable, EmptyState, ErrorMessage, ICON, SectionCard, type Column } from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

type Groupe = {
    cle: string;
    methode: string;
    unite: string;
    agrege: boolean;
    valeur: number | null;
    formule: string;
    indicateurs: { id: number; code: string; label: string }[];
};

const METHODES: Record<string, string> = {
    sum: 'Somme',
    average: 'Moyenne',
    weighted_average: 'Moyenne pondérée',
    min: 'Minimum',
    max: 'Maximum',
    last_value: 'Dernière valeur',
    custom_formula: 'Formule',
    non_aggregatable: 'Non agrégeable',
};

/** Agrégation des dernières valeurs validées, par méthode et unité compatibles. */
export default function AgregationIndicateurs() {
    const [groupes, setGroupes] = useState<Groupe[] | null>(null);
    const [error, setError] = useState('');

    useEffect(() => {
        api.get('/suivi/indicateurs/agregation').then((response) => setGroupes(response.data.data ?? [])).catch((caught) => setError(errorsOf(caught)));
    }, []);

    const colonnes: Column<Groupe>[] = [
        { key: 'cle', header: 'Groupe', render: (row) => <span className="strong">{row.cle}</span> },
        { key: 'methode', header: 'Méthode', render: (row) => METHODES[row.methode] ?? row.methode },
        {
            key: 'valeur',
            header: 'Valeur',
            align: 'right',
            className: 'num',
            render: (row) => (row.valeur === null || row.valeur === undefined ? '—' : `${row.valeur}${row.unite ? ` ${row.unite}` : ''}`),
        },
        { key: 'agrege', header: 'Nature', render: (row) => (row.agrege ? <Badge tone="info" size="sm">Agrégé</Badge> : <Badge tone="neutral" size="sm">Valeur propre</Badge>) },
        { key: 'indicateurs', header: 'Indicateurs', render: (row) => <span className="cell-sub">{row.indicateurs.map((indicateur) => indicateur.code).join(', ')}</span> },
        { key: 'formule', header: 'Formule', render: (row) => <span className="cell-sub">{row.formule}</span> },
    ];

    return (
        <SectionCard title="Agrégation des indicateurs" icon={ICON.report} subtitle="Dernières valeurs validées ou consolidées, regroupées par méthode et unité compatibles." flush>
            <ErrorMessage error={error} onClose={() => setError('')} />
            <DataTable
                columns={colonnes}
                rows={groupes ?? []}
                rowKey={(row) => `${row.methode}-${row.cle}`}
                loading={groupes === null && !error}
                compact
                empty={<EmptyState icon={ICON.report} title="Aucune valeur validée à agréger" compact />}
            />
        </SectionCard>
    );
}
