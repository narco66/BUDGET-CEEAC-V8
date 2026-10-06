import { faBuilding, faClock, faFlag, faHandPointer, faRoute, faUserTie } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { FormEvent, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    Alert,
    Badge,
    Button,
    DocumentList,
    EmptyState,
    ErrorMessage,
    FormField,
    ICON,
    InfoGrid,
    KeyValueList,
    PageError,
    PageHeader,
    PageSkeleton,
    SectionCard,
    useToast,
    WorkflowTimeline,
} from '../../../components/ui';
import { dateFr, dateHeure, errorsOf, fcfa } from '../../../utils/format';

export default function TacheFiche() {
    const { id } = useParams();
    const toast = useToast();
    const [tache, setTache] = useState<any>(null);
    const [texte, setTexte] = useState('');
    const [error, setError] = useState('');
    const [loadError, setLoadError] = useState('');
    const [pending, setPending] = useState<string | null>(null);

    function load() {
        api.get(`/taches/${id}`)
            .then((response) => { setTache(response.data.data); setLoadError(''); })
            .catch((caught) => setLoadError(errorsOf(caught)));
    }

    useEffect(() => {
        load();
    }, [id]);

    async function commenter(event: FormEvent) {
        event.preventDefault();
        setError('');
        setPending('commenter');
        try {
            await api.post(`/taches/${id}/commentaires`, { body: texte });
            setTexte('');
            toast.success('Observation ajoutée au dossier.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function prendre() {
        setError('');
        setPending('prendre');
        try {
            await api.post(`/taches/${id}/prendre`);
            toast.success('Tâche prise en charge.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function liberer() {
        setError('');
        setPending('liberer');
        try {
            await api.post(`/taches/${id}/liberer`);
            toast.success('Tâche libérée : elle est de nouveau proposée à tous les titulaires.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    if (!tache) {
        return loadError ? <PageError message={loadError} onRetry={load} /> : <PageSkeleton variant="detail" />;
    }

    const banniere = tache.banniere ?? {};
    const actionRequise = banniere.action_requise || tache.action_libelle;

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/taches', label: 'Mes tâches' }}
                eyebrow={(
                    <>
                        <span className="mono strong">{tache.reference}</span>
                        <Badge tone="brand" size="sm">{tache.type}</Badge>
                        {tache.exercice && <span>Exercice {tache.exercice}</span>}
                        {tache.statut_libelle && <Badge tone="neutral" dot>{tache.statut_libelle}</Badge>}
                    </>
                )}
                title={tache.sujet}
                subtitle={tache.objet}
                figure={{ label: 'Montant du dossier', value: fcfa(tache.montant), unit: 'FCFA' }}
                actions={(
                    <>
                        {tache.peut_agir && tache.statut !== 'terminee' && tache.statut !== 'en_cours' && (
                            <Button variant="brand" icon={faHandPointer} onClick={prendre} loading={pending === 'prendre'}>Prendre en charge</Button>
                        )}
                        {tache.peut_liberer && (
                            <Button variant="secondary" icon={ICON.return} onClick={liberer} loading={pending === 'liberer'}>Libérer</Button>
                        )}
                        {tache.lien && <Button variant="primary" to={tache.lien} iconRight={ICON.open}>Ouvrir le dossier</Button>}
                    </>
                )}
            />

            <SectionCard tone="brand">
                <div className="split" style={{ alignItems: 'flex-start' }}>
                    <div className="stack-sm">
                        <span className="lbl" style={{ color: 'var(--navy-200)' }}>Étape actuelle</span>
                        <strong style={{ fontSize: 'var(--text-lg)', color: '#fff' }}>{banniere.etape || tache.etape_libelle || '—'}</strong>
                        <span style={{ color: '#D1E0F6', fontSize: 'var(--text-sm)' }}>
                            Dernière action : {banniere.derniere_action || 'aucune'} · {banniere.dernier_acteur || '—'}
                        </span>
                    </div>
                    <div className="stack-sm" style={{ alignItems: 'flex-end', textAlign: 'right' }}>
                        <span className="lbl" style={{ color: 'var(--navy-200)' }}>Action requise</span>
                        <span className="badge" style={{ background: 'var(--gold-500)', color: 'var(--navy-950)' }}>{actionRequise || '—'}</span>
                        <span style={{ color: '#D1E0F6', fontSize: 'var(--text-sm)' }}>Acteur attendu : {banniere.acteur_attendu || tache.role_libelle}</span>
                    </div>
                </div>
            </SectionCard>

            <InfoGrid
                label="Informations clés"
                items={[
                    { label: 'Étape', value: tache.etape_libelle, icon: faRoute },
                    { label: 'Acteur attendu', value: tache.role_libelle, icon: faUserTie },
                    { label: 'Action requise', value: tache.action_libelle, icon: faFlag },
                    { label: 'Échéance', value: dateFr(tache.echeance), icon: faClock, hint: tache.en_retard ? <span className="text-danger">En retard de {-tache.delai_jours} j</span> : undefined, mono: true },
                ]}
            />

            {tache.prise_par && (
                <Alert tone={tache.prise_par_moi ? 'success' : 'info'}>
                    {tache.prise_par_moi ? 'Vous avez pris cette tâche en charge' : `Prise en charge par ${tache.prise_par}`}
                    {tache.prise_le ? ` depuis le ${dateHeure(tache.prise_le)}` : ''}.
                    {!tache.prise_par_moi && ' Vos collègues titulaires du rôle la voient sans la traiter en double.'}
                </Alert>
            )}

            <ErrorMessage error={error} onClose={() => setError('')} />

            <div className="liq-split">
                <div className="stack">
                    <SectionCard title="Contexte du dossier" icon={faBuilding}>
                        <KeyValueList items={[
                            { label: 'Demandeur', value: tache.demandeur },
                            { label: 'Structure', value: tache.structure },
                            { label: 'Priorité', value: tache.priorite_libelle },
                            { label: 'Statut', value: tache.statut_libelle },
                            { label: 'Durée de traitement', value: tache.duree_heures !== null && tache.duree_heures !== undefined ? `${tache.duree_heures} h` : null, hidden: tache.duree_heures === null || tache.duree_heures === undefined },
                            { label: 'Délégation', value: tache.delegation?.titulaire ? `Visible par délégation de ${tache.delegation.titulaire} (${tache.delegation.du} → ${tache.delegation.au})` : null, hidden: !tache.delegation?.titulaire },
                        ]} />
                    </SectionCard>

                    {tache.pap && (
                        <SectionCard title="Activité PAP" icon={ICON.planning}>
                            <KeyValueList items={[
                                { label: 'Activité', value: tache.pap.activite },
                                { label: 'Indicateur', value: tache.pap.indicateur },
                                { label: 'Cible', value: tache.pap.cible },
                                { label: 'Bénéficiaires', value: tache.pap.beneficiaires },
                                { label: 'Unité responsable', value: tache.pap.unite_responsable },
                                { label: 'Période', value: `${tache.pap.debut || '—'} → ${tache.pap.fin || '—'}` },
                            ]} />
                        </SectionCard>
                    )}

                    <SectionCard title="Chronologie" icon={ICON.history}>
                        <WorkflowTimeline events={(tache.chronologie ?? []).map((event) => ({
                            action: event.action,
                            actor: event.systeme ? 'Système' : event.acteur,
                            date: event.le,
                            detail: [event.circuit, event.vers ? `vers ${event.vers}` : null].filter(Boolean).join(' · '),
                            system: event.systeme,
                        }))} />
                    </SectionCard>

                    <SectionCard title="Observations" icon={ICON.comment} subtitle={`${(tache.commentaires ?? []).length} observation(s)`}>
                        {(tache.commentaires ?? []).length === 0 && !tache.peut_agir && <EmptyState icon={ICON.comment} title="Aucune observation" compact />}
                        {(tache.commentaires ?? []).map((comment, index) => (
                            <article key={index} className="stack-sm" style={{ gap: 4, paddingBottom: 12, borderBottom: '1px solid var(--color-divider)' }}>
                                <div className="cluster">
                                    <span className="avatar size-sm" aria-hidden="true">{String(comment.auteur ?? '?').split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase()}</span>
                                    <strong>{comment.auteur}</strong>
                                    <span className="subtle">{comment.fonction} · {dateHeure(comment.le)}</span>
                                </div>
                                <p style={{ paddingLeft: 36 }}>{comment.texte}</p>
                            </article>
                        ))}
                        {tache.peut_agir && (
                            <form onSubmit={commenter} className="stack">
                                <FormField label="Nouvelle observation" required hint="L’observation est versée au dossier et visible des acteurs du circuit.">
                                    <textarea className="inp" rows={3} value={texte} onChange={(event) => setTexte(event.target.value)} />
                                </FormField>
                                <div className="form-actions">
                                    <Button variant="primary" type="submit" icon={ICON.comment} loading={pending === 'commenter'} disabled={!texte.trim()}>Ajouter l’observation</Button>
                                </div>
                            </form>
                        )}
                    </SectionCard>
                </div>

                <aside className="stack">
                    {tache.finances && (
                        <SectionCard title="Ligne budgétaire" icon={ICON.budget}>
                            <KeyValueList compact items={[
                                { label: 'Révisé', value: fcfa(tache.finances.revise) },
                                { label: 'Engagé', value: fcfa(tache.finances.engage) },
                                { label: 'Liquidé', value: fcfa(tache.finances.liquide) },
                                { label: 'Ordonnancé', value: fcfa(tache.finances.ordonnance) },
                                { label: 'Payé', value: fcfa(tache.finances.paye) },
                                { label: 'Disponible', value: fcfa(tache.finances.disponible), strong: true },
                            ]} />
                            <span className="subtle">Montants en FCFA, lus dans la chaîne de dépense.</span>
                        </SectionCard>
                    )}
                    <SectionCard title="Pièces" icon={ICON.attachment}>
                        <DocumentList
                            items={(tache.documents ?? []).map((document) => ({ name: document.nom, meta: [document.type, document.code].filter(Boolean).join(' · ') }))}
                            emptyTitle="Aucune pièce liée"
                        />
                    </SectionCard>
                    <p className="subtle" style={{ display: 'flex', gap: 8 }}>
                        <FontAwesomeIcon icon={ICON.lock} style={{ marginTop: 3 }} />
                        Les décisions formelles (validation, visa, signature) se prennent sur la fiche du dossier.
                    </p>
                </aside>
            </div>
        </main>
    );
}
