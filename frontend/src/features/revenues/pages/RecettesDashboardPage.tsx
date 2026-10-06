import { useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import { DataTable, ErrorMessage, FilterSelect, ICON, Meter, PageHeader, SectionCard, StackedBar, StatCard, type Column } from '../../../components/ui';
import { fcfa } from '../../../utils/format';

const COULEURS = ['#0B1C3E', '#C4A35A', '#1D4E89', '#0F766E', '#B45309', '#7C3AED'];

export default function RecettesDashboardPage() {
    const [board, setBoard] = useState<any>(null);
    const [exercices, setExercices] = useState<any[]>([]);
    const [exercice, setExercice] = useState('');
    const [error, setError] = useState('');

    useEffect(() => {
        api.get('/recettes/referentiel').then((response) => setExercices(response.data.exercices ?? [])).catch(() => setExercices([]));
    }, []);

    useEffect(() => {
        api.get('/recettes/tableau', { params: exercice ? { exercice_id: exercice } : {} })
            .then((response) => { setBoard(response.data); setError(''); })
            .catch(() => setError('Le tableau de bord des recettes n’a pas pu être chargé.'));
    }, [exercice]);

    const kpi = board?.kpi;
    const maxMois = Math.max(1, ...(board?.mensuel ?? []).map((row: any) => row.montant));
    const columns: Column<any>[] = [
        { key: 'reference', header: 'Référence', className: 'mono', render: (row) => row.reference },
        { key: 'debiteur', header: 'Débiteur', render: (row) => row.debiteur },
        { key: 'solde', header: 'Solde', align: 'right', className: 'mono', render: (row) => fcfa(row.solde) },
        { key: 'echeance', header: 'Échéance', render: (row) => row.echeance },
    ];

    return (
        <main className="app-content">
            <PageHeader
                title="Recettes"
                subtitle={board?.exercice ? `Exercice ${board.exercice.annee} · taux de recouvrement ${kpi?.taux ?? 0} %` : 'Cycle des recettes, de la prévision à l’encaissement.'}
                actions={<FilterSelect label="Exercice" value={exercice} onChange={setExercice} options={exercices.map((row) => ({ value: String(row.id), label: String(row.annee) }))} allLabel="Exercice ouvert" />}
            />
            <ErrorMessage error={error} onClose={() => setError('')} />
            {kpi && (
                <div className="grid-kpi">
                    <StatCard label="Prévisions" value={fcfa(kpi.previsions)} unit="FCFA" icon={ICON.budget} to="/recettes/previsions" />
                    <StatCard label="Appelé" value={fcfa(kpi.appele)} unit="FCFA" icon={ICON.revenue} />
                    <StatCard label="Constaté" value={fcfa(kpi.constate)} unit="FCFA" icon={ICON.order} />
                    <StatCard label="Encaissé" value={fcfa(kpi.encaisse)} unit="FCFA" icon={ICON.payment} tone="success" to="/recettes/encaissements" />
                    <StatCard label="À recouvrer" value={fcfa(kpi.solde)} unit="FCFA" icon={ICON.calendar} tone="warning" to="/recettes/creances" />
                    <StatCard label="Taux" value={kpi.taux} unit="%" icon={ICON.monitoring} />
                    <StatCard label="Créances échues" value={fcfa(kpi.creances_echues)} unit="FCFA" tone="danger" />
                    <StatCard label="Non échues" value={fcfa(kpi.creances_non_echues)} unit="FCFA" />
                    <StatCard label="Contributions attendues" value={fcfa(kpi.contributions_attendues)} unit="FCFA" to="/recettes/contributions" />
                    <StatCard label="Contributions reçues" value={fcfa(kpi.contributions_recues)} unit="FCFA" tone="success" />
                    <StatCard label="En retard" value={fcfa(kpi.retards)} unit="FCFA" tone="danger" to="/recettes/relances" />
                    <StatCard label="Encaissements du mois" value={fcfa(kpi.encaissements_mois)} unit="FCFA" icon={ICON.payment} />
                </div>
            )}
            {board && (
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16, marginTop: 16 }}>
                    <SectionCard title="Prévu et encaissé" icon={ICON.monitoring}>
                        <Meter label="Encaissé / prévu" value={fcfa(board.prevu_vs_encaisse.encaisse)} ratio={board.prevu_vs_encaisse.prevu ? (board.prevu_vs_encaisse.encaisse / board.prevu_vs_encaisse.prevu) * 100 : 0} />
                    </SectionCard>
                    <SectionCard title="Vieillissement" icon={ICON.calendar}>
                        <StackedBar label="Créances par ancienneté" segments={(board.vieillissement ?? []).map((row: any, index: number) => ({ label: row.libelle, value: row.montant, color: COULEURS[index % COULEURS.length] }))} />
                    </SectionCard>
                    <SectionCard title="Encaissements mensuels" icon={ICON.dashboard}>
                        {(board.mensuel ?? []).map((row: any) => (
                            <Meter key={row.mois} label={`Mois ${row.mois}`} value={fcfa(row.montant)} ratio={(row.montant / maxMois) * 100} />
                        ))}
                    </SectionCard>
                    <SectionCard title="Par catégorie" icon={ICON.budget}>
                        {(board.par_categorie ?? []).length === 0 && <p className="subtle">Aucune recette constatée.</p>}
                        {(board.par_categorie ?? []).map((row: any) => <Meter key={row.label} label={row.label} value={fcfa(row.montant)} ratio={kpi?.encaisse ? (row.montant / kpi.encaisse) * 100 : 0} />)}
                    </SectionCard>
                    <SectionCard title="Contributions par État" icon={ICON.users}>
                        {(board.par_etat ?? []).length === 0 && <p className="subtle">Aucune contribution enregistrée.</p>}
                        {(board.par_etat ?? []).map((row: any) => <Meter key={row.etat} label={row.etat} value={fcfa(row.encaisse)} ratio={row.attendu ? (row.encaisse / row.attendu) * 100 : 0} hint={`Attendu ${fcfa(row.attendu)}`} />)}
                    </SectionCard>
                    <SectionCard title="Taux par exercice" icon={ICON.synthesis}>
                        {(board.taux_exercices ?? []).map((row: any) => <Meter key={row.annee} label={row.annee} value={`${row.taux} %`} ratio={row.taux} />)}
                    </SectionCard>
                </div>
            )}
            <SectionCard title="Principales créances" icon={ICON.revenue} flush>
                <DataTable columns={columns} rows={board?.principales ?? []} rowKey={(row) => row.id} loading={!board && !error} empty={<span className="subtle">Aucune créance ouverte.</span>} />
            </SectionCard>
        </main>
    );
}
