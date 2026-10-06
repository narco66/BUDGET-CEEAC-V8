import { faBan, faBuildingColumns, faCircleExclamation, faGaugeHigh, faPause } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { Link } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    Badge,
    Button,
    EmptyState,
    ICON,
    PageError,
    PageHeader,
    PageSkeleton,
    ProgressBar,
    SectionCard,
    StatCard,
} from '../../../components/ui';
import { fcfa, fcfaCompact, percent } from '../../../utils/format';
import useResource from '../../../utils/useResource';

const STAGE_NOUNS: Record<string, [string, string]> = {
    EB: ['expression de besoin', 'expressions de besoin'],
    ENG: ['engagement', 'engagements'],
    LIQ: ['liquidation', 'liquidations'],
    ORD: ['ordonnancement', 'ordonnancements'],
    PAY: ['paiement', 'paiements'],
};

function plural(count: number, singular: string, pluralForm = `${singular}s`): string {
    return `${count} ${count > 1 ? pluralForm : singular}`;
}
import { CumulativeChart, ExecutionFunnel, StageFlow } from '../components/ChainCharts';

export default function ChainePage() {
    const { data: board, error, reload } = useResource(() => api.get('/chaine/tableau-de-bord').then((response) => response.data.data), []);

    if (!board) {
        return error ? <PageError message={error} onRetry={reload} /> : <PageSkeleton />;
    }

    const pilotage = board.pilotage;
    if (!pilotage?.exercice) {
        return (
            <main className="app-content">
                <PageHeader title="Chaîne de dépense" subtitle="Situation des dossiers, de l’expression du besoin jusqu’au règlement." />
                <div className="card"><EmptyState icon={ICON.calendar} title="Aucun exercice budgétaire">Le tableau de chaîne s’affiche dès qu’un exercice est ouvert.</EmptyState></div>
            </main>
        );
    }

    const exe = pilotage.execution;
    const alertes = pilotage.alertes;
    const retards = pilotage.maillons.filter((stage) => stage.en_retard > 0);
    const attention = [
        ...retards.map((stage) => ({ key: `retard-${stage.code}`, icon: ICON.clock, tone: 'danger', label: `${plural(stage.en_retard, STAGE_NOUNS[stage.code]?.[0] ?? 'dossier', STAGE_NOUNS[stage.code]?.[1] ?? 'dossiers')} en retard`, hint: 'Échéance de traitement dépassée', to: stage.lien })),
        alertes.transmissions_en_erreur > 0 && { key: 'transmission', icon: faCircleExclamation, tone: 'danger', label: `${plural(alertes.transmissions_en_erreur, 'transmission')} à l’Agence Comptable en erreur`, hint: 'Ordres signés, transmission à relancer', to: '/ordonnancements' },
        alertes.rejets_bancaires > 0 && { key: 'rejet', icon: faBan, tone: 'danger', label: plural(alertes.rejets_bancaires, 'rejet bancaire', 'rejets bancaires'), hint: 'Paiements à réémettre', to: '/paiements' },
        alertes.paiements_suspendus > 0 && { key: 'suspendu', icon: faPause, tone: 'warning', label: plural(alertes.paiements_suspendus, 'paiement suspendu', 'paiements suspendus'), hint: 'Suspension à lever ou à confirmer', to: '/paiements' },
        alertes.a_rapprocher > 0 && { key: 'rapprocher', icon: ICON.reconciliation, tone: 'warning', label: `${plural(alertes.a_rapprocher, 'paiement')} à rapprocher`, hint: 'Concordance banque ou caisse attendue', to: '/rapprochements' },
        pilotage.lignes.en_depassement > 0 && { key: 'depassement', icon: faCircleExclamation, tone: 'danger', label: `${plural(pilotage.lignes.en_depassement, 'ligne')} en dépassement de crédit`, hint: 'Disponible négatif : engagé et réservé supérieurs au révisé', to: '/lignes-budgetaires' },
        pilotage.lignes.tendues > 0 && { key: 'tendues', icon: faGaugeHigh, tone: 'warning', label: `${plural(pilotage.lignes.tendues, 'ligne')} au disponible tendu`, hint: `Disponible inférieur à ${pilotage.lignes.seuil_tension} % du révisé`, to: '/lignes-budgetaires' },
    ].filter(Boolean) as Array<{ key: string; icon: any; tone: string; label: string; hint: string; to: string }>;

    return (
        <main className="app-content">
            <PageHeader
                eyebrow={<><FontAwesomeIcon icon={ICON.dashboard} /> Tableau de pilotage · exercice {pilotage.exercice.annee} <Badge tone="success" size="sm" dot>{pilotage.exercice.statut}</Badge></>}
                title="Chaîne de dépense"
                subtitle="Exécution du budget et situation des dossiers, de l’expression du besoin jusqu’au règlement."
                actions={<Button icon={ICON.budget} to="/lignes-budgetaires">Lignes budgétaires</Button>}
            />

            <div className="grid-kpi">
                <StatCard dark label={`Budget révisé ${pilotage.exercice.annee}`} value={fcfaCompact(exe.revise)} unit="FCFA" hint={`${exe.lignes} lignes · disponible ${fcfaCompact(exe.disponible)}`} icon={ICON.budget} to="/lignes-budgetaires" />
                <StatCard label="Engagé" value={fcfaCompact(exe.engage)} unit="FCFA" hint={`${percent(exe.taux_engagement)} du budget révisé`} icon={ICON.commitment} to="/engagements">
                    <ProgressBar value={exe.taux_engagement} size="thin" label="Taux d’engagement du budget révisé" color="#3B5FA8" />
                </StatCard>
                <StatCard label="Payé" value={fcfaCompact(exe.paye)} unit="FCFA" hint={`${exe.engage > 0 ? percent((exe.paye / exe.engage) * 100) : '—'} de l’engagé · ${percent(exe.taux_paiement)} du révisé`} icon={ICON.payment} tone="success" to="/paiements" />
                <StatCard label="Reste à payer" value={fcfaCompact(exe.reste_a_payer)} unit="FCFA" hint="Ordonnancé non encore payé" icon={ICON.pending} tone={exe.reste_a_payer > 0 ? 'orange' : 'neutral'} to="/paiements" />
            </div>

            <div className="layout-aside is-wide">
                <SectionCard
                    title="Entonnoir d’exécution"
                    subtitle="De l’engagé au payé · base 100 % = montant engagé"
                    icon={ICON.transform}
                    footer={<span>Soldes calculés par la source unique des lignes budgétaires (engagé net des dégagements, liquidé visé, ordonnancé signé, payé exécuté). Réservé par les EB en circuit : <b className="mono">{fcfaCompact(exe.reserve)}</b> · gelé : <b className="mono">{fcfaCompact(exe.gele)}</b>.</span>}
                >
                    <ExecutionFunnel execution={exe} />
                </SectionCard>

                <SectionCard title="Points d’attention" icon={ICON.warning} tone={attention.length > 0 ? 'warning' : 'default'} tag={attention.length > 0 ? <Badge tone="warning" size="sm">{attention.length}</Badge> : undefined}>
                    {attention.length === 0 ? (
                        <EmptyState compact icon={ICON.success} title="Aucun point d’attention">Aucun retard, aucune anomalie de transmission ou de règlement sur l’exercice.</EmptyState>
                    ) : (
                        <ul className="list-rows">
                            {attention.map((item) => (
                                <li key={item.key}>
                                    <Link to={item.to} className="list-row">
                                        <span className={`stat-icon`} style={{ width: 30, height: 30, background: `var(--${item.tone === 'danger' ? 'danger' : 'warning'}-bg)`, color: `var(--${item.tone === 'danger' ? 'danger' : 'warning'}-fg)` }} aria-hidden="true"><FontAwesomeIcon icon={item.icon} /></span>
                                        <span className="list-row-main"><span className="list-row-title">{item.label}</span><span className="list-row-sub">{item.hint}</span></span>
                                        <FontAwesomeIcon icon={ICON.open} style={{ color: 'var(--slate-400)' }} />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>

            <SectionCard
                title="Flux des dossiers par maillon"
                subtitle={`Dossiers de l’exercice ${pilotage.exercice.annee} · aboutis = transmis au maillon suivant ou réglés`}
                icon={ICON.actions}
            >
                <StageFlow stages={pilotage.maillons} />
            </SectionCard>

            <div className="layout-aside is-wide">
                <SectionCard title="Évolution de l’exécution" subtitle="Cumuls mensuels · engagé à la date de l’engagement, payé à la date de valeur" icon={ICON.dashboard}>
                    <CumulativeChart points={pilotage.evolution} year={pilotage.exercice.annee} />
                </SectionCard>

                <SectionCard
                    title="Lignes les plus engagées"
                    icon={faBuildingColumns}
                    subtitle="Taux d’engagement du crédit révisé"
                    actions={<Button size="sm" variant="ghost" to="/lignes-budgetaires" iconRight={ICON.open}>Toutes</Button>}
                >
                    {pilotage.lignes.plus_engagees.length === 0 ? (
                        <EmptyState compact icon={ICON.budget} title="Aucune ligne dotée" />
                    ) : (
                        <ul className="stack" style={{ gap: 14 }}>
                            {pilotage.lignes.plus_engagees.map((line) => (
                                <li key={line.id} className="stack-sm" style={{ gap: 5 }}>
                                    <div className="split" style={{ gap: 8, flexWrap: 'nowrap' }}>
                                        <span className="truncate" title={line.libelle}><span className="mono strong">{line.code}</span> · {line.libelle}</span>
                                        <span className="mono strong nowrap">{percent(line.taux_engagement, 0)}</span>
                                    </div>
                                    <ProgressBar value={line.taux_engagement} size="thin" label={`Taux d’engagement de la ligne ${line.code}`} color={line.taux_engagement >= 90 ? 'var(--danger-solid)' : line.taux_engagement >= 75 ? 'var(--warning-solid)' : '#3B5FA8'} />
                                    <span className="subtle">Engagé {fcfaCompact(line.engage)} sur {fcfaCompact(line.revise)} · disponible <span className={line.disponible < 0 ? 'text-danger strong' : undefined}>{fcfa(line.disponible)} FCFA</span>{line.disponible < 0 && ' · dépassement'}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
        </main>
    );
}
