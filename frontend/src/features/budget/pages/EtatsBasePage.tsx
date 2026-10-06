import { useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, ICON, Meter, PageError, PageHeader, PageSkeleton, SectionCard, StatCard, StatusBadge, type Column } from '../../../components/ui';
import { telecharger } from '../../../utils/download';
import { errorsOf, fcfa } from '../../../utils/format';

type DossierRow = { reference: string; statut: string; montant: number };
type EtatBaseData = {
    exercice: number;
    precision: string;
    engagements_vises_non_transformes: number;
    montant_engagements_vises: number;
    liquidations_ouvertes: number;
    ordonnancements_non_payes: number;
    montant_ordonnancements_non_payes: number;
    paiements_a_rapprocher: number;
    rejets: number;
    anciennete_ordonnancements_ouverts: Record<string, number>;
    credits: {
        perimetre: string;
        total: { revise: number; engage: number; paye: number; disponible: number };
        natures: Record<string, { revise: number; engage: number; paye: number; disponible: number }>;
    };
    listes: { liquidations: DossierRow[]; ordonnancements: DossierRow[] };
    fournisseurs: Array<{ titulaire: string; nombre: number; montant: number }>;
    delais: { precision?: string; regles?: number; dans_le_delai?: number; depassements?: number; sans_regle?: number };
};

const NATURES: Record<string, string> = {
    pap: 'PAP',
    hors_pap: 'Hors PAP',
};

const STATUTS: Record<string, string> = {
    a_signer: 'À signer',
    a_rapprocher: 'À rapprocher',
    cloture: 'Clôturé',
    complement: 'Complément demandé',
    en_controle: 'En contrôle',
    en_preparation: 'En préparation',
    generee: 'Générée',
    paye_partiel: 'Partiellement payé',
    rejete: 'Rejeté',
    rejetee: 'Rejetée',
    signe: 'Signé',
    transmission_erreur: 'Transmission en erreur',
    transformee_ordonnancement: 'Transmise en ordonnancement',
};

const TRANCHES: Record<string, string> = {
    '0_30': '0 à 30 jours',
    '31_60': '31 à 60 jours',
    '61_90': '61 à 90 jours',
    '91_180': '91 à 180 jours',
    plus_180: 'Plus de 180 jours',
};

