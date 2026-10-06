import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, ICON, PageHeader, SectionCard, StatCard, useDialogs, useToast, type Column } from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';
import { PreparationNav, StatutPrep } from '../preparation/shell';

export default function PreparationPage() {
    const toast = useToast();
    const { confirm } = useDialogs();
    const [portrait, setPortrait] = useState<any>(null);
    const [campagnes, setCampagnes] = useState<any>(null);
    const [error, setError] = useState('');
    const [pending, setPending] = useState<string | null>(null);

    function load() {
        api.get('/preparation').then((response) => setPortrait(response.data)).catch((caught) => setError(errorsOf(caught)));
        api.get('/preparation/campagnes', { params: { per_page: 5 } }).then((response) => setCampagnes(response.data)).catch(() => setCampagnes(null));
    }

    useEffect(() => { load(); }, []);

    async function executer(key: string, request: () => Promise<unknown>, success: string) {
        setPending(key);
        setError('');
        try {
            await request();
            toast.success(success);
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    const columns: Column<any>[] = [
        { key: 'code', header: 'Code', className: 'mono', render: (row) => row.code },
        { key: 'libelle', header: 'Libellé', render: (row) => row.libelle },
        { key: 'structure', header: 'Structure', render: (row) => row.structure },
        { key: 'montant', header: 'Proposé', align: 'right', className: 'mono', render: (row) => fcfa(row.montant) },
        { key: 'statut', header: 'Statut', render: (row) => <StatutPrep valeur={row.statut} /> },
        {
            key: 'actions',
            header: 'Actions',
            render: (row) => portrait?.droits?.editer && row.statut === 'brouillon'
                ? <Button size="sm" loading={pending === `s${row.id}`} onClick={() => executer(`s${row.id}`, () => api.post(`/preparation/propositions/${row.id}/soumettre`), 'Proposition soumise.')}>Soumettre</Button>
                : portrait?.droits?.adopter && row.statut === 'soumis'
                    ? <Button size="sm" variant="primary" loading={pending === `r${row.id}`} onClick={() => executer(`r${row.id}`, () => api.post(`/preparation/propositions/${row.id}/retenir`, { retenir: true }), 'Proposition retenue.')}>Retenir</Button>
                    : null,
        },
    ];

    return (
        <main className="app-content">
            <PageHeader
                title="Préparation budgétaire"
                subtitle="Campagne, cadrage, propositions, arbitrage et adoption. Les crédits exécutables naissent seulement à la transmission."
                actions={<Button variant="primary" icon={ICON.create} to="/preparation/campagnes/nouvelle">Nouvelle campagne</Button>}
            />
            <PreparationNav />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <div className="grid-kpi">
                <StatCard label="Exercice en préparation" value={portrait?.exercice?.annee ?? '—'} icon={ICON.budget} />
                <StatCard label="Campagnes" value={campagnes?.meta?.total ?? 0} icon={ICON.calendar} />
                <StatCard label="Propositions reprises" value={portrait?.propositions?.length ?? 0} icon={ICON.inbox} hint="Ancien report de l’exercice exécutoire" />
            </div>
            <SectionCard title="Campagnes récentes" icon={ICON.budget} actions={<Button size="sm" to="/preparation/campagnes">Toutes</Button>} flush>
                <DataTable
                    columns={[
                        { key: 'code', header: 'Code', render: (row) => <Link className="cell-ref" to={`/preparation/campagnes/${row.id}`}>{row.code}</Link> },
                        { key: 'libelle', header: 'Intitulé', render: (row) => row.libelle },
                        { key: 'exercice', header: 'Exercice', render: (row) => row.exercice },
                        { key: 'dossiers', header: 'Dossiers', align: 'right', render: (row) => row.dossiers },
                        { key: 'statut', header: 'Statut', render: (row) => <StatutPrep valeur={row.statut} /> },
                    ] as Column<any>[]}
                    rows={campagnes?.data ?? []}
                    rowKey={(row) => row.id}
                    empty={<EmptyState icon={ICON.budget} title="Aucune campagne" action={<Button variant="primary" icon={ICON.create} to="/preparation/campagnes/nouvelle">Nouvelle campagne</Button>} />}
                />
            </SectionCard>
            {portrait?.exercice && (
                <SectionCard
                    title={`Report des lignes ${portrait.exercice.annee}`}
                    subtitle={portrait.campagne
                        ? `La campagne ${portrait.campagne.code} porte l’adoption de cet exercice. Le report de lignes ne rend plus l’exercice exécutoire.`
                        : 'Ce report recopie les lignes de l’exercice exécutoire lorsqu’aucune campagne n’est ouverte.'}
                    icon={ICON.budget}
                    actions={portrait.droits?.adopter && <Button variant="danger-outline" icon={ICON.validate} loading={pending === 'adopter'} onClick={async () => {
                        const ok = await confirm({ title: 'Adopter le report', description: 'Cette action crée les lignes votées et rend l’exercice exécutoire. Elle est irréversible.', confirmLabel: 'Adopter', tone: 'danger', icon: ICON.validate });
                        if (ok) {
                            await executer('adopter', () => api.post(`/preparation/${portrait.exercice.id}/adopter`), 'Budget adopté.');
                        }
                    }}>Adopter le report</Button>}
                    flush
                >
                    <DataTable columns={columns} rows={portrait.propositions ?? []} rowKey={(row) => row.id} empty={<EmptyState icon={ICON.budget} title="Aucune proposition reprise" />} />
                </SectionCard>
            )}
            {!portrait?.exercice && portrait?.droits?.ouvrir && (
                <SectionCard title="Exercice" icon={ICON.calendar}>
                    <p>Aucune préparation d’exercice n’est ouverte. L’ouverture recopie les lignes de l’exercice exécutoire comme base de travail.</p>
                    <Button variant="brand" icon={ICON.create} loading={pending === 'ouvrir'} onClick={() => executer('ouvrir', () => api.post('/preparation/ouvrir'), 'Préparation ouverte.')}>Ouvrir l’exercice suivant</Button>
                </SectionCard>
            )}
        </main>
    );
}
