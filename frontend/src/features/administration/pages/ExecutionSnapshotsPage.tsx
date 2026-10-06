import { useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import { DataTable, EmptyState, ErrorMessage, ICON, PageHeader, SectionCard, StatCard } from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

type Volumes = {
    expressions: number;
    engagements: number;
    liquidations: number;
    ordonnancements: number;
    paiements: number;
};

type Instantane = {
    id: number;
    genere_le: string;
    volumes: Volumes | null;
};

const VOLUMES: { key: keyof Volumes; label: string }[] = [
    { key: 'expressions', label: 'Expressions de besoin' },
    { key: 'engagements', label: 'Engagements' },
    { key: 'liquidations', label: 'Liquidations' },
    { key: 'ordonnancements', label: 'Ordonnancements' },
    { key: 'paiements', label: 'Paiements' },
];

function nombre(value: number): string {
    return new Intl.NumberFormat('fr-FR').format(value);
}

function quand(value: string): string {
    const date = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long', timeStyle: 'short' }).format(date);
}

export default function ExecutionSnapshotsPage() {
    const [rows, setRows] = useState<Instantane[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        api.get('/rapports/execution')
            .then((response) => setRows(response.data.data))
            .catch((caught) => setError(errorsOf(caught)))
            .finally(() => setLoading(false));
    }, []);

    const dernier = rows.find((row) => row.volumes !== null) ?? null;

    return (
        <main className="app-content">
            <PageHeader
                eyebrow="Reporting"
                title="Instantanés d’exécution"
                subtitle="Volumes de la chaîne photographiés chaque jour à 7 h 40. Ce relevé ne remplace pas les dossiers."
            />
            <ErrorMessage error={error} title="Instantanés indisponibles" />
            {dernier?.volumes && (
                <div className="grid-kpi">
                    {VOLUMES.map((item) => (
                        <StatCard key={item.key} label={item.label} value={nombre(dernier.volumes![item.key])} icon={ICON.report} hint={quand(dernier.genere_le)} />
                    ))}
                </div>
            )}
            <SectionCard title="Historique" icon={ICON.archive} flush>
                <DataTable
                    columns={[
                        { key: 'genere_le', header: 'Produit le', render: (row: Instantane) => quand(row.genere_le) },
                        ...VOLUMES.map((item) => ({
                            key: item.key,
                            header: item.label,
                            align: 'right' as const,
                            render: (row: Instantane) => (row.volumes ? nombre(row.volumes[item.key]) : '—'),
                        })),
                    ]}
                    rows={rows}
                    rowKey={(row: Instantane) => row.id}
                    loading={loading}
                    empty={<EmptyState icon={ICON.report} title="Aucun instantané" compact>Le premier relevé est produit à 7 h 40.</EmptyState>}
                />
            </SectionCard>
        </main>
    );
}
