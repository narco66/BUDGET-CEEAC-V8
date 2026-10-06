import { faClock, faLightbulb, faShieldHalved, faWrench } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    Badge,
    Button,
    DataTable,
    Drawer,
    EmptyState,
    ErrorMessage,
    ICON,
    PageHeader,
    ProgressBar,
    SectionCard,
    Tabs,
    useToast,
    WorkflowTimeline,
    type Column,
} from '../../../components/ui';

type Onglet = 'mesures-correctives' | 'risques' | 'recommandations';

const ONGLETS: Array<{ value: Onglet; label: string; icon: any }> = [
    { value: 'mesures-correctives', label: 'Mesures correctives', icon: faWrench },
    { value: 'risques', label: 'Risques', icon: faShieldHalved },
    { value: 'recommandations', label: 'Recommandations', icon: faLightbulb },
];

const STATUTS: Record<Onglet, string[]> = {
    'mesures-correctives': ['ouverte', 'en_cours', 'realisee', 'cloturee', 'abandonnee'],
    risques: ['ouvert', 'en_traitement', 'maitrise', 'survenu', 'clos'],
    recommandations: ['ouverte', 'acceptee', 'en_cours', 'partiellement_realisee', 'realisee', 'rejetee', 'cloturee'],
};

const PREUVE: Record<Onglet, string> = {
    'mesures-correctives': 'mesure_corrective',
    risques: 'risque',
    recommandations: 'recommandation',
};

const CRITICITE_TONE: Record<string, string> = { critique: 'danger', eleve: 'orange', modere: 'warning', faible: 'success' };

