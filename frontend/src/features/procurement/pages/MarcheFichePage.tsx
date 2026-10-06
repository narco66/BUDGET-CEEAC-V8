import { FormEvent, useEffect, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    Alert,
    Button,
    DataTable,
    EmptyState,
    ErrorMessage,
    FormField,
    ICON,
    KeyValueList,
    Modal,
    PageError,
    PageHeader,
    PageSkeleton,
    SectionCard,
    StatusBadge,
    Stepper,
    useDialogs,
    useToast,
    type StepItem,
} from '../../../components/ui';
import { dateFr, dateHeure, errorsOf, fcfa, libelleCode } from '../../../utils/format';
import MarcheForm, { PROCEDURES, type MarcheSaisie } from '../components/MarcheForm';

const CYCLE = ['projet', 'notifie', 'en_execution', 'clos'] as const;
const LIBELLES: Record<string, string> = { projet: 'Projet', notifie: 'Notifié', en_execution: 'En exécution', clos: 'Clos', resilie: 'Résilié' };
const ACTIONS_STATUT: Record<string, { label: string; tone: 'default' | 'warning' | 'danger' }> = {
    notifie: { label: 'Notifier au titulaire', tone: 'default' },
    en_execution: { label: 'Démarrer l’exécution', tone: 'default' },
    clos: { label: 'Clore le marché', tone: 'warning' },
    resilie: { label: 'Résilier', tone: 'danger' },
};

function etapes(statut: string): StepItem[] {
    if (statut === 'resilie') {
        return [{ label: 'Projet', state: 'done' }, { label: 'Résilié', state: 'rejected' }];
    }
    const rang = CYCLE.indexOf(statut as (typeof CYCLE)[number]);

    return CYCLE.map((code, index) => ({
        label: LIBELLES[code],
        state: index < rang || (code === 'clos' && statut === 'clos') ? 'done' : index === rang ? 'current' : 'todo',
    }));
}

