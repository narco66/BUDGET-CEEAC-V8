import { faBan, faBuildingColumns, faClock, faFlag, faKey, faRoute, faUserTie } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import AuditChronologie from '../../audit/AuditChronologie';
import GedDossier from '../../ged/GedDossier';
import {
    ActionMenu,
    Alert,
    AmountInput,
    Button,
    ChainTrail,
    Checklist,
    ChecklistSummary,
    DocumentList,
    ErrorMessage,
    FileDrop,
    FormField,
    ICON,
    InfoGrid,
    KeyValueList,
    Modal,
    NatureBadge,
    PageError,
    PageHeader,
    PageSkeleton,
    SectionCard,
    StatusBadge,
    Tabs,
    useDialogs,
    useToast,
    WorkflowTimeline,
    type CheckItem,
} from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';
import ActesDossier from '../../commitments/ActesDossier';

const TABS = [
    ['synthese', 'Synthèse'],
    ['ordonnancement', 'Ordonnancement source'],
    ['beneficiaire', 'Bénéficiaire'],
    ['coordonnees', 'Coordonnées'],
    ['montants', 'Montants'],
    ['controles', 'Contrôles comptables'],
    ['pap', 'PAP'],
    ['pieces', 'Pièces'],
    ['historique', 'Historique'],
    ['rapprochement', 'Rapprochement'],
];

const EMPTY_FORM = {
    mode: 'virement',
    compte_bancaire_id: '',
    compte_ceeac: '',
    motif: '',
};

const MODES = { virement: 'Virement', cheque: 'Chèque', caisse: 'Caisse' };

/** Actions qui ne demandent qu’un motif obligatoire. */
const MOTIF_ACTIONS: Record<string, { title: string; confirm: string; endpoint: string; tone: 'warning' | 'danger' | 'default'; success: string }> = {
    retourner: { title: 'Retourner le paiement', confirm: 'Retourner', endpoint: 'retourner', tone: 'warning', success: 'Paiement retourné.' },
    rejeter: { title: 'Rejeter le paiement', confirm: 'Confirmer le rejet', endpoint: 'rejeter', tone: 'danger', success: 'Paiement rejeté.' },
    suspendre: { title: 'Suspendre le paiement', confirm: 'Suspendre', endpoint: 'suspendre', tone: 'warning', success: 'Paiement suspendu.' },
    rejet: { title: 'Enregistrer un rejet bancaire', confirm: 'Enregistrer le rejet', endpoint: 'rejet-bancaire', tone: 'danger', success: 'Rejet bancaire enregistré.' },
    reemettre: { title: 'Réémettre le paiement', confirm: 'Réémettre', endpoint: 'reemettre', tone: 'default', success: 'Paiement réémis.' },
    'lever-suspension': { title: 'Lever la suspension', confirm: 'Lever la suspension', endpoint: 'lever-suspension', tone: 'default', success: 'Suspension levée.' },
};