/** Libellé lisible d’un code de statut (« en_traitement » → « En traitement »). */
function libelle(code: string | null | undefined): string {
    if (!code) return '—';
    const text = code.replace(/_/g, ' ');
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function messageErreur(error: any): string {
    const details = error?.response?.data?.errors;

    return details ? Object.values(details).flat().join(' ') : error?.response?.data?.message ?? 'Mise à jour refusée.';
}

function ongletDemande(valeur: string | null): Onglet {
    return valeur === 'risques' || valeur === 'recommandations' || valeur === 'mesures-correctives' ? valeur : 'mesures-correctives';
}

export default function SuiviActionsPage() {
    const toast = useToast();
    const [params, setParams] = useSearchParams();
    const [onglet, setOnglet] = useState<Onglet>(ongletDemande(params.get('onglet')));
    const [lignes, setLignes] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [edition, setEdition] = useState<Record<number, any>>({});
    const [historique, setHistorique] = useState<{ ligne: any; lignes: any[] } | null>(null);
    const [erreur, setErreur] = useState('');
    const [pending, setPending] = useState<number | null>(null);

    function charger(cible: Onglet = onglet) {
        setLoading(true);
        api.get(`/suivi/${cible}`).then((response) => setLignes(response.data.data)).finally(() => setLoading(false));
    }

    useEffect(() => {
        const demande = ongletDemande(params.get('onglet'));
        if (demande !== onglet) {
            setOnglet(demande);
        }
    }, [params]);

    useEffect(() => {
        setEdition({});
        setHistorique(null);
        setLignes([]);
        charger(onglet);
    }, [onglet]);

    function choisir(cible: string) {
        const valeur = ongletDemande(cible);
        setOnglet(valeur);
        setParams(valeur === 'mesures-correctives' ? {} : { onglet: valeur });
    }

    function champ(id: number, cle: string, valeur: string) {
        setEdition((courant) => ({ ...courant, [id]: { ...courant[id], [cle]: valeur } }));
    }

    async function enregistrer(ligne: any) {
        setErreur('');
        const saisie = edition[ligne.id] ?? {};
        const payload: Record<string, unknown> = { comment: saisie.comment || null };
        if (saisie.status) payload.status = saisie.status;
        if (saisie.progress !== undefined && saisie.progress !== '') payload.progress = Number(saisie.progress);
        if (onglet === 'risques') {
            if (saisie.probability) payload.probability = Number(saisie.probability);
            if (saisie.impact) payload.impact = Number(saisie.impact);
        }
        setPending(ligne.id);
        try {
            await api.patch(`/suivi/${onglet}/${ligne.id}`, payload);
            setEdition((courant) => ({ ...courant, [ligne.id]: {} }));
            toast.success('Mise à jour enregistrée et tracée.');
            charger();
        } catch (error) {
            setErreur(messageErreur(error));
            toast.error(messageErreur(error));
        } finally {
            setPending(null);
        }
    }

    async function joindre(ligne: any, fichier: File | undefined) {
        if (!fichier) return;
        const form = new FormData();
        form.append('type', PREUVE[onglet]);
        form.append('id', String(ligne.id));
        form.append('category', 'preuve');
        form.append('fichier', fichier);
        try {
            await api.post('/suivi/preuves', form);
            toast.success('Preuve jointe : la clôture est maintenant possible.');
        } catch (error) {
            setErreur(messageErreur(error));
        }
    }

    function voirHistorique(ligne: any) {
        api.get(`/suivi/${onglet}/${ligne.id}/historique`).then((response) => setHistorique({ ligne, lignes: response.data.data }));
    }

    const columns: Column<any>[] = [
        {
            key: 'reference',
            header: 'Référence · description',
            render: (ligne) => (
                <div style={{ minWidth: 220 }}>
                    <div className="cell-ref">{ligne.reference ?? `MC-${ligne.id}`}</div>
                    <div style={{ marginTop: 2 }}>{ligne.description}</div>
                    {ligne.last_comment && <div className="cell-sub">« {ligne.last_comment} »</div>}
                </div>
            ),
        },
        { key: 'responsable', header: 'Responsable', render: (ligne) => ligne.responsible_role },
        {
            key: 'echeance',
            header: 'Échéance',
            render: (ligne) => (
                <div>
                    <span className="mono">{ligne.due_on ? String(ligne.due_on).slice(0, 10) : '—'}</span>
                    {ligne.en_retard && <div style={{ marginTop: 4 }}><Badge tone="danger" icon={faClock} size="sm">En retard</Badge></div>}
                </div>
            ),
        },
        {
            key: 'avancement',
            header: onglet === 'risques' ? 'Criticité' : 'Avancement',
            render: (ligne) => onglet === 'risques'
                ? <div><Badge tone={CRITICITE_TONE[ligne.criticite] ?? 'neutral'} size="sm">{libelle(ligne.criticite)}</Badge><div className="cell-sub mono">P{ligne.probability} × I{ligne.impact}</div></div>
                : <div style={{ display: 'flex', alignItems: 'center', gap: 8, minWidth: 110 }}><div style={{ flex: 1 }}><ProgressBar value={ligne.progress ?? 0} size="thin" label="Avancement" /></div><span className="mono" style={{ fontSize: 'var(--text-xs)' }}>{ligne.progress ?? 0} %</span></div>,
        },
        { key: 'statut', header: 'Statut', render: (ligne) => <Badge tone="neutral" dot>{libelle(ligne.status)}</Badge> },
        {
            key: 'maj',
            header: 'Mise à jour',
            render: (ligne) => {
                const saisie = edition[ligne.id] ?? {};
                return (
                    <div className="stack-sm" style={{ minWidth: 230, gap: 6 }}>
                        <select className="inp inp-sm" aria-label="Nouveau statut" value={saisie.status ?? ''} onChange={(event) => champ(ligne.id, 'status', event.target.value)}>
                            <option value="">Statut inchangé</option>
                            {STATUTS[onglet].map((statut) => <option key={statut} value={statut}>{libelle(statut)}</option>)}
                        </select>
                        {onglet === 'risques' ? (
                            <div style={{ display: 'flex', gap: 6 }}>
                                <input className="inp inp-sm num" type="number" min={1} max={5} placeholder="Probabilité" aria-label="Probabilité" value={saisie.probability ?? ''} onChange={(event) => champ(ligne.id, 'probability', event.target.value)} />
                                <input className="inp inp-sm num" type="number" min={1} max={5} placeholder="Impact" aria-label="Impact" value={saisie.impact ?? ''} onChange={(event) => champ(ligne.id, 'impact', event.target.value)} />
                            </div>
                        ) : (
                            <input className="inp inp-sm num" type="number" min={0} max={100} placeholder="Avancement %" aria-label="Avancement" value={saisie.progress ?? ''} onChange={(event) => champ(ligne.id, 'progress', event.target.value)} />
                        )}
                        <input className="inp inp-sm" placeholder="Commentaire / motif" aria-label="Commentaire" value={saisie.comment ?? ''} onChange={(event) => champ(ligne.id, 'comment', event.target.value)} />
                    </div>
                );
            },
        },
        {
            key: 'actions',
            header: 'Actions',
            srHeader: true,
            className: 'cell-actions',
            render: (ligne) => (
                <div className="stack-sm" style={{ gap: 6, alignItems: 'stretch' }}>
                    <Button size="sm" variant="primary" icon={ICON.save} loading={pending === ligne.id} onClick={() => enregistrer(ligne)}>Enregistrer</Button>
                    <label className="btn btn-secondary btn-sm">
                        <FontAwesomeIcon icon={ICON.attachment} /><span>Preuve</span>
                        <input type="file" hidden onChange={(event) => joindre(ligne, event.target.files?.[0])} />
                    </label>
                    <Button size="sm" variant="ghost" icon={ICON.history} onClick={() => voirHistorique(ligne)}>Historique</Button>
                </div>
            ),
        },
    ];

    return (
        <main className="app-content">
            <PageHeader
                title="Suivi des actions"
                subtitle="Avancement, statut et clôture des mesures correctives, risques et recommandations. Toute clôture exige une preuve ; tout changement est historisé."
            />
            <ErrorMessage error={erreur} onClose={() => setErreur('')} />

            <SectionCard flush>
                <Tabs label="Type d’action" inCard items={ONGLETS} value={onglet} onChange={choisir} />
                <DataTable
                    columns={columns}
                    rows={lignes}
                    rowKey={(ligne) => ligne.id}
                    loading={loading}
                    minWidth={1180}
                    empty={<EmptyState icon={ONGLETS.find((item) => item.value === onglet)?.icon} title="Aucun élément dans votre périmètre">Les éléments de suivi rattachés à vos activités apparaîtront ici.</EmptyState>}
                />
            </SectionCard>

            {historique && (
                <Drawer
                    title="Historique"
                    description={`${historique.ligne.reference ?? `MC-${historique.ligne.id}`} · ${historique.ligne.description}`}
                    icon={ICON.history}
                    onClose={() => setHistorique(null)}
                    footer={<Button onClick={() => setHistorique(null)}>Fermer</Button>}
                >
                    <WorkflowTimeline
                        events={historique.lignes.map((ligne) => ({
                            action: ligne.action,
                            actor: ligne.acteur ?? 'Système',
                            date: ligne.le,
                            detail: ligne.apres ? Object.entries(ligne.apres).map(([cle, valeur]) => `${cle} : ${valeur ?? '—'}`).join(', ') : undefined,
                            note: ligne.motif,
                            system: !ligne.acteur,
                        }))}
                    />
                </Drawer>
            )}
        </main>
    );
}