export default function MarcheFichePage() {
    const { id } = useParams();
    const [searchParams, setSearchParams] = useSearchParams();
    const navigate = useNavigate();
    const toast = useToast();
    const { confirm, prompt } = useDialogs();
    const [marche, setMarche] = useState<any>(null);
    const [error, setError] = useState('');
    const [loadError, setLoadError] = useState('');
    const [pending, setPending] = useState<string | null>(null);
    const [edition, setEdition] = useState<MarcheSaisie | null>(null);
    const [engagementId, setEngagementId] = useState('');

    function load() {
        api.get(`/marches/${id}`)
            .then((response) => { setMarche(response.data.data); setLoadError(''); })
            .catch((caught) => setLoadError(errorsOf(caught) || 'Marché indisponible.'));
    }

    useEffect(() => { load(); }, [id]);

    // Arrivée depuis l’action « Modifier » de la liste : la fenêtre de modification s’ouvre d’emblée.
    useEffect(() => {
        if (marche && searchParams.get('modifier') === '1') {
            if (marche.actions?.modifier) {
                setEdition({ objet: marche.objet, montant: String(marche.montant), procedure: marche.procedure, tiers_id: marche.tiers_id ? String(marche.tiers_id) : '' });
            }
            setSearchParams({}, { replace: true });
        }
    }, [marche]);

    async function run(key: string, request: () => Promise<any>, success: string) {
        setPending(key);
        setError('');
        try {
            const response = await request();
            if (response?.data?.data?.reference) {
                setMarche(response.data.data);
            } else {
                load();
            }
            toast.success(success);
            return true;
        } catch (caught) {
            setError(errorsOf(caught));
            return false;
        } finally {
            setPending(null);
        }
    }

    if (!marche) {
        return loadError ? <PageError message={loadError} onRetry={load} /> : <PageSkeleton variant="detail" />;
    }

    async function changerStatut(statut: string) {
        const action = ACTIONS_STATUT[statut];
        const fields = statut === 'notifie'
            ? [{ name: 'notified_on', label: 'Date de notification', type: 'date' as const, required: true, defaultValue: new Date().toISOString().slice(0, 10) }]
            : [{ name: 'motif', label: 'Motif', type: 'textarea' as const, required: statut === 'resilie' }];
        const values = await prompt({
            title: action.label,
            description: `${marche.reference} · ${marche.objet}`,
            confirmLabel: action.label,
            tone: action.tone,
            fields,
        });
        if (values) {
            run(`statut-${statut}`, () => api.post(`/marches/${marche.id}/statut`, { statut, ...values }), `Marché ${LIBELLES[statut].toLowerCase()}.`);
        }
    }

    async function supprimer() {
        const ok = await confirm({
            title: 'Supprimer ce projet de marché ?',
            description: `${marche.reference} · ${marche.objet}. La suppression est tracée dans le journal d’audit.`,
            confirmLabel: 'Supprimer',
            tone: 'danger',
        });
        if (!ok) return;
        setPending('supprimer');
        try {
            await api.delete(`/marches/${marche.id}`);
            toast.success(`Marché ${marche.reference} supprimé.`);
            navigate('/marches');
        } catch (caught) {
            setError(errorsOf(caught));
            setPending(null);
        }
    }

    async function enregistrer(event: FormEvent) {
        event.preventDefault();
        if (!edition) return;
        const ok = await run('modifier', () => api.put(`/marches/${marche.id}`, {
            objet: edition.objet,
            montant: Number(edition.montant),
            procedure: edition.procedure,
            tiers_id: edition.tiers_id ? Number(edition.tiers_id) : null,
        }), 'Marché modifié.');
        if (ok) setEdition(null);
    }

    function rattacher(event: FormEvent) {
        event.preventDefault();
        run('rattacher', () => api.post(`/marches/${marche.id}/rattacher`, { engagement_id: Number(engagementId) }), 'Engagement rattaché : le marché est notifié.')
            .then((ok) => ok && setEngagementId(''));
    }

    const actions = marche.actions ?? {};
    const transitions: string[] = marche.transitions ?? [];

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/marches', label: 'Marchés et contrats' }}
                eyebrow={<><span className="mono strong">{marche.reference}</span><StatusBadge statut={marche.statut} libelle={marche.statut_libelle} size="sm" />{marche.annee && <span>Exercice {marche.annee}</span>}</>}
                title={marche.objet}
                subtitle={[PROCEDURES[marche.procedure] ?? marche.procedure, marche.titulaire ? `titulaire ${marche.titulaire}` : 'titulaire non désigné'].join(' · ')}
                figure={{ label: 'Montant du marché', value: fcfa(marche.montant), unit: 'FCFA' }}
                actions={(
                    <>
                        {actions.modifier && (
                            <Button icon={ICON.edit} onClick={() => setEdition({ objet: marche.objet, montant: String(marche.montant), procedure: marche.procedure, tiers_id: marche.tiers_id ? String(marche.tiers_id) : '' })}>Modifier</Button>
                        )}
                        {transitions.filter((statut) => statut !== 'resilie').map((statut) => (
                            <Button key={statut} variant="primary" icon={ICON.check} loading={pending === `statut-${statut}`} onClick={() => changerStatut(statut)}>{ACTIONS_STATUT[statut]?.label ?? statut}</Button>
                        ))}
                        {transitions.includes('resilie') && <Button variant="danger-outline" icon={ICON.reject} loading={pending === 'statut-resilie'} onClick={() => changerStatut('resilie')}>Résilier</Button>}
                        {actions.supprimer && <Button variant="danger-outline" icon={ICON.delete} loading={pending === 'supprimer'} onClick={supprimer}>Supprimer</Button>}
                    </>
                )}
            />
            <ErrorMessage error={error} onClose={() => setError('')} />

            <SectionCard title="Cycle du marché" icon={ICON.history}>
                <Stepper label="Cycle du marché" steps={etapes(marche.statut)} />
                {marche.statut === 'projet' && <p className="subtle" style={{ marginTop: 8 }}>Le contenu du marché se modifie tant qu’il est au stade de projet. Après notification, seul son statut évolue.</p>}
            </SectionCard>

            <div className="grid-2">
                <SectionCard title="Identification" icon={ICON.document}>
                    <KeyValueList items={[
                        { label: 'Référence', value: marche.reference, mono: true, strong: true },
                        { label: 'Procédure', value: PROCEDURES[marche.procedure] ?? marche.procedure },
                        { label: 'Montant', value: `${fcfa(marche.montant)} FCFA`, mono: true },
                        { label: 'Titulaire', value: marche.titulaire ? <>{marche.titulaire} <span className="mono subtle">{marche.titulaire_code}</span></> : 'Non désigné', warning: !marche.titulaire },
                        { label: 'Notifié le', value: marche.notifie_le ? dateFr(marche.notifie_le) : '—' },
                        { label: 'Créé par', value: marche.cree_par ? `${marche.cree_par} · ${dateHeure(marche.cree_le)}` : dateHeure(marche.cree_le) },
                    ]} />
                </SectionCard>

                <SectionCard title="Engagement rattaché" icon={ICON.commitment}>
                    {marche.engagement_id ? (
                        <KeyValueList items={[
                            { label: 'Engagement', value: <Link className="mono strong" to={`/engagements/${marche.engagement_id}`}>{marche.engagement}</Link> },
                            { label: 'Montant engagé', value: marche.engagement_montant !== null ? `${fcfa(marche.engagement_montant)} FCFA` : '—', mono: true },
                            {
                                label: 'Écart marché / engagement',
                                value: marche.engagement_montant !== null ? `${fcfa(marche.montant - marche.engagement_montant)} FCFA` : '—',
                                mono: true,
                                warning: marche.engagement_montant !== null && marche.engagement_montant > marche.montant,
                            },
                        ]} />
                    ) : actions.rattacher ? (
                        <form className="stack" onSubmit={rattacher}>
                            <Alert tone="info">Le marché n’est rattaché à aucun engagement. Le rattachement exige le même exercice et notifie le marché.</Alert>
                            <FormField label="Engagement" required>
                                <select className="inp" required value={engagementId} onChange={(event) => setEngagementId(event.target.value)}>
                                    <option value="">Choisir</option>
                                    {(marche.engagements ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.reference}</option>)}
                                </select>
                            </FormField>
                            <div className="form-actions"><Button variant="primary" type="submit" icon={ICON.transform} loading={pending === 'rattacher'} disabled={!engagementId}>Rattacher</Button></div>
                        </form>
                    ) : <EmptyState icon={ICON.commitment} title="Aucun engagement rattaché" compact />}
                </SectionCard>
            </div>

            <SectionCard title="Historique" icon={ICON.history} flush>
                <DataTable
                    columns={[
                        { key: 'le', header: 'Date', render: (row: any) => <span className="num">{dateHeure(row.le)}</span> },
                        { key: 'action', header: 'Action', render: (row: any) => libelleCode(String(row.action).replace(/^marche\./, '')) },
                        { key: 'acteur', header: 'Acteur', render: (row: any) => row.acteur ?? '—' },
                        { key: 'motif', header: 'Motif', render: (row: any) => <span className="cell-sub">{row.motif ?? ''}</span> },
                    ]}
                    rows={marche.historique ?? []}
                    rowKey={(row: any) => row.id}
                    compact
                    empty={<EmptyState icon={ICON.history} title="Aucun événement" compact />}
                />
            </SectionCard>

            {edition && (
                <Modal
                    title={`Modifier ${marche.reference}`}
                    icon={ICON.edit}
                    onClose={() => setEdition(null)}
                    footer={<><Button onClick={() => setEdition(null)}>Annuler</Button><Button variant="primary" type="submit" form="marche-edition" icon={ICON.save} loading={pending === 'modifier'}>Enregistrer</Button></>}
                >
                    <form id="marche-edition" className="form-grid" onSubmit={enregistrer}>
                        <MarcheForm value={edition} onChange={setEdition} tiers={marche.tiers ?? []} />
                    </form>
                    <ErrorMessage error={error} title="Modification refusée" />
                </Modal>
            )}
        </main>
    );
}