export default function PayFiche() {
    const { id } = useParams();
    const toast = useToast();
    const { prompt } = useDialogs();
    const [dossier, setDossier] = useState<any>(null);
    const [loadError, setLoadError] = useState('');
    const [tab, setTab] = useState('synthese');
    const [form, setForm] = useState(EMPTY_FORM);
    const [modal, setModal] = useState<'signer' | 'executer' | null>(null);
    const [confirmed, setConfirmed] = useState(false);
    const [password, setPassword] = useState('');
    const [eligibles, setEligibles] = useState<any>({ tiers: null, data: [] });
    const [montant, setMontant] = useState('');
    const [reference, setReference] = useState('');
    const [dateValeur, setDateValeur] = useState('');
    const [preuve, setPreuve] = useState<File | null>(null);
    const [error, setError] = useState('');
    const [pending, setPending] = useState<string | null>(null);

    function load() {
        api.get(`/paiements/${id}`).then((response) => {
            const data = response.data.data;
            setDossier(data);
            setLoadError('');
            setForm({
                mode: data.mode || 'virement',
                compte_bancaire_id: data.compte_bancaire_id ? String(data.compte_bancaire_id) : '',
                compte_ceeac: data.compte_ceeac || '',
                motif: data.motif_reglement || '',
            });
            setMontant(String(data.reste || ''));
            if (data.actions?.preparer) {
                api.get(`/paiements/${id}/comptes-eligibles`).then((accounts) => setEligibles(accounts.data));
            }
        }).catch((caught) => setLoadError(errorsOf(caught)));
    }

    function preparePayload() {
        return {
            ...form,
            compte_bancaire_id: form.mode === 'caisse' || !form.compte_bancaire_id ? null : Number(form.compte_bancaire_id),
        };
    }

    useEffect(() => {
        load();
    }, [id]);

    async function soumettre() {
        setError('');
        setPending('soumettre');
        try {
            await api.post(`/paiements/${id}/preparer`, preparePayload());
            await api.post(`/paiements/${id}/soumettre`);
            toast.success('Paiement soumis au Chef Comptable.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
            toast.error(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function act(path: string, payload: any = {}, success?: string) {
        setError('');
        setPending(path);
        try {
            await api.post(`/paiements/${id}/${path}`, payload);
            setModal(null);
            setConfirmed(false);
            setReference('');
            setPreuve(null);
            if (success) toast.success(success);
            load();
        } catch (caught) {
            setError(errorsOf(caught));
            toast.error(errorsOf(caught));
        } finally {
            setPassword('');
            setPending(null);
        }
    }

    async function motifAction(key: string) {
        const meta = MOTIF_ACTIONS[key];
        const values = await prompt({
            title: meta.title,
            description: `${dossier.reference} · reste ${fcfa(dossier.reste)} FCFA`,
            confirmLabel: meta.confirm,
            tone: meta.tone,
            fields: [{ name: 'motif', label: 'Motif', type: 'textarea', required: true, maxLength: 255 }],
        });
        if (values) act(meta.endpoint, { motif: values.motif }, meta.success);
    }

    async function rapprocher() {
        const values = await prompt({
            title: 'Rapprocher le paiement',
            description: 'Concordance avec le relevé bancaire ou de caisse.',
            confirmLabel: 'Rapprocher',
            icon: ICON.reconciliation,
            fields: [{ name: 'reference', label: 'Référence du relevé', required: true, maxLength: 64 }],
        });
        if (values) act('rapprocher', { reference: values.reference }, 'Paiement rapproché.');
    }

    function executer() {
        if (!preuve) {
            setError('Joignez l’avis bancaire ou l’acquit.');
            return;
        }
        const body = new FormData();
        body.append('montant', montant);
        body.append('reference', reference);
        body.append('date_valeur', dateValeur);
        body.append('preuve', preuve);
        act('executer', body, 'Exécution enregistrée.');
    }

    if (!dossier) {
        return loadError ? <PageError message={loadError} onRetry={load} /> : <PageSkeleton variant="detail" />;
    }

    const actions = dossier.actions;
    const controles: CheckItem[] = (dossier.controles ?? []).map((item) => ({ label: item.point, ok: item.ok, detail: item.detail, blocking: item.bloquant }));

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/paiements', label: 'Paiements' }}
                eyebrow={(
                    <>
                        <span className="mono strong">{dossier.reference}</span>
                        <StatusBadge statut={dossier.statut} libelle={dossier.statut_libelle} />
                        {dossier.nature_libelle && <NatureBadge nature={dossier.nature} libelle={dossier.nature_libelle} />}
                    </>
                )}
                title={dossier.objet}
                meta={(
                    <ChainTrail current="PAY" links={{
                        EB: { reference: dossier.eb_reference, to: `/expressions-besoin/${dossier.expression_besoin_id}` },
                        ENG: { reference: dossier.engagement, to: `/engagements/${dossier.engagement_id}` },
                        LIQ: { reference: dossier.liquidation, to: `/liquidations/${dossier.liquidation_id}` },
                        ORD: { reference: dossier.ordonnancement, to: `/ordonnancements/${dossier.ordonnancement_id}` },
                        PAY: { reference: dossier.reference },
                    }} />
                )}
                figure={{ label: 'Reste à payer', value: fcfa(dossier.reste), unit: 'FCFA' }}
                actions={(
                    <>
                        {actions.prendre_en_charge && <Button variant="brand" icon={ICON.takeCharge} loading={pending === 'prendre-en-charge'} onClick={() => act('prendre-en-charge', {}, 'Paiement pris en charge.')}>Prendre en charge</Button>}
                        {actions.preparer && <Button icon={ICON.save} loading={pending === 'preparer'} onClick={() => act('preparer', preparePayload(), 'Préparation enregistrée.')}>Enregistrer la préparation</Button>}
                        {actions.preparer && <Button variant="primary" icon={ICON.submit} loading={pending === 'soumettre'} onClick={soumettre}>Soumettre au Chef Comptable</Button>}
                        {actions.valider && <Button variant="primary" icon={ICON.validate} loading={pending === 'valider'} onClick={() => act('valider', {}, 'Paiement validé.')}>Valider</Button>}
                        {actions.signer && <Button variant="primary" icon={ICON.sign} onClick={() => setModal('signer')}>Autoriser le règlement</Button>}
                        {actions.executer && <Button variant="primary" icon={ICON.execute} onClick={() => { setError(''); setModal('executer'); }}>Exécuter</Button>}
                        {actions.rapprocher && <Button variant="primary" icon={ICON.reconciliation} onClick={rapprocher}>Rapprocher</Button>}
                        {actions.reemettre && <Button variant="brand" icon={ICON.retry} onClick={() => motifAction('reemettre')}>Réémettre</Button>}
                        {actions.lever_suspension && <Button variant="brand" icon={ICON.resume} onClick={() => motifAction('lever-suspension')}>Lever la suspension</Button>}
                        {actions.retourner && <Button variant="warning" icon={ICON.return} onClick={() => motifAction('retourner')}>Retourner</Button>}
                        {actions.rejeter && <Button variant="danger-outline" icon={ICON.reject} onClick={() => motifAction('rejeter')}>Rejeter</Button>}
                        <ActionMenu actions={[
                            { label: 'Suspendre', icon: ICON.suspend, onSelect: () => motifAction('suspendre'), hidden: !actions.suspendre },
                            { label: 'Rejet bancaire', icon: faBan, onSelect: () => motifAction('rejet'), hidden: !actions.rejet_bancaire, danger: true },
                            { label: 'Preuve de paiement (PDF)', icon: ICON.pdf, href: `/api/v1/paiements/${dossier.id}/pdf`, hidden: !actions.pdf, separatorBefore: actions.suspendre || actions.rejet_bancaire },
                        ]} />
                    </>
                )}
            />

            <InfoGrid
                label="Situation du paiement"
                items={[
                    { label: 'Étape', value: dossier.etape, icon: faRoute },
                    { label: 'Acteur attendu', value: dossier.acteur_attendu, icon: faUserTie },
                    { label: 'Dernière action', value: dossier.derniere_action, icon: ICON.history },
                    { label: 'Échéance', value: dossier.echeance, icon: faClock, mono: true },
                ]}
            />
            <InfoGrid
                label="Montants du paiement"
                items={[
                    { label: 'Ordonnancé', value: `${fcfa(dossier.montant_ordonnance)} FCFA`, mono: true },
                    { label: 'Déjà payé', value: `${fcfa(dossier.montant_paye)} FCFA`, mono: true },
                    { label: 'Paiement en cours', value: dossier.statut_libelle, icon: faFlag },
                    { label: 'Reste', value: `${fcfa(dossier.reste)} FCFA`, mono: true },
                ]}
            />

            <ErrorMessage error={modal ? '' : error} onClose={() => setError('')} />
            {(dossier.retour || dossier.rejet || dossier.rejet_bancaire) && (
                <Alert tone={dossier.retour ? 'warning' : 'danger'} title={dossier.retour ? 'Paiement retourné' : dossier.rejet ? 'Paiement rejeté' : 'Rejet bancaire'}>
                    {dossier.retour || dossier.rejet || dossier.rejet_bancaire}
                </Alert>
            )}

            <ActesDossier dossier={dossier} base="/api/v1/paiements" />
            <Tabs label="Sections du paiement" value={tab} onChange={setTab} items={TABS.map(([value, label]) => ({ value, label, icon: value === 'coordonnees' && actions.preparer ? ICON.edit : undefined }))} />

            {tab === 'synthese' && (
                <SectionCard title="Synthèse" icon={ICON.document} tag={controles.length > 0 ? <ChecklistSummary items={controles} /> : undefined}>
                    <KeyValueList items={[
                        { label: 'Bénéficiaire', value: dossier.beneficiaire, strong: true },
                        { label: 'Structure', value: dossier.structure },
                        { label: 'Ligne', value: `${dossier.ligne || ''} ${dossier.ligne_libelle || ''}`.trim() },
                        { label: 'Mode', value: MODES[dossier.mode] || dossier.mode || 'À choisir' },
                    ]} />
                </SectionCard>
            )}
            {tab === 'ordonnancement' && (
                <SectionCard title="Ordonnancement source" icon={ICON.order} actions={<Button size="sm" to={`/ordonnancements/${dossier.ordonnancement_id}`} iconRight={ICON.open}>Ouvrir l’ordre</Button>}>
                    <KeyValueList items={[
                        { label: 'Ordre', value: dossier.ordonnancement, mono: true },
                        { label: 'Signature', value: dossier.signature, mono: true },
                        { label: 'Liquidation', value: `${dossier.liquidation} · ${dossier.visa || ''}` },
                        { label: 'Engagement', value: dossier.engagement, mono: true },
                    ]} />
                </SectionCard>
            )}
            {tab === 'beneficiaire' && (
                <SectionCard title="Bénéficiaire" icon={faUserTie}>
                    <KeyValueList items={[{ label: 'Bénéficiaire', value: dossier.beneficiaire, strong: true }]} />
                </SectionCard>
            )}
            {tab === 'coordonnees' && (
                <SectionCard title="Coordonnées de règlement" icon={faBuildingColumns} subtitle={actions.preparer ? 'Préparation par l’Agence Comptable' : undefined}>
                    {actions.preparer ? (
                        <div className="form-grid">
                            <FormField label="Mode de règlement" required>
                                <select className="inp" value={form.mode} onChange={(event) => setForm({ ...form, mode: event.target.value })}>
                                    <option value="virement">Virement</option>
                                    <option value="cheque">Chèque</option>
                                    <option value="caisse">Caisse</option>
                                </select>
                            </FormField>
                            <FormField label="Compte CEEAC débité">
                                <input className="inp mono" value={form.compte_ceeac} onChange={(event) => setForm({ ...form, compte_ceeac: event.target.value })} />
                            </FormField>
                            {form.mode !== 'caisse' && (
                                <FormField
                                    label="Compte validé du bénéficiaire"
                                    required
                                    className="span-all"
                                    hint={`Tiers : ${eligibles.tiers ? `${eligibles.tiers.code} · ${eligibles.tiers.raison_sociale} (${eligibles.tiers.statut})` : 'aucun tiers rattaché — créez la fiche dans le référentiel Tiers'}`}
                                >
                                    <select className="inp" value={form.compte_bancaire_id} onChange={(event) => setForm({ ...form, compte_bancaire_id: event.target.value })}>
                                        <option value="">Choisir un compte validé…</option>
                                        {eligibles.data.map((account) => (
                                            <option key={account.id} value={account.id}>
                                                {account.banque} · {account.numero_masque} · {account.titulaire}{account.vigilance ? ' · vigilance' : ''}
                                            </option>
                                        ))}
                                    </select>
                                </FormField>
                            )}
                            {form.mode !== 'caisse' && eligibles.data.length === 0 && (
                                <div className="span-all"><Alert tone="danger" title="Aucun compte validé">Un compte doit être saisi puis validé par un second acteur dans le référentiel Tiers.</Alert></div>
                            )}
                            <FormField label="Motif du règlement" className="span-all">
                                <input className="inp" value={form.motif} onChange={(event) => setForm({ ...form, motif: event.target.value })} />
                            </FormField>
                            <div className="span-all"><Alert tone="info">Les coordonnées bancaires proviennent du référentiel et sont figées dans le paiement. Le plafond de caisse est de 500 000 FCFA.</Alert></div>
                        </div>
                    ) : (
                        <KeyValueList items={[
                            { label: 'Mode', value: MODES[dossier.mode] || dossier.mode },
                            { label: 'Banque', value: dossier.banque },
                            { label: 'Agence', value: dossier.agence },
                            { label: 'Compte', value: dossier.compte, mono: true },
                            { label: 'Titulaire', value: dossier.titulaire },
                            { label: 'Compte CEEAC', value: dossier.compte_ceeac || 'Non renseigné', mono: Boolean(dossier.compte_ceeac) },
                            { label: 'Compte modifié', value: dossier.compte_modifie ? 'Oui' : 'Non', warning: dossier.compte_modifie },
                        ]} />
                    )}
                </SectionCard>
            )}
            {tab === 'montants' && (
                <SectionCard title="Montants" icon={ICON.budget}>
                    <KeyValueList compact items={[
                        { label: 'Engagé', value: `${fcfa(dossier.montant_engage)} FCFA` },
                        { label: 'Liquidé brut', value: `${fcfa(dossier.montant_brut)} FCFA` },
                        { label: 'Retenues', value: `${fcfa(dossier.retenues)} FCFA` },
                        { label: 'Net ordonnancé', value: `${fcfa(dossier.montant_ordonnance)} FCFA`, strong: true },
                        { label: 'Déjà payé', value: `${fcfa(dossier.montant_paye)} FCFA` },
                        { label: 'Reste', value: `${fcfa(dossier.reste)} FCFA`, strong: true },
                    ]} />
                </SectionCard>
            )}
            {tab === 'controles' && (
                <SectionCard title="Contrôles comptables" icon={ICON.entry} tag={<ChecklistSummary items={controles} />}>
                    <Checklist items={controles} />
                </SectionCard>
            )}
            {tab === 'pap' && (
                <SectionCard title="Rattachement PAP" icon={ICON.planning}>
                    {dossier.pap ? (
                        <KeyValueList items={[
                            { label: 'Pilier', value: dossier.pap.pilier },
                            { label: 'Axe', value: dossier.pap.axe },
                            { label: 'Produit', value: dossier.pap.produit },
                            { label: 'Activité', value: dossier.pap.activite },
                            { label: 'Indicateur', value: dossier.pap.indicateur },
                        ]} />
                    ) : <p className="muted">Dépense hors PAP, ou fiche PAP non renseignée.</p>}
                </SectionCard>
            )}
            {tab === 'pieces' && (
                <SectionCard title="Pièces" icon={ICON.attachment}>
                    <DocumentList items={(dossier.pieces ?? []).map((piece) => ({ name: piece.nom, meta: piece.type }))} emptyTitle="Aucune pièce" emptyText="Aucune pièce héritée de l’expression de besoin." />
                    {id && <GedDossier type="paiement" entityId={Number(id)} />}
                    {id && <AuditChronologie type="paiement" entityId={Number(id)} />}
                </SectionCard>
            )}
            {tab === 'historique' && (
                <SectionCard title="Historique" icon={ICON.history}>
                    <WorkflowTimeline events={(dossier.historique ?? []).map((event) => ({ action: event.action, actor: event.acteur || 'Système', date: event.le, note: event.motif, system: !event.acteur }))} />
                </SectionCard>
            )}
            {tab === 'rapprochement' && (
                <div className="layout-aside is-wide">
                    <SectionCard title="Exécutions bancaires" icon={ICON.execute}>
                        <WorkflowTimeline
                            emptyText="Aucune exécution enregistrée."
                            events={(dossier.executions ?? []).map((execution) => ({
                                action: `Exécution n° ${execution.rang} · ${fcfa(execution.montant)} FCFA`,
                                date: execution.date_valeur || '—',
                                detail: `${execution.reference} · ${execution.preuve ? 'avis joint' : 'sans avis'}`,
                                note: execution.statut === 'rejetee' ? `Rejetée : ${execution.motif_rejet || 'sans motif'}` : undefined,
                                actor: execution.statut === 'rejetee' ? 'Rejet bancaire' : 'Exécutée',
                            }))}
                        />
                    </SectionCard>
                    <SectionCard title="Rapprochement" icon={ICON.reconciliation}>
                        <KeyValueList items={[
                            { label: 'Référence de règlement', value: dossier.reference_reglement, mono: true },
                            { label: 'Date de valeur', value: dossier.date_valeur, mono: true },
                            { label: 'Date officielle', value: dossier.date_valeur ? 'Date d’exécution effective' : 'Non exécuté' },
                            { label: 'Rapprochement', value: dossier.rapprochement || 'En attente' },
                            { label: 'Rapproché le', value: dossier.rapproche_le, mono: true },
                        ]} />
                    </SectionCard>
                </div>
            )}

            {modal === 'signer' && (
                <Modal
                    title="Autoriser le règlement"
                    description={`${dossier.reference} · ${fcfa(dossier.reste)} FCFA`}
                    icon={ICON.sign}
                    tone="warning"
                    onClose={() => setModal(null)}
                    footer={(
                        <>
                            <span className="modal-footer-note">Le mot de passe n’est pas conservé.</span>
                            <Button onClick={() => setModal(null)}>Annuler</Button>
                            <Button variant="primary" icon={ICON.sign} disabled={!confirmed || !password} loading={pending === 'signer'} onClick={() => act('signer', { confirmation: confirmed, mot_de_passe: password }, 'Règlement autorisé.')}>Autoriser</Button>
                        </>
                    )}
                >
                    <label className={`choice${confirmed ? ' is-checked' : ''}`}>
                        <input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} />
                        <span>Je confirme l’autorisation de ce règlement.</span>
                    </label>
                    <FormField label="Mot de passe de l’Agent Comptable" required>
                        <div className="input-group has-icon">
                            <FontAwesomeIcon icon={faKey} className="input-icon" />
                            <input className="inp" type="password" autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} />
                        </div>
                    </FormField>
                    <ErrorMessage error={error} title="Autorisation refusée" />
                </Modal>
            )}

            {modal === 'executer' && (
                <Modal
                    title="Exécuter le règlement"
                    description={`${dossier.reference} · reste ${fcfa(dossier.reste)} FCFA`}
                    icon={ICON.execute}
                    onClose={() => setModal(null)}
                    footer={(
                        <>
                            <Button onClick={() => setModal(null)}>Annuler</Button>
                            <Button variant="primary" icon={ICON.execute} disabled={!montant || !reference || !dateValeur} loading={pending === 'executer'} onClick={executer}>Enregistrer l’exécution</Button>
                        </>
                    )}
                >
                    <div className="form-grid">
                        <FormField label="Montant exécuté" required className="span-all">
                            <AmountInput value={montant} onChange={setMontant} inputMode="numeric" />
                        </FormField>
                        <FormField label="Référence" required><input className="inp mono" value={reference} onChange={(event) => setReference(event.target.value)} maxLength={64} /></FormField>
                        <FormField label="Date de valeur" required><input className="inp" type="date" value={dateValeur} onChange={(event) => setDateValeur(event.target.value)} /></FormField>
                    </div>
                    <div className="field">
                        <span className="field-label">Avis bancaire ou acquit <span className="field-required" aria-hidden="true">*</span></span>
                        <FileDrop file={preuve} onFile={setPreuve} accept="application/pdf,image/png,image/jpeg" hint="PDF, PNG ou JPEG." label="Choisir l’avis" />
                    </div>
                    <ErrorMessage error={error} title="Exécution refusée" />
                </Modal>
            )}
        </main>
    );
}