export default function EtatsBasePage() {
    const [data, setData] = useState<EtatBaseData | null>(null);
    const [error, setError] = useState('');

    useEffect(() => {
        api.get('/etats/base').then((response) => setData(response.data.data as EtatBaseData)).catch((caught) => setError(errorsOf(caught) || 'États indisponibles.'));
    }, []);

    if (error && !data) {
        return <main className="app-content"><PageError message={error} /></main>;
    }
    if (!data) {
        return <main className="app-content"><PageSkeleton /></main>;
    }

    const credits = data.credits?.total ?? { revise: 0, engage: 0, paye: 0, disponible: 0 };
    const natures = Object.entries(data.credits?.natures ?? {});
    const anciennetes = Object.entries(data.anciennete_ordonnancements_ouverts ?? {});
    const maxAnciennete = Math.max(1, ...anciennetes.map(([, nombre]) => Number(nombre) || 0));
    const delais = data.delais ?? {};

    const colonnesDossiers: Column<DossierRow>[] = [
        { key: 'reference', header: 'Référence', className: 'mono', render: (row) => row.reference },
        { key: 'statut', header: 'Statut', render: (row) => <StatusBadge statut={row.statut} libelle={STATUTS[row.statut] ?? row.statut} size="sm" /> },
        { key: 'montant', header: 'Montant', align: 'right', className: 'mono', render: (row) => `${fcfa(row.montant)} F CFA` },
    ];

    const colonnesFournisseurs: Column<EtatBaseData['fournisseurs'][number]>[] = [
        { key: 'titulaire', header: 'Titulaire', render: (row) => row.titulaire },
        { key: 'nombre', header: 'Paiements', align: 'right', render: (row) => row.nombre },
        { key: 'montant', header: 'Payé', align: 'right', className: 'mono', render: (row) => `${fcfa(row.montant)} F CFA` },
    ];

    const delaiMetrics = [
        { label: 'Règles enregistrées', value: delais.regles ?? 0, tone: 'neutral' },
        { label: 'Dans le délai', value: delais.dans_le_delai ?? 0, tone: 'success' },
        { label: 'Dépassements', value: delais.depassements ?? 0, tone: 'warning' },
        { label: 'Sans règle', value: delais.sans_regle ?? 0, tone: 'warning' },
    ];

    async function exporter() {
        setError('');
        try {
            await telecharger('/etats/base/export', `etats-base-${data?.exercice}.csv`);
        } catch (caught) {
            setError(errorsOf(caught));
        }
    }

    return (
        <main className="app-content">
            <PageHeader
                title={`États de base ${data.exercice}`}
                subtitle={data.precision}
                eyebrow="Pilotage · Exécution budgétaire"
                figure={{ label: 'Crédit disponible', value: fcfa(credits.disponible), unit: 'F CFA' }}
                actions={<Button icon={ICON.export} onClick={exporter}>Exporter CSV</Button>}
            />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <div className="grid-kpi">
                <StatCard label="Engagements visés" value={data.engagements_vises_non_transformes} unit="dossiers" hint={`${fcfa(data.montant_engagements_vises)} F CFA`} icon={ICON.commitment} />
                <StatCard label="Liquidations ouvertes" value={data.liquidations_ouvertes} unit="dossiers" hint="À traiter dans la chaîne" icon={ICON.settlement} tone="warning" />
                <StatCard label="Ordonnancements non payés" value={data.ordonnancements_non_payes} unit="dossiers" hint={`${fcfa(data.montant_ordonnancements_non_payes)} F CFA`} icon={ICON.order} tone="orange" />
                <StatCard label="Paiements à rapprocher" value={data.paiements_a_rapprocher} unit="paiements" hint="En attente de rapprochement" icon={ICON.reconciliation} tone="info" />
                <StatCard label="Rejets" value={data.rejets} unit="dossiers" hint="Sur l’exercice" icon={ICON.reject} tone="danger" />
            </div>
            <SectionCard title="Crédits des lignes officielles" subtitle={data.credits?.perimetre} icon={ICON.budget}>
                <div className="etat-credit-layout">
                    <div className="etat-credit-total-grid" aria-label="Synthèse des crédits">
                        {[
                            { label: 'Révisé', value: credits.revise },
                            { label: 'Engagé', value: credits.engage },
                            { label: 'Payé', value: credits.paye },
                            { label: 'Disponible', value: credits.disponible },
                        ].map((item) => (
                            <div className="etat-credit-total" key={item.label}>
                                <span>{item.label}</span>
                                <strong>{fcfa(item.value)} <small>F CFA</small></strong>
                            </div>
                        ))}
                        <div className="etat-credit-meters">
                            <Meter label="Engagé sur le révisé" value={`${fcfa(credits.engage)} F CFA`} ratio={credits.revise ? (credits.engage / credits.revise) * 100 : null} color="var(--navy-600)" />
                            <Meter label="Payé sur le révisé" value={`${fcfa(credits.paye)} F CFA`} ratio={credits.revise ? (credits.paye / credits.revise) * 100 : null} color="var(--green-600)" />
                        </div>
                    </div>
                    <div className="table-wrap">
                        <table className="tbl etat-nature-table">
                            <caption className="sr-only">Crédits par nature de dépense</caption>
                            <thead><tr><th scope="col">Nature</th><th className="r" scope="col">Révisé</th><th className="r" scope="col">Engagé</th><th className="r" scope="col">Payé</th><th className="r" scope="col">Disponible</th></tr></thead>
                            <tbody>
                                {natures.map(([nature, solde]) => (
                                    <tr key={nature}>
                                        <td>{NATURES[nature] ?? nature}</td>
                                        <td className="r mono">{fcfa(solde.revise)}</td>
                                        <td className="r mono">{fcfa(solde.engage)}</td>
                                        <td className="r mono">{fcfa(solde.paye)}</td>
                                        <td className="r mono">{fcfa(solde.disponible)}</td>
                                    </tr>
                                ))}
                                {natures.length === 0 && <tr><td colSpan={5} className="is-empty">Aucun crédit sur les lignes officielles.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                </div>
            </SectionCard>
            <div className="grid-halves">
                <SectionCard title="Liquidations ouvertes" subtitle="Dossiers à traiter" icon={ICON.settlement} tag={<span className="badge tone-neutral badge-sm">{data.listes.liquidations.length}</span>} flush>
                    <DataTable columns={colonnesDossiers} rows={data.listes.liquidations} rowKey={(row) => row.reference} compact caption="Liquidations ouvertes" empty={<EmptyState icon={ICON.settlement} title="Aucune liquidation ouverte" compact />} />
                </SectionCard>
                <SectionCard title="Ordonnancements non payés" subtitle="Ordres en attente de règlement" icon={ICON.order} tag={<span className="badge tone-neutral badge-sm">{data.listes.ordonnancements.length}</span>} flush>
                    <DataTable columns={colonnesDossiers} rows={data.listes.ordonnancements} rowKey={(row) => row.reference} compact caption="Ordonnancements non payés" empty={<EmptyState icon={ICON.order} title="Aucun ordonnancement ouvert" compact />} />
                </SectionCard>
            </div>
            <div className="grid-2">
                <SectionCard title="Paiements par titulaire" subtitle="Montants effectivement payés" icon={ICON.payment} flush>
                    <DataTable columns={colonnesFournisseurs} rows={data.fournisseurs} rowKey={(row) => row.titulaire} compact caption="Paiements regroupés par titulaire" empty={<EmptyState icon={ICON.payment} title="Aucun paiement rapproché" compact />} />
                </SectionCard>
                <SectionCard title="Ancienneté des ordonnancements ouverts" subtitle="Ordres encore ouverts, classés par ancienneté." icon={ICON.clock}>
                    <div className="etat-age-list" aria-label="Répartition des ordonnancements selon leur ancienneté">
                        {anciennetes.map(([code, nombre]) => (
                            <div className="etat-age-row" key={code}>
                                <span>{TRANCHES[code] ?? code}</span>
                                <div className="progress is-thin" role="img" aria-label={`${TRANCHES[code] ?? code} : ${nombre} dossier(s)`}>
                                    <span style={{ width: `${(Number(nombre) / maxAnciennete) * 100}%` }} />
                                </div>
                                <strong>{nombre}</strong>
                            </div>
                        ))}
                    </div>
                </SectionCard>
            </div>
            <SectionCard title="Délais des tâches ouvertes" subtitle={delais.precision} icon={ICON.clock}>
                <dl className="etat-delay-grid">
                    {delaiMetrics.map((metric) => (
                        <div className={`etat-delay-metric tone-${metric.tone}`} key={metric.label}>
                            <dt>{metric.label}</dt>
                            <dd>{metric.value}</dd>
                        </div>
                    ))}
                </dl>
            </SectionCard>
        </main>
    );
}
