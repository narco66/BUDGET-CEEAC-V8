import { faBuildingColumns, faKey, faScaleBalanced, faTriangleExclamation, faUserTie } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import AuditChronologie from '../../audit/AuditChronologie';
import GedDossier from '../../ged/GedDossier';
import {
    Alert,
    Badge,
    Button,
    ChainTrail,
    Checklist,
    ChecklistSummary,
    DataTable,
    DocumentList,
    ErrorMessage,
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
    ['decision', 'Décision'],
    ['synthese', 'Synthèse'],
    ['liquidation', 'Liquidation source'],
    ['budget', 'Situation budgétaire'],
    ['beneficiaire', 'Bénéficiaire'],
    ['factures', 'Factures'],
    ['retenues', 'Retenues'],
    ['pap', 'PAP'],
    ['marche', 'Marché'],
    ['pieces', 'Pièces'],
    ['controles', 'Contrôles'],
    ['historique', 'Historique'],
    ['documents', 'Documents'],
];

export default function OrdFiche() {
    const { id } = useParams();
    const navigate = useNavigate();
    const toast = useToast();
    const { prompt } = useDialogs();
    const [dossier, setDossier] = useState<any>(null);
    const [loadError, setLoadError] = useState('');
    const [tab, setTab] = useState('decision');
    const [signOpen, setSignOpen] = useState(false);
    const [confirmed, setConfirmed] = useState(false);
    const [code, setCode] = useState('');
    const [error, setError] = useState('');
    const [pending, setPending] = useState<string | null>(null);

    function load() {
        api.get(`/ordonnancements/${id}`)
            .then((response) => { setDossier(response.data.data); setLoadError(''); })
            .catch((caught) => setLoadError(errorsOf(caught)));
    }

    useEffect(() => {
        load();
    }, [id]);

    async function act(path: string, payload = {}, success?: string) {
        setError('');
        setPending(path);
        try {
            await api.post(`/ordonnancements/${id}/${path}`, payload);
            setSignOpen(false);
            setConfirmed(false);
            setCode('');
            if (success) toast.success(success);
            load();
        } catch (caught) {
            setError(errorsOf(caught));
            toast.error(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function fractionner() {
        const values = await prompt({
            title: 'Ordonnancer partiellement',
            description: 'La première part reste sur cet ordre. Le solde ouvre un second ordre, toujours à signer.',
            confirmLabel: 'Fractionner',
            icon: ICON.need,
            fields: [{ name: 'montant', label: 'Première part (FCFA)', required: true }],
        });
        if (!values) return;
        setError('');
        setPending('fractionner');
        try {
            const response = await api.post(`/ordonnancements/${id}/fractionner`, {
                montant: Number(String(values.montant).replace(/\s/g, '')),
            });
            toast.success('Ordonnancement partiel ouvert.');
            navigate(`/ordonnancements/${response.data.data.id}`);
        } catch (caught) {
            setError(errorsOf(caught));
            toast.error(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function decision(action: 'retourner' | 'rejeter') {
        const rejet = action === 'rejeter';
        const values = await prompt({
            title: rejet ? 'Rejeter l’ordonnancement' : 'Retourner vers la liquidation',
            description: `${dossier.reference} · ${fcfa(dossier.montant_net)} FCFA net`,
            confirmLabel: rejet ? 'Confirmer le rejet' : 'Retourner',
            tone: rejet ? 'danger' : 'warning',
            icon: rejet ? ICON.reject : ICON.return,
            fields: [{ name: 'motif', label: 'Motif', type: 'textarea', required: true }],
        });
        if (values) act(action, { motif: values.motif }, rejet ? 'Ordonnancement rejeté.' : 'Ordonnancement retourné vers la liquidation.');
    }

    if (!dossier) {
        return loadError ? <PageError message={loadError} onRetry={load} /> : <PageSkeleton variant="detail" />;
    }

    const controles: CheckItem[] = (dossier.controles ?? []).map((item) => ({ label: item.point, ok: item.ok, detail: item.detail, blocking: item.bloquant }));
    const pieces = (dossier.pieces ?? []).map((piece) => ({ name: piece.nom, meta: [piece.type, piece.le].filter(Boolean).join(' · ') }));

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/ordonnancements', label: 'Ordonnancements' }}
                eyebrow={(
                    <>
                        <span className="mono strong">{dossier.reference}</span>
                        {dossier.signe && <StatusBadge statut="signe" libelle="Signé" />}
                        <StatusBadge statut={dossier.statut} libelle={dossier.statut_libelle} />
                        {dossier.nature_libelle && <NatureBadge nature={dossier.nature} libelle={dossier.nature_libelle} />}
                    </>
                )}
                title={dossier.objet}
                meta={(
                    <ChainTrail current="ORD" links={{
                        EB: { reference: dossier.eb_reference, to: `/expressions-besoin/${dossier.expression_besoin_id}` },
                        ENG: { reference: dossier.engagement, to: `/engagements/${dossier.engagement_id}` },
                        LIQ: { reference: dossier.liquidation, to: `/liquidations/${dossier.liquidation_id}` },
                        ORD: { reference: dossier.reference },
                        PAY: { reference: dossier.paiement, to: dossier.paiement_id ? `/paiements/${dossier.paiement_id}` : null, pending: 'créé à la transmission' },
                    }} />
                )}
                figure={{ label: 'Net à ordonnancer', value: fcfa(dossier.montant_net), unit: 'FCFA', hint: dossier.lettres }}
                actions={(
                    <>
                        {dossier.actions.signer && <Button variant="danger-outline" icon={ICON.reject} onClick={() => decision('rejeter')}>Rejeter</Button>}
                        {dossier.actions.signer && <Button variant="warning" icon={ICON.return} onClick={() => decision('retourner')}>Retourner</Button>}
                        {dossier.actions.fractionner && <Button icon={ICON.need} onClick={fractionner} loading={pending === 'fractionner'}>Ordonnancer partiellement</Button>}
                        {dossier.actions.signer && <Button variant="primary" icon={ICON.sign} onClick={() => setSignOpen(true)}>Signer l’ordre de paiement</Button>}
                        {dossier.actions.pdf && <Button href={`/api/v1/ordonnancements/${dossier.id}/pdf`} icon={ICON.pdf}>Ordre de paiement</Button>}
                    </>
                )}
            />

            <InfoGrid
                label="Montants"
                items={[
                    { label: 'Engagé', value: fcfa(dossier.montant_engage), mono: true },
                    { label: 'Liquidé brut', value: fcfa(dossier.montant_brut), mono: true },
                    { label: 'Retenues', value: fcfa(dossier.retenues), mono: true },
                    { label: 'Net', value: fcfa(dossier.montant_net), mono: true },
                ]}
            />

            <ErrorMessage error={!signOpen ? error : ''} onClose={() => setError('')} />

            {dossier.transmission_erreur && (
                <SectionCard
                    title="Transmission en erreur · l’ordre de paiement reste signé"
                    icon={faTriangleExclamation}
                    tone="danger"
                    actions={dossier.actions.reprendre && <Button variant="primary" icon={ICON.retry} loading={pending === 'reprendre'} onClick={() => act('reprendre', {}, 'Transmission relancée.')}>Relancer maintenant</Button>}
                >
                    <p>La signature est valide et n’est pas annulée. Seule la transmission technique vers l’Agence Comptable a échoué. Chaque reprise utilise la même clé d’idempotence : aucun second paiement ne peut être créé.</p>
                    <KeyValueList items={[{ label: 'Clé d’idempotence', value: dossier.idempotence, mono: true }]} />
                    <div className="grid-halves">
                        <div className="stack-sm">
                            <h3>Événements déclenchés par la signature</h3>
                            <ul className="list-rows">
                                {(dossier.effets ?? []).map((item) => (
                                    <li key={item.evenement} className="list-row">
                                        <span className="list-row-main"><span className="list-row-title">{item.evenement}</span><span className="list-row-sub">{item.detail}</span></span>
                                        <Badge tone="neutral" size="sm">{item.etat}</Badge>
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <div className="stack-sm">
                            <h3>Accusé de transmission</h3>
                            <KeyValueList items={[
                                { label: 'Destinataire', value: 'Agence Comptable' },
                                { label: 'Statut', value: dossier.paiement ? 'Accusé · paiement créé' : 'Non accusé' },
                                { label: 'Paiement', value: dossier.paiement, mono: true },
                            ]} />
                        </div>
                    </div>
                    <h3>Tentatives de transmission</h3>
                    <div className="card" style={{ boxShadow: 'none' }}>
                        <DataTable
                            compact
                            columns={[
                                { key: 'numero', header: 'N°', render: (item: any) => item.numero },
                                { key: 'le', header: 'Date', className: 'mono', render: (item: any) => item.le },
                                { key: 'resultat', header: 'Résultat', render: (item: any) => item.resultat },
                                { key: 'message', header: 'Message', render: (item: any) => item.message },
                            ]}
                            rows={dossier.journal ?? []}
                            rowKey={(item: any) => item.numero}
                        />
                    </div>
                    <h3>Données de signature</h3>
                    <KeyValueList items={[
                        { label: 'Signataire', value: dossier.signataire },
                        { label: 'Fonction', value: dossier.fonction_signataire },
                        { label: 'Horodatage', value: dossier.signe_le, mono: true },
                        { label: 'Référence', value: dossier.signature, mono: true },
                        { label: 'Version', value: dossier.version_signature },
                        { label: 'Empreinte', value: dossier.empreinte, mono: true },
                    ]} />
                    <Alert tone="neutral" icon={ICON.lock}>Après signature, aucune modification directe n’est possible. Un retrait passe par une procédure de révocation, qui n’est pas ouverte dans ce module.</Alert>
                </SectionCard>
            )}

            {dossier.paiement && !dossier.transmission_erreur && (
                <Alert tone="success" title="Transmis à l’Agence Comptable">
                    Paiement <span className="mono">{dossier.paiement}</span>. La prise en charge par l’agence n’est pas encore suivie dans ce module.
                </Alert>
            )}

            <ActesDossier dossier={dossier} base="/api/v1/ordonnancements" />
            <Tabs label="Sections de l’ordonnancement" value={tab} onChange={setTab} items={TABS.map(([value, label]) => ({ value, label }))} />

            {tab === 'decision' && (
                <div className="layout-aside is-wide">
                    <div className="stack">
                        <SectionCard title="Ordonnateur compétent" icon={faUserTie} tag={<Badge tone={dossier.au_dessus_du_seuil ? 'violet' : 'info'} size="sm"><span className="mono">{dossier.au_dessus_du_seuil ? '>' : '≤'} seuil {fcfa(dossier.seuil)}</span></Badge>}>
                            <strong style={{ fontSize: 'var(--text-md)' }}>{dossier.ordonnateur}</strong>
                            <p className="muted">{dossier.fondement}</p>
                        </SectionCard>
                        <SectionCard title="Bénéficiaire" icon={faBuildingColumns}>
                            <BeneficiaryDetails dossier={dossier} />
                        </SectionCard>
                        <SectionCard title="Budget" icon={ICON.budget}>
                            <KeyValueList items={[
                                { label: 'Imputation', value: `${dossier.ligne ?? '—'} · ${dossier.ligne_libelle ?? ''}` },
                                { label: 'Crédits', value: 'Engagés, la liquidation ne crée pas une seconde réservation' },
                                { label: 'Déjà ordonnancé sur l’engagement', value: `${fcfa(dossier.cumul_ordonnance)} FCFA`, mono: true },
                            ]} />
                        </SectionCard>
                        <SectionCard title="Anomalies" icon={ICON.warning} tone={(dossier.anomalies ?? []).length ? 'warning' : 'default'}>
                            {(dossier.anomalies ?? []).length === 0
                                ? <span className="muted">Aucune anomalie détectée.</span>
                                : <ul style={{ listStyle: 'disc', paddingLeft: 18 }}>{dossier.anomalies.map((item) => <li key={item}>{item}</li>)}</ul>}
                        </SectionCard>
                    </div>
                    <aside className="stack">
                        <SectionCard title="Contrôles avant signature" icon={ICON.entry} tag={<ChecklistSummary items={controles} />}>
                            <Checklist items={controles} />
                        </SectionCard>
                        <SectionCard title="Documents essentiels" icon={ICON.attachment}>
                            <DocumentList
                                items={[...pieces, ...(dossier.facture ? [{ name: `Facture ${dossier.facture}`, meta: dossier.date_facture }] : [])]}
                                emptyTitle="Aucune pièce"
                                emptyText="Aucune pièce héritée de l’expression de besoin."
                            />
                        </SectionCard>
                    </aside>
                </div>
            )}

            {tab === 'synthese' && (
                <SectionCard title="Synthèse" icon={ICON.document}>
                    <KeyValueList items={[
                        { label: 'Étape', value: dossier.statut_libelle },
                        { label: 'Dernière action', value: dossier.derniere_action },
                        { label: 'Acteur attendu', value: dossier.acteur_attendu },
                        { label: 'Prochaine étape', value: dossier.signe ? 'Agence Comptable' : 'Signature de l’ordre de paiement' },
                        { label: 'Délai', value: dossier.heures_attente != null ? `${dossier.heures_attente} h${dossier.en_retard ? ' · alerte à 48 h' : ''}` : null, warning: dossier.en_retard },
                        { label: 'En lettres', value: dossier.lettres },
                        { label: 'Structure', value: dossier.structure },
                    ]} />
                </SectionCard>
            )}

            {tab === 'liquidation' && (
                <SectionCard title="Liquidation source" icon={ICON.settlement} actions={<Button size="sm" to={`/liquidations/${dossier.liquidation_id}`} iconRight={ICON.open}>Ouvrir la liquidation</Button>}>
                    <KeyValueList items={[
                        { label: 'Liquidation', value: dossier.liquidation, mono: true },
                        { label: 'Visa', value: `${dossier.visa ?? '—'} · ${dossier.visa_le ?? ''}` },
                        { label: 'Service fait', value: dossier.service_fait },
                        { label: 'Réserves', value: dossier.reserves },
                        { label: 'Engagement', value: dossier.engagement, mono: true },
                    ]} />
                </SectionCard>
            )}

            {tab === 'budget' && (
                <SectionCard title="Situation budgétaire" icon={ICON.budget} flush>
                    <DataTable
                        columns={[
                            { key: 'ligne', header: 'Ligne', className: 'mono', render: (row: any) => row.ligne },
                            { key: 'libelle', header: 'Libellé', render: (row: any) => row.libelle },
                            { key: 'brut', header: 'Liquidé (brut)', align: 'right', className: 'mono', render: (row: any) => fcfa(row.brut) },
                            { key: 'net', header: 'Ordonnancé (net)', align: 'right', className: 'cell-amount', render: (row: any) => fcfa(row.net) },
                        ]}
                        rows={dossier.imputations ?? []}
                        rowKey={(row: any) => row.ligne}
                    />
                </SectionCard>
            )}

            {tab === 'beneficiaire' && (
                <SectionCard title="Bénéficiaire" icon={faBuildingColumns}>
                    <BeneficiaryDetails dossier={dossier} detailed />
                </SectionCard>
            )}

            {tab === 'factures' && (
                <SectionCard title="Factures" icon={ICON.settlement} flush>
                    <DataTable
                        columns={[
                            { key: 'numero', header: 'Facture', className: 'mono', render: (row: any) => row.numero },
                            { key: 'date', header: 'Date', className: 'mono', render: (row: any) => row.date },
                            { key: 'brut', header: 'Brut', align: 'right', className: 'mono', render: (row: any) => fcfa(row.brut) },
                            { key: 'taxes', header: 'Taxes', align: 'right', className: 'mono', render: (row: any) => fcfa(row.taxes) },
                            { key: 'retenues', header: 'Retenues', align: 'right', className: 'mono', render: (row: any) => fcfa(row.retenues) },
                            { key: 'net', header: 'Net', align: 'right', className: 'cell-amount', render: (row: any) => fcfa(row.net) },
                            { key: 'statut', header: 'Statut', render: (row: any) => row.statut },
                        ]}
                        rows={dossier.factures ?? []}
                        rowKey={(row: any) => row.numero}
                    />
                </SectionCard>
            )}

            {tab === 'retenues' && (
                <SectionCard title="Retenues" icon={faScaleBalanced} flush footer={<span>Net à payer : <span className="mono strong">{fcfa(dossier.montant_net)} FCFA</span></span>}>
                    <DataTable
                        columns={[
                            { key: 'libelle', header: 'Retenue', render: (row: any) => row.libelle },
                            { key: 'base', header: 'Base', align: 'right', className: 'mono', render: (row: any) => fcfa(row.base) },
                            { key: 'taux', header: 'Taux', align: 'right', className: 'mono', render: (row: any) => `${row.taux} %` },
                            { key: 'montant', header: 'Montant', align: 'right', className: 'cell-amount', render: (row: any) => fcfa(row.montant) },
                        ]}
                        rows={dossier.retenues_detail ?? []}
                        rowKey={(row: any) => row.libelle}
                    />
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
                            { label: 'Résultats', value: dossier.pap.resultats },
                            { label: 'Indicateur', value: dossier.pap.indicateur },
                            { label: 'Cible', value: dossier.pap.cible },
                            { label: 'Période', value: dossier.pap.periode },
                        ]} />
                    ) : <p className="muted">Cette dépense est hors PAP, ou le référentiel de la ligne n’est pas renseigné.</p>}
                </SectionCard>
            )}

            {tab === 'marche' && (
                <SectionCard title="Marché" icon={ICON.commitment}>
                    <p>{dossier.marche?.message}</p>
                </SectionCard>
            )}

            {tab === 'pieces' && (
                <SectionCard title="Pièces" icon={ICON.attachment}>
                    <DocumentList items={pieces} emptyTitle="Aucune pièce" />
                    {id && <GedDossier type="ordonnancement" entityId={Number(id)} />}
                    {id && <AuditChronologie type="ordonnancement" entityId={Number(id)} />}
                </SectionCard>
            )}

            {tab === 'controles' && (
                <SectionCard title="Contrôles" icon={ICON.entry} tag={<ChecklistSummary items={controles} />}>
                    <Checklist items={controles} />
                </SectionCard>
            )}

            {tab === 'historique' && (
                <div className="stack">
                    <SectionCard title="Liquidations et ordonnancements successifs" icon={ICON.commitment} flush>
                        <DataTable
                            columns={[
                                { key: 'tranche', header: 'Tranche', render: (row: any) => row.tranche },
                                { key: 'liquidation', header: 'Liquidation', className: 'mono', render: (row: any) => row.liquidation },
                                { key: 'ordonnancement', header: 'Ordonnancement', className: 'mono', render: (row: any) => row.ordonnancement ?? '—' },
                                { key: 'brut', header: 'Brut', align: 'right', className: 'mono', render: (row: any) => fcfa(row.brut) },
                                { key: 'net', header: 'Net', align: 'right', className: 'cell-amount', render: (row: any) => fcfa(row.net) },
                                { key: 'etat', header: 'État', render: (row: any) => row.etat },
                            ]}
                            rows={dossier.successives ?? []}
                            rowKey={(row: any) => row.liquidation}
                        />
                    </SectionCard>
                    <SectionCard title="Historique" icon={ICON.history}>
                        <WorkflowTimeline events={(dossier.historique ?? []).map((event) => ({ action: event.action, actor: event.acteur ?? 'Système', date: event.le, note: event.motif, system: !event.acteur }))} />
                    </SectionCard>
                </div>
            )}

            {tab === 'documents' && (
                <SectionCard title="Documents" icon={ICON.document} subtitle={`Exercice 2026 › Chaîne de dépense › Ordonnancement › ${dossier.reference}`}>
                    <DocumentList items={(dossier.ged ?? []).map((item) => ({ name: item.nom, meta: item.detail }))} emptyTitle="Aucun document archivé" />
                </SectionCard>
            )}

            {signOpen && (
                <Modal
                    size="lg"
                    title="Signature de l’ordre de paiement"
                    description="Action formelle, horodatée et irréversible"
                    icon={ICON.sign}
                    tone="warning"
                    onClose={() => setSignOpen(false)}
                    footer={(
                        <>
                            <span className="modal-footer-note">Le mot de passe n’est pas conservé.</span>
                            <Button onClick={() => setSignOpen(false)}>Annuler</Button>
                            <Button variant="primary" icon={ICON.sign} disabled={!confirmed || code.length === 0} loading={pending === 'signer'} onClick={() => act('signer', { confirmation: true, mot_de_passe: code }, 'Ordre de paiement signé.')}>Signer l’ordre de paiement</Button>
                        </>
                    )}
                >
                    <div className="layout-aside" style={{ gridTemplateColumns: 'minmax(0, 1fr) 240px' }}>
                        <KeyValueList items={[
                            { label: 'Référence', value: dossier.reference, mono: true },
                            { label: 'Bénéficiaire', value: dossier.beneficiaire },
                            { label: 'Objet', value: dossier.objet },
                            { label: 'Imputation', value: dossier.ligne, mono: true },
                            { label: 'Liquidation', value: `${dossier.liquidation} · ${dossier.visa ?? ''}` },
                            { label: 'Ordonnateur', value: dossier.ordonnateur },
                        ]} />
                        <div className="card card-tone-brand" style={{ padding: 16, display: 'flex', flexDirection: 'column', gap: 6, alignSelf: 'start' }}>
                            <span className="lbl" style={{ color: '#B8CFF0' }}>Net à payer</span>
                            <span className="mono" style={{ fontSize: 24, fontWeight: 700 }}>{fcfa(dossier.montant_net)}</span>
                            <span style={{ fontSize: 'var(--text-xs)', color: '#D1E0F6' }}>{dossier.lettres}</span>
                        </div>
                    </div>
                    {(dossier.effets ?? []).length > 0 && (
                        <Alert tone="info" title="La signature déclenche">
                            <ol style={{ listStyle: 'decimal', paddingLeft: 18 }}>
                                {(dossier.effets ?? []).map((item) => <li key={item.evenement}>{item.evenement} — {item.detail}</li>)}
                            </ol>
                        </Alert>
                    )}
                    <label className={`choice${confirmed ? ' is-checked' : ''}`}>
                        <input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} />
                        <span>Je confirme l’ordonnancement de cette dépense.</span>
                    </label>
                    <FormField label="Mot de passe du signataire" required hint="Seront enregistrés : signataire, fonction, date et heure, référence de signature, empreinte du document et version signée.">
                        <div className="input-group has-icon">
                            <FontAwesomeIcon icon={faKey} className="input-icon" />
                            <input className="inp" type="password" autoComplete="current-password" value={code} onChange={(event) => setCode(event.target.value)} />
                        </div>
                    </FormField>
                    <ErrorMessage error={error} title="Signature refusée" />
                </Modal>
            )}
        </main>
    );
}

function BeneficiaryDetails({ dossier, detailed = false }: { dossier: any; detailed?: boolean }) {
    return (
        <KeyValueList items={[
            { label: 'Raison sociale', value: dossier.beneficiaire, strong: true },
            { label: 'RCCM · NIF', value: [dossier.rccm, dossier.nif].filter(Boolean).join(' · '), hidden: detailed },
            { label: 'RCCM', value: dossier.rccm, hidden: !detailed },
            { label: 'NIF', value: dossier.nif, hidden: !detailed },
            { label: 'Banque', value: dossier.banque || 'Non renseignée' },
            { label: 'Compte', value: dossier.compte || 'Non renseigné dans le référentiel des tiers', mono: Boolean(dossier.compte) },
        ]} />
    );
}
