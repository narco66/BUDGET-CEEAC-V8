import { faUserTie, faWallet } from '@fortawesome/free-solid-svg-icons';
import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import AuditChronologie from '../../audit/AuditChronologie';
import GedDossier from '../../ged/GedDossier';
import {
    ActionMenu,
    Alert,
    Button,
    DataTable,
    DocumentList,
    EmptyState,
    ErrorMessage,
    ICON,
    InfoGrid,
    KeyValueList,
    NatureBadge,
    PageError,
    PageHeader,
    PageSkeleton,
    ProgressBar,
    SectionCard,
    StatusBadge,
    Stepper,
    useDialogs,
    useToast,
    WorkflowTimeline,
    type Column,
} from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';
import ReturnModal from '../components/ReturnModal';

const SUCCESS: Record<string, string> = {
    soumettre: 'Expression de besoin soumise au circuit de validation.',
    valider: 'Expression de besoin validée.',
    approuver: 'Expression de besoin approuvée.',
    transformer: 'Engagement généré à partir de l’expression de besoin.',
    retourner: 'Expression de besoin retournée à son initiateur.',
    rejeter: 'Expression de besoin rejetée.',
};

export default function EbFiche() {
    const { id } = useParams();
    const navigate = useNavigate();
    const toast = useToast();
    const { confirm } = useDialogs();
    const [dossier, setDossier] = useState<any>(null);
    const [modal, setModal] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [loadError, setLoadError] = useState('');
    const [pending, setPending] = useState<string | null>(null);

    function load() {
        return api.get(`/expressions-besoin/${id}`)
            .then((response) => { setDossier(response.data.data); setLoadError(''); })
            .catch((caught) => setLoadError(errorsOf(caught)));
    }

    useEffect(() => {
        load();
    }, [id]);

    async function act(action: string, payload: Record<string, unknown> = {}) {
        setError('');
        setPending(action);
        try {
            const response = await api.post(`/expressions-besoin/${id}/${action}`, payload || {});
            if (action === 'dupliquer') {
                toast.success('Copie modifiable créée.');
                navigate(`/expressions-besoin/${response.data.data.id}/modifier`);
                return;
            }
            setModal(null);
            if (SUCCESS[action]) toast.success(SUCCESS[action]);
            await load();
        } catch (caught) {
            setError(errorsOf(caught));
            toast.error(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function decide(action: 'valider' | 'approuver' | 'transformer', title: string, description: string) {
        if (await confirm({ title, description, confirmLabel: title, tone: 'success', icon: action === 'transformer' ? ICON.transform : ICON.validate })) {
            act(action);
        }
    }

    if (!dossier) {
        return loadError ? <PageError message={loadError} onRetry={load} /> : <PageSkeleton variant="detail" />;
    }
    const actions = dossier.actions || {};
    const decision = actions.valider || actions.approuver || actions.retourner || actions.rejeter;

    const lineColumns: Column<any>[] = [
        { key: 'designation', header: 'Désignation', render: (line) => <div><div style={{ fontWeight: 500 }}>{line.designation}</div>{line.observation && <div className="cell-sub">{line.observation}</div>}</div> },
        { key: 'beneficiaire', header: 'Bénéficiaire', render: (line) => line.beneficiaire || '—' },
        { key: 'lieu', header: 'Lieu', render: (line) => line.lieu || '—' },
        { key: 'quantite', header: 'Qté', align: 'right', render: (line) => <span className="num">{line.quantite} {line.unite}</span> },
        { key: 'pu', header: 'PU', align: 'right', className: 'mono', render: (line) => fcfa(line.prix_unitaire) },
        { key: 'montant', header: 'Montant', align: 'right', className: 'cell-amount', render: (line) => fcfa(line.montant) },
    ];

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/expressions-besoin', label: 'Expressions de besoin' }}
                eyebrow={(
                    <>
                        <span className="mono strong">{dossier.reference}</span>
                        <StatusBadge statut={dossier.statut} libelle={dossier.statut_libelle} />
                        <NatureBadge nature={dossier.nature} libelle={dossier.nature_libelle} />
                    </>
                )}
                title={dossier.objet}
                subtitle={dossier.structure}
                actions={(
                    <>
                        <Button href={`/api/v1/expressions-besoin/${dossier.id}/apercu`} icon={ICON.pdf}>Aperçu PDF</Button>
                        {actions.modifier && <Button to={`/expressions-besoin/${dossier.id}/modifier`} icon={ICON.edit}>Modifier</Button>}
                        {actions.retourner && <Button variant="warning" icon={ICON.return} onClick={() => setModal('retour')}>Retourner</Button>}
                        {actions.rejeter && <Button variant="danger-outline" icon={ICON.reject} onClick={() => setModal('rejet')}>Rejeter</Button>}
                        {actions.soumettre && <Button variant="primary" icon={ICON.submit} loading={pending === 'soumettre'} onClick={() => act('soumettre')}>Soumettre</Button>}
                        {actions.valider && <Button variant="primary" icon={ICON.validate} loading={pending === 'valider'} onClick={() => decide('valider', 'Valider l’expression de besoin', `Le dossier ${dossier.reference} passe à l’étape suivante du circuit.`)}>Valider</Button>}
                        {actions.approuver && <Button variant="primary" icon={ICON.approve} loading={pending === 'approuver'} onClick={() => decide('approuver', 'Approuver l’expression de besoin', `L’approbation de ${dossier.reference} permet la génération de l’engagement.`)}>Approuver</Button>}
                        {actions.generer_engagement && <Button variant="brand" icon={ICON.transform} loading={pending === 'transformer'} onClick={() => decide('transformer', 'Générer l’engagement', `Un engagement sera créé à partir de ${dossier.reference} pour ${fcfa(dossier.montant)} FCFA.`)}>Générer l’engagement</Button>}
                        <ActionMenu actions={[
                            { label: 'PDF officiel', icon: ICON.pdf, href: `/api/v1/expressions-besoin/${dossier.id}/pdf`, hidden: !actions.pdf },
                            { label: 'Créer une copie modifiable', icon: ICON.duplicate, onSelect: () => act('dupliquer') },
                        ]} />
                    </>
                )}
            />

            <ErrorMessage error={error} onClose={() => setError('')} />

            {dossier.anomalie_acteur && <Alert tone="warning" title="Résolution de l’acteur">{dossier.anomalie_acteur}</Alert>}
            {decision && (
                <Alert tone="info" title="Décision attendue de votre part" strong>
                    Acteur attendu : {dossier.acteur_attendu}{dossier.echeance ? ` · échéance ${dossier.echeance}` : ''}
                </Alert>
            )}
            {dossier.retour_motif && <Alert tone="warning" title="Dossier retourné pour correction">{dossier.retour_motif}</Alert>}
            {dossier.rejet_motif && <Alert tone="danger" title="Dossier rejeté">{dossier.rejet_motif}</Alert>}
            {!actions.modifier && (
                <Alert tone="neutral" icon={ICON.lock} title="Sous-lignes en lecture seule">
                    {dossier.engagement
                        ? `Cette expression est déjà transformée en ${dossier.engagement}. Pour modifier ses informations, créez une copie modifiable ; l’engagement existant reste inchangé.`
                        : 'Cette expression ne peut pas être modifiée à cette étape du circuit. Seul son initiateur peut modifier un brouillon ou un dossier retourné.'}
                </Alert>
            )}

            <InfoGrid
                label="Synthèse financière"
                items={[
                    { label: 'Acteur attendu', value: dossier.acteur_attendu, hint: dossier.delai_libelle, icon: faUserTie },
                    { label: 'Montant', value: `${fcfa(dossier.montant)} FCFA`, mono: true, icon: ICON.budget },
                    { label: 'Disponible', value: `${fcfa(dossier.ligne.disponible)} FCFA`, mono: true, icon: faWallet },
                    { label: 'Solde prévisionnel', value: `${fcfa(dossier.ligne.solde_previsionnel)} FCFA`, mono: true },
                    { label: 'Engagement', value: dossier.engagement, mono: true, icon: ICON.commitment, hidden: !dossier.engagement },
                ]}
            />

            {dossier.circuit?.length > 0 && (
                <SectionCard title="Circuit de validation" icon={ICON.actions} flush>
                    <Stepper
                        label="Circuit de validation"
                        steps={dossier.circuit.map((step) => ({
                            label: step.libelle,
                            state: step.etat === 'fait' ? 'done' : step.etat === 'en_cours' ? 'current' : 'todo',
                            hint: step.echeance ? `Échéance ${step.echeance}` : undefined,
                        }))}
                    />
                </SectionCard>
            )}

            <div className="layout-aside is-wide">
                <div className="stack">
                    <SectionCard title="Sous-lignes" icon={ICON.need} flush subtitle={`${dossier.lignes?.length ?? 0} sous-ligne(s) · total ${fcfa(dossier.montant)} FCFA`}>
                        <DataTable
                            columns={lineColumns}
                            rows={dossier.lignes ?? []}
                            rowKey={(line) => line.id}
                            empty={<EmptyState compact title="Aucune sous-ligne">Aucune sous-ligne n’est enregistrée sur cette expression.</EmptyState>}
                        />
                    </SectionCard>
                    <SectionCard title="Justification" icon={ICON.comment}>
                        {dossier.justification ? <p style={{ whiteSpace: 'pre-line' }}>{dossier.justification}</p> : <span className="muted">Aucune justification saisie.</span>}
                    </SectionCard>
                    <SectionCard title="Pièces justificatives" icon={ICON.attachment}>
                        <DocumentList items={(dossier.documents ?? []).map((document) => ({ name: document.nom, meta: document.type }))} emptyTitle="Aucune pièce jointe" />
                    </SectionCard>
                    {id && <GedDossier type="expression_besoin" entityId={Number(id)} />}
                    {id && <AuditChronologie type="expression_besoin" entityId={Number(id)} />}
                </div>

                <aside className="stack">
                    <SectionCard title="Ligne budgétaire officielle" icon={ICON.budget} tag={<span className="tag tag-budget">BUDGET</span>}>
                        <div>
                            <div className="mono strong">{dossier.ligne.code}</div>
                            <div className="subtle">{dossier.ligne.libelle}</div>
                        </div>
                        <KeyValueList compact items={[
                            { label: 'Voté', value: fcfa(dossier.ligne.montant_vote) },
                            { label: 'Actualisé', value: fcfa(dossier.ligne.actualise) },
                            { label: 'Engagé', value: fcfa(dossier.ligne.engage || 0) },
                        ]} />
                    </SectionCard>
                    {dossier.referentiel && (
                        <SectionCard title="Référentiel PAP" icon={ICON.planning} tag={<span className="tag tag-ref">COMPLÉMENT</span>}>
                            <KeyValueList items={[
                                { label: 'Pilier', value: dossier.referentiel.pilier },
                                { label: 'Axe', value: dossier.referentiel.axe },
                                { label: 'Activité', value: dossier.referentiel.activite },
                            ]} />
                            <div className="stack-sm">
                                <div className="split"><span className="lbl">Complétude</span><span className="mono strong">{dossier.completude} %</span></div>
                                <ProgressBar value={dossier.completude} label="Complétude du référentiel PAP" color={dossier.completude >= 80 ? 'var(--green-600)' : dossier.completude >= 60 ? 'var(--warning-solid)' : 'var(--danger-solid)'} />
                            </div>
                        </SectionCard>
                    )}
                    <SectionCard title="Historique" icon={ICON.history}>
                        <WorkflowTimeline events={(dossier.historique ?? []).map((event) => ({ action: event.action, actor: event.acteur || 'Système', date: event.date, note: event.motif, system: !event.acteur }))} />
                    </SectionCard>
                </aside>
            </div>

            {modal && (
                <ReturnModal
                    mode={modal === 'retour' ? 'retour' : 'rejet'}
                    title={modal === 'retour' ? 'Retourner pour correction' : 'Rejeter l’expression de besoin'}
                    reference={dossier.reference}
                    initiateur={dossier.initiateur}
                    onClose={() => setModal(null)}
                    onConfirm={(payload) => act(modal === 'retour' ? 'retourner' : 'rejeter', payload)}
                />
            )}
        </main>
    );
}
