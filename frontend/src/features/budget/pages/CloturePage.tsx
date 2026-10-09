import { useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import {
    Alert,
    Badge,
    Button,
    DataTable,
    EmptyState,
    ErrorMessage,
    FormField,
    ICON,
    PageHeader,
    PageSkeleton,
    SectionCard,
    StatStrip,
    StatusBadge,
    Stepper,
    StripCell,
    useDialogs,
    useToast,
    type Column,
    type StepItem,
} from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

const STATUTS_EXERCICE: Record<string, string> = {
    preparation: 'En préparation',
    executoire: 'Exécutoire',
    ouvert: 'Ouvert',
    clos: 'Clos',
};

type Periode = { id: number; label: string; statut: string; peut_fermer?: boolean; peut_rouvrir?: boolean };

function etapes(exercice: any): StepItem[] {
    const demandee = exercice.cloture === 'demande' || exercice.statut === 'clos';
    const close = exercice.statut === 'clos';
    const archivee = Boolean(exercice.archive);

    return [
        { label: 'Chaîne soldée', state: exercice.blocages > 0 ? 'current' : 'done', hint: exercice.blocages > 0 ? `${exercice.blocages} dossier(s) ouvert(s)` : 'Aucun dossier ouvert' },
        { label: 'Demande du Directeur du Budget', state: demandee ? 'done' : exercice.blocages > 0 ? 'todo' : 'current' },
        { label: 'Confirmation du Secrétaire général', state: close ? 'done' : demandee ? 'current' : 'todo' },
        { label: 'Archivage', state: archivee ? 'done' : close ? 'current' : 'todo', hint: exercice.archive || undefined },
    ];
}

export default function CloturePage() {
    const toast = useToast();
    const { prompt } = useDialogs();
    const [portrait, setPortrait] = useState<any>(null);
    const [error, setError] = useState('');
    const [motif, setMotif] = useState('');
    const [pending, setPending] = useState<string | null>(null);

    function load() {
        api.get('/cloture').then((response) => { setPortrait(response.data); setError(''); }).catch((caught) => setError(errorsOf(caught)));
    }

    useEffect(() => { load(); }, []);

    async function agir(key: string, request: () => Promise<unknown>, success: string) {
        setPending(key);
        setError('');
        try {
            await request();
            toast.success(success);
            setMotif('');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    if (!portrait && !error) {
        return <PageSkeleton />;
    }

    const colonnesPeriodes: Column<Periode>[] = [
        { key: 'label', header: 'Période', render: (periode) => <span className="strong">{periode.label}</span> },
        { key: 'statut', header: 'Statut', render: (periode) => <StatusBadge statut={periode.statut} libelle={STATUTS_EXERCICE[periode.statut] ?? periode.statut} size="sm" /> },
        {
            key: 'action',
            header: 'Fermeture',
            render: (periode) => (periode.peut_fermer ? (
                <form
                    className="cluster"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const motifPeriode = String(new FormData(event.currentTarget).get('motif') || '');
                        agir(`p${periode.id}`, () => api.post(`/cloture/periodes/${periode.id}/fermer`, { motif: motifPeriode }), 'Période fermée.');
                    }}
                >
                    <input className="inp" name="motif" required placeholder="Motif de fermeture" aria-label={`Motif de fermeture ${periode.label}`} style={{ minWidth: 220 }} />
                    <Button size="sm" type="submit" icon={ICON.lock} loading={pending === `p${periode.id}`}>Fermer la période échue</Button>
                </form>
            ) : periode.peut_rouvrir ? (
                <Button size="sm" variant="warning" loading={pending === `r${periode.id}`} onClick={async () => {
                    const values = await prompt({ title: `Rouvrir ${periode.label}`, description: 'La réouverture permet de nouveau des écritures sur la période ; elle est tracée.', confirmLabel: 'Rouvrir', tone: 'warning', fields: [{ name: 'motif', label: 'Motif', type: 'textarea', required: true }] });
                    if (values) agir(`r${periode.id}`, () => api.post(`/cloture/periodes/${periode.id}/rouvrir`, values), 'Période rouverte.');
                }}>Rouvrir</Button>
            ) : <span className="subtle">—</span>),
        },
    ];

    return (
        <main className="app-content">
            <PageHeader
                eyebrow="Budget"
                title="Clôture annuelle"
                subtitle="Un exercice ne se clôture que lorsque la chaîne de dépense ne contient plus de dossier ouvert. Le Secrétaire général confirme la demande du Directeur du Budget."
            />
            <ErrorMessage error={error} onClose={() => setError('')} />

            {(portrait?.exercices ?? []).map((exercice: any) => {
                const situation = exercice.situation;
                const demandee = exercice.cloture === 'demande';

                return (
                    <SectionCard
                        key={exercice.id}
                        title={`Exercice ${exercice.annee}`}
                        icon={ICON.budget}
                        tag={<StatusBadge statut={exercice.statut} libelle={STATUTS_EXERCICE[exercice.statut] ?? exercice.statut} size="sm" />}
                    >
                        <div className="stack">
                            {exercice.statut !== 'preparation' && <Stepper label={`Clôture de l’exercice ${exercice.annee}`} steps={etapes(exercice)} />}

                            {situation && (
                                <StatStrip label={`Situation de la chaîne ${exercice.annee}`}>
                                    <StripCell label="Engagements visés non transformés" value={situation.engagements_vises_non_transformes} />
                                    <StripCell label="Liquidations ouvertes" value={situation.liquidations_ouvertes} />
                                    <StripCell label="Ordonnancements non payés" value={situation.ordonnancements_non_payes} />
                                    <StripCell label="Paiements à rapprocher" value={situation.paiements_a_rapprocher} />
                                </StatStrip>
                            )}
                            {situation?.precision && <p className="subtle">{situation.precision}</p>}

                            {exercice.blocages > 0 && (
                                <Alert tone="warning" title={`${exercice.blocages} dossier(s) ouvert(s) empêchent la clôture`}>
                                    <div className="cluster" style={{ marginTop: 6 }}>
                                        {(exercice.exemples ?? []).map((row: any) => (
                                            <Badge key={`${row.module}-${row.reference}`} tone="neutral" size="sm">{row.module} · {row.reference}</Badge>
                                        ))}
                                    </div>
                                </Alert>
                            )}

                            {exercice.blocages === 0 && exercice.statut !== 'clos' && exercice.statut !== 'preparation' && (
                                demandee ? (
                                    <div className="cluster">
                                        <Alert tone="info">La clôture est demandée. Elle attend la confirmation du Secrétaire général.</Alert>
                                        <Button variant="primary" icon={ICON.check} loading={pending === `c${exercice.id}`} onClick={() => agir(`c${exercice.id}`, () => api.post(`/cloture/${exercice.id}/confirmer`), 'Exercice clôturé.')}>Confirmer la clôture</Button>
                                    </div>
                                ) : (
                                    <form className="cluster" style={{ alignItems: 'flex-end' }} onSubmit={(event) => { event.preventDefault(); agir(`d${exercice.id}`, () => api.post(`/cloture/${exercice.id}/demander`, { motif }), 'Demande enregistrée.'); }}>
                                        <FormField label="Motif de clôture" required style={{ flex: '1 1 320px' }}>
                                            <input className="inp" required value={motif} onChange={(event) => setMotif(event.target.value)} />
                                        </FormField>
                                        <Button variant="primary" type="submit" icon={ICON.lock} loading={pending === `d${exercice.id}`}>Demander la clôture</Button>
                                    </form>
                                )
                            )}

                            {exercice.statut === 'clos' && (
                                <Alert tone="success">{exercice.archive ? `Clôture archivée sous ${exercice.archive}. ` : ''}Aucune opération financière n’y est plus possible.</Alert>
                            )}
                            {exercice.statut === 'clos' && !exercice.archive && exercice.peut_archiver && (
                                <form className="cluster" style={{ alignItems: 'flex-end' }} onSubmit={(event) => { event.preventDefault(); const reference = new FormData(event.currentTarget).get('reference'); agir(`a${exercice.id}`, () => api.post(`/cloture/${exercice.id}/archiver`, { reference }), 'Clôture archivée.'); }}>
                                    <FormField label="Référence d’archive" required style={{ flex: '1 1 320px' }}>
                                        <input className="inp" name="reference" required maxLength={80} />
                                    </FormField>
                                    <Button variant="primary" type="submit" icon={ICON.archive} loading={pending === `a${exercice.id}`}>Archiver la clôture</Button>
                                </form>
                            )}
                            {exercice.statut === 'clos' && !exercice.archive && !exercice.peut_archiver && <Alert tone="info">L’archivage de cette clôture revient au Secrétaire général.</Alert>}
                            {exercice.statut !== 'clos' && <p className="subtle">L’archivage suit une clôture déjà confirmée. Il ne change pas le statut de l’exercice.</p>}
                        </div>

                        <h3 className="card-subtitle" style={{ marginTop: 16 }}>Périodes</h3>
                        <DataTable
                            columns={colonnesPeriodes}
                            rows={exercice.periodes ?? []}
                            rowKey={(periode) => periode.id}
                            compact
                            empty={(
                                <EmptyState
                                    icon={ICON.calendar}
                                    title="Aucune période"
                                    compact
                                    action={exercice.statut !== 'clos' && <Button size="sm" icon={ICON.calendar} loading={pending === `pp${exercice.id}`} onClick={() => agir(`pp${exercice.id}`, () => api.post(`/cloture/${exercice.id}/periodes`), 'Périodes de l’exercice créées.')}>Créer les périodes</Button>}
                                >
                                    Les périodes mensuelles permettent de fermer les mois échus.
                                </EmptyState>
                            )}
                        />
                    </SectionCard>
                );
            })}
        </main>
    );
}
