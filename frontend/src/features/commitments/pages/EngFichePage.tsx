import { faBan, faClock, faHandHoldingDollar, faLightbulb, faRoute, faUserTie } from '@fortawesome/free-solid-svg-icons';
import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import AuditChronologie from '../../audit/AuditChronologie';
import GedDossier from '../../ged/GedDossier';
import api from '../../../api/httpClient';
import {
    ActionMenu,
    Alert,
    AmountInput,
    Badge,
    Button,
    ChainTrail,
    Checklist,
    ChecklistSummary,
    DataTable,
    DocumentList,
    EmptyState,
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
    ProgressBar,
    SearchInput,
    SectionCard,
    StatusBadge,
    Tabs,
    useDialogs,
    useToast,
    WorkflowTimeline,
    type CheckItem,
    type Column,
} from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';
import ActesDossier from '../ActesDossier';

const TABS = [
    ['instruction', 'Instruction'],
    ['controle', 'Contrôle'],
    ['eb', 'Expression de besoin'],
    ['budget', 'Budget et imputation'],
    ['detail', 'Détail'],
    ['pieces', 'Pièces'],
    ['historique', 'Historique'],
];

const SUCCESS: Record<string, string> = {
    transmettre: 'Engagement transmis.',
    retourner: 'Engagement retourné.',
    rejeter: 'Engagement rejeté.',
    viser: 'Visa apposé : le dossier est figé et une liquidation est ouverte.',
    degager: 'Dégagement enregistré.',
    annuler: 'Engagement annulé.',
};

export default function EngFiche() {
    const { id } = useParams();
    const navigate = useNavigate();
    const toast = useToast();
    const { prompt, confirm } = useDialogs();
    const [dossier, setDossier] = useState<any>(null);
    const [tab, setTab] = useState('instruction');
    const [error, setError] = useState('');
    const [loadError, setLoadError] = useState('');
    const [pending, setPending] = useState<string | null>(null);
    const [visaOpen, setVisaOpen] = useState(false);
    const [observations, setObservations] = useState('');
    const [beneficiaire, setBeneficiaire] = useState('');
    const [tiersId, setTiersId] = useState('');
    const [rechercheTiers, setRechercheTiers] = useState('');
    const [tiers, setTiers] = useState<any[]>([]);
    const [degagement, setDegagement] = useState('');
    const [motifDegagement, setMotifDegagement] = useState('');
    const [acte, setActe] = useState('');
    const [pieceType, setPieceType] = useState('');
    const [piece, setPiece] = useState<File | null>(null);

    function load() {
        api.get(`/engagements/${id}`).then((response) => {
            const data = response.data.data;
            setDossier(data);
            setLoadError('');
            setBeneficiaire(data.beneficiaire || '');
            setTiersId(data.tiers_id ? String(data.tiers_id) : '');
            if (data.actions?.viser) {
                setTab('controle');
            }
        }).catch((caught) => setLoadError(errorsOf(caught)));
    }

    useEffect(() => {
        load();
    }, [id]);

    useEffect(() => {
        if (!dossier?.actions?.completer) {
            return;
        }
        const handle = setTimeout(() => {
            api.get('/tiers', { params: { q: rechercheTiers } }).then((response) => setTiers(response.data.data || []));
        }, 250);

        return () => clearTimeout(handle);
    }, [dossier?.actions?.completer, rechercheTiers]);

    async function act(action: string, payload = {}) {
        setError('');
        setPending(action);
        try {
            const response = await api.post(`/engagements/${id}/${action}`, payload);
            setDossier(response.data.data);
            setVisaOpen(false);
            setObservations('');
            setDegagement('');
            setMotifDegagement('');
            setActe('');
            if (SUCCESS[action]) toast.success(SUCCESS[action]);
        } catch (caught) {
            setError(errorsOf(caught));
            toast.error(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function saveBeneficiary() {
        setError('');
        setPending('beneficiaire');
        try {
            const payload = tiersId ? { tiers_id: Number(tiersId) } : { beneficiaire };
            const response = await api.patch(`/engagements/${id}`, payload);
            setDossier(response.data.data);
            setBeneficiaire(response.data.data.beneficiaire || '');
            setTiersId(response.data.data.tiers_id ? String(response.data.data.tiers_id) : '');
            toast.success('Bénéficiaire enregistré.');
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function transmettre() {
        const values = await prompt({
            title: 'Transmettre l’engagement',
            description: `${dossier.reference} · ${fcfa(dossier.montant)} FCFA`,
            confirmLabel: 'Transmettre',
            icon: ICON.transmit,
            fields: [{ name: 'observations', label: 'Observations', type: 'textarea', hint: 'Facultatif. Versées au journal du workflow.' }],
        });
        if (values) act('transmettre', { observations: values.observations });
    }

    async function decision(action: 'retourner' | 'rejeter') {
        const rejet = action === 'rejeter';
        const values = await prompt({
            title: rejet ? 'Rejeter l’engagement' : 'Retourner l’engagement',
            description: rejet ? 'Le rejet est une décision formelle consignée au workflow.' : 'Le dossier revient à l’étape d’instruction pour correction.',
            confirmLabel: rejet ? 'Confirmer le rejet' : 'Retourner',
            tone: rejet ? 'danger' : 'warning',
            icon: rejet ? ICON.reject : ICON.return,
            fields: [
                { name: 'motif', label: 'Motif', required: true, maxLength: 255 },
                { name: 'observations', label: 'Observations', type: 'textarea' },
            ],
        });
        if (values) act(action, { motif: values.motif, observations: values.observations });
    }

    async function joindrePiece(event: { preventDefault: () => void }) {
        event.preventDefault();
        if (!piece || !pieceType) return;
        setError('');
        setPending('piece');
        try {
            const body = new FormData();
            body.append('type', pieceType);
            body.append('fichier', piece);
            const response = await api.post(`/engagements/${id}/pieces`, body);
            setDossier(response.data.data);
            setPiece(null);
            setPieceType('');
            toast.success('Pièce obligatoire jointe.');
        } catch (caught) {
            toast.error(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function ouvrirSuite(action: 'partiel' | 'avenant') {
        const avenant = action === 'avenant';
        const values = await prompt({
            title: avenant ? 'Ouvrir un avenant' : 'Engager partiellement',
            description: avenant
                ? 'L’avenant ouvre un engagement supplémentaire. L’acte déjà visé n’est pas modifié.'
                : 'La première part reste sur cet engagement. Le solde du besoin ouvre un second engagement.',
            confirmLabel: avenant ? 'Ouvrir l’avenant' : 'Fractionner',
            icon: ICON.need,
            fields: [
                { name: 'montant', label: avenant ? 'Montant de l’avenant (FCFA)' : 'Première part (FCFA)', required: true },
                { name: 'motif', label: 'Motif', required: true, maxLength: 255 },
            ],
        });
        if (!values) return;
        setError('');
        setPending(action);
        try {
            const response = await api.post(`/engagements/${id}/${action}`, {
                montant: Number(String(values.montant).replace(/\s/g, '')),
                motif: values.motif,
            });
            toast.success(avenant ? 'Avenant ouvert.' : 'Engagement partiel ouvert.');
            navigate(`/engagements/${response.data.data.id}`);
        } catch (caught) {
            setError(errorsOf(caught));
            toast.error(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function annuler() {
        if (await confirm({ title: 'Annuler l’engagement', description: `Motif : « ${motifDegagement} ». Les crédits réservés sont libérés. Cette décision est définitive.`, confirmLabel: 'Annuler l’engagement', tone: 'danger', icon: faBan })) {
            act('annuler', { motif: motifDegagement });
        }
    }

    if (!dossier) {
        return loadError ? <PageError message={loadError} onRetry={load} /> : <PageSkeleton variant="detail" />;
    }

    const credit = dossier.credit;
    const suites = dossier.liquidations ?? [];
    const principale = suites.find((row) => row.reference === dossier.liquidation) ?? suites[0];
    const partiel = dossier.montant_eb && dossier.montant < dossier.montant_eb;
    const checks = controlPoints(dossier);
    const piecesAttendues = dossier.pieces_attendues ?? [];
    const presentPieces = piecesAttendues.filter((type) => (dossier.documents || []).some((document) => document.type === type));
    const piecesManquantes = piecesAttendues.filter((type) => !presentPieces.includes(type));
    const history = (dossier.historique || []).map((event) => ({ action: event.action, actor: event.acteur || 'Système', date: event.le, detail: event.motif ? `Motif : ${event.motif}` : undefined, note: event.observations, system: !event.acteur }));
    const tabs = TABS.map(([value, label]) => ({
        value,
        label,
        hidden: value === 'controle' && !(dossier.actions.viser || dossier.visa || dossier.etape === 'controleur_financier'),
        count: value === 'pieces' ? (dossier.documents || []).length : value === 'historique' ? (dossier.historique || []).length : undefined,
    }));

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/engagements', label: 'Engagements' }}
                eyebrow={(
                    <>
                        <span>Engagement n° <span className="mono strong">{dossier.reference}</span></span>
                        <StatusBadge statut={dossier.statut} libelle={dossier.statut_libelle} />
                        <NatureBadge nature={dossier.nature} libelle={dossier.nature_libelle} />
                        {partiel && <Badge tone="sky">Engagement partiel</Badge>}
                    </>
                )}
                title={dossier.objet}
                subtitle={dossier.structure}
                meta={(
                    <ChainTrail current="ENG" links={{
                        EB: { reference: dossier.eb_reference, to: `/expressions-besoin/${dossier.expression_besoin_id}` },
                        ENG: { reference: dossier.reference },
                        LIQ: principale ? { reference: principale.reference, to: `/liquidations/${principale.id}` } : undefined,
                        ORD: principale?.ordonnancement ? { reference: principale.ordonnancement, to: `/ordonnancements/${principale.ordonnancement_id}` } : undefined,
                        PAY: principale?.paiement ? { reference: principale.paiement, to: `/paiements/${principale.paiement_id}` } : undefined,
                    }}
                    />
                )}
                figure={{ label: 'Montant engagé', value: fcfa(dossier.montant), unit: 'FCFA' }}
                actions={(
                    <>
                        {dossier.actions.retourner && <Button variant="warning" icon={ICON.return} onClick={() => decision('retourner')} loading={pending === 'retourner'}>Retourner</Button>}
                        {dossier.actions.rejeter && <Button variant="danger-outline" icon={ICON.reject} onClick={() => decision('rejeter')} loading={pending === 'rejeter'}>Rejeter</Button>}
                        {dossier.actions.transmettre && <Button variant="primary" icon={ICON.transmit} onClick={transmettre} loading={pending === 'transmettre'}>Transmettre</Button>}
                        {dossier.actions.partiel && <Button icon={ICON.need} onClick={() => ouvrirSuite('partiel')} loading={pending === 'partiel'}>Engager partiellement</Button>}
                        {dossier.actions.avenant && <Button icon={ICON.need} onClick={() => ouvrirSuite('avenant')} loading={pending === 'avenant'}>Avenant</Button>}
                        {dossier.actions.viser && <Button variant="primary" icon={ICON.visa} onClick={() => setVisaOpen(true)}>Viser</Button>}
                        <ActionMenu actions={[{ label: 'Fiche PDF', icon: ICON.pdf, href: `/api/v1/engagements/${dossier.id}/pdf`, hidden: !dossier.actions.pdf }]} />
                    </>
                )}
            />

            <InfoGrid
                label="Situation du dossier"
                items={[
                    { label: 'Étape actuelle', value: dossier.acteur_attendu, icon: faRoute },
                    { label: 'Dernière action', value: dossier.derniere_action, icon: ICON.history },
                    { label: 'Échéance', value: dossier.echeance, icon: faClock, mono: true },
                    { label: 'EB source', value: dossier.eb_reference, icon: ICON.need, mono: true },
                ]}
            />

            <ErrorMessage error={error} onClose={() => setError('')} />

            {(dossier.actions.degager || dossier.actions.annuler || dossier.montant_degage > 0) && (
                <SectionCard title="Dégagement et annulation" icon={faHandHoldingDollar} subtitle="Libération de crédits non consommés sur l’engagement">
                    <KeyValueList compact items={[
                        { label: 'Montant de l’acte', value: `${fcfa(dossier.montant)} FCFA` },
                        { label: 'Dégagé', value: `${fcfa(dossier.montant_degage)} FCFA`, warning: dossier.montant_degage > 0 },
                        { label: 'Engagé net', value: `${fcfa(dossier.engage_net)} FCFA`, strong: true },
                    ]} />
                    {(dossier.degagements ?? []).length > 0 && (
                        <WorkflowTimeline events={dossier.degagements.map((row) => ({ action: `Dégagement ${row.reference} · ${fcfa(row.montant)} FCFA`, actor: row.acteur, date: row.le, note: row.motif }))} />
                    )}
                    {(dossier.actions.degager || dossier.actions.annuler) && (
                        <div className="form-grid" style={{ ['--cols' as string]: 3, alignItems: 'end' }}>
                            {dossier.actions.degager && (
                                <FormField label="Montant à dégager">
                                    <AmountInput value={degagement} onChange={(value) => setDegagement(value.replace(/\D/g, ''))} inputMode="numeric" />
                                </FormField>
                            )}
                            <FormField label="Motif" required>
                                <input className="inp" value={motifDegagement} onChange={(event) => setMotifDegagement(event.target.value)} />
                            </FormField>
                            {dossier.actions.degager && (
                                <FormField label="Acte justificatif" optional>
                                    <input className="inp" value={acte} onChange={(event) => setActe(event.target.value)} />
                                </FormField>
                            )}
                            <div className="form-actions start span-all">
                                {dossier.actions.degager && <Button icon={faHandHoldingDollar} disabled={!degagement || !motifDegagement} loading={pending === 'degager'} onClick={() => act('degager', { montant: Number(degagement), motif: motifDegagement, acte })}>Dégager</Button>}
                                {dossier.actions.annuler && <Button variant="danger-outline" icon={faBan} disabled={!motifDegagement} loading={pending === 'annuler'} onClick={annuler}>Annuler l’engagement</Button>}
                            </div>
                        </div>
                    )}
                </SectionCard>
            )}

            {suites.length > 0 && (
                <SectionCard title="Liquidations rattachées" icon={ICON.settlement} subtitle="Chaque liquidation reste accessible, y compris lorsqu’une suivante est ouverte.">
                    <ul className="list-rows">
                        {suites.map((row) => (
                            <li key={row.id} className="list-row">
                                <Link className="mono strong" to={`/liquidations/${row.id}`}>{row.reference}</Link>
                                <StatusBadge statut={row.statut} libelle={row.statut_libelle} />
                                <span className="mono">{fcfa(row.montant)} FCFA</span>
                                {row.ordonnancement_id && <Link to={`/ordonnancements/${row.ordonnancement_id}`}>{row.ordonnancement}</Link>}
                                {row.paiement_id && <Link to={`/paiements/${row.paiement_id}`}>{row.paiement}</Link>}
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            )}

            <div className="stack" style={{ gap: 0 }}>
                <ActesDossier dossier={dossier} base="/api/v1/engagements" />
                <Tabs label="Sections de l’engagement" items={tabs} value={tab} onChange={setTab} />
            </div>

            {tab === 'instruction' && (
                <div className="liq-split">
                    <div className="stack">
                        {credit && (
                            <SectionCard title={`Contrôle de disponibilité · ligne ${dossier.ligne}`} subtitle={dossier.ligne_libelle} icon={ICON.budget} tone={credit.suffisant ? 'default' : 'danger'}>
                                <ProgressBar
                                    size="thick"
                                    label="Part du disponible mobilisée"
                                    value={credit.disponible_avant > 0 ? Math.min(100, Math.round((credit.montant / credit.disponible_avant) * 100)) : 100}
                                    color={credit.montant > credit.disponible_avant ? 'var(--danger-solid)' : 'var(--green-600)'}
                                />
                                <KeyValueList compact items={[
                                    { label: 'Disponible avant', value: fcfa(credit.disponible_avant) },
                                    { label: 'Montant demandé', value: fcfa(credit.montant), strong: true },
                                    { label: 'Disponible après', value: fcfa(credit.disponible_apres), warning: !credit.suffisant },
                                ]} />
                                {!credit.suffisant && <Alert tone="danger" title="Crédit insuffisant">Insuffisance de {fcfa(credit.insuffisance)} FCFA. La transmission est bloquée.</Alert>}
                            </SectionCard>
                        )}
                        {partiel && (
                            <SectionCard title="Engagement partiel de l’EB" icon={ICON.need}>
                                <KeyValueList compact items={[
                                    { label: 'Montant de l’EB', value: fcfa(dossier.montant_eb) },
                                    { label: 'Montant engagé maintenant', value: fcfa(dossier.montant), strong: true },
                                    { label: 'Reliquat non engagé', value: fcfa(dossier.montant_eb - dossier.montant) },
                                ]} />
                            </SectionCard>
                        )}
                        <SectionCard title="Détail des prestations" subtitle="Hérité de l’expression de besoin" icon={ICON.need} flush>
                            <Lines dossier={dossier} />
                        </SectionCard>
                        <SectionCard
                            title="Bénéficiaire"
                            icon={faUserTie}
                            actions={dossier.actions.completer && <Button size="sm" variant="primary" icon={ICON.save} onClick={saveBeneficiary} loading={pending === 'beneficiaire'}>Enregistrer le bénéficiaire</Button>}
                        >
                            {dossier.actions.completer ? (
                                <div className="form-grid">
                                    <FormField label="Rechercher un tiers actif" className="span-all">
                                        <SearchInput value={rechercheTiers} onChange={setRechercheTiers} placeholder="Raison sociale, code, NIF…" label="Rechercher un tiers" />
                                    </FormField>
                                    <FormField label="Tiers du référentiel" hint="Un tiers du référentiel garantit des coordonnées validées.">
                                        <select className="inp" value={tiersId} onChange={(event) => setTiersId(event.target.value)}>
                                            <option value="">Saisie libre du bénéficiaire</option>
                                            {tiers.map((row) => <option key={row.id} value={row.id}>{row.raison_sociale} · {row.code}</option>)}
                                        </select>
                                    </FormField>
                                    {!tiersId && (
                                        <FormField label="Bénéficiaire (saisie libre)">
                                            <input className="inp" value={beneficiaire} onChange={(event) => setBeneficiaire(event.target.value)} />
                                        </FormField>
                                    )}
                                </div>
                            ) : <strong style={{ fontSize: 'var(--text-md)' }}>{dossier.beneficiaire || '—'}</strong>}
                            <span className="subtle">{[dossier.beneficiaire_rccm, dossier.beneficiaire_nif].filter(Boolean).join(' · ') || 'RCCM et NIF non renseignés'}</span>
                        </SectionCard>
                        <SectionCard title="Marché / contrat" icon={ICON.document}>
                            <p className="muted">Aucun marché n’est rattaché. Le bénéficiaire est porté directement sur l’engagement.</p>
                        </SectionCard>
                        {credit && !credit.suffisant && (
                            <Alert tone="warning" icon={faLightbulb} title="Comment débloquer ce dossier ?">
                                <ul style={{ listStyle: 'disc', paddingLeft: 18, display: 'flex', flexDirection: 'column', gap: 4 }}>
                                    <li>Réduire le montant engagé pour qu’il tienne dans les {fcfa(credit.disponible_avant)} FCFA encore disponibles sur la ligne {dossier.ligne}.</li>
                                    <li>Répartir le besoin sur une autre imputation disposant du crédit. Le virement de crédit se traite hors de cet engagement.</li>
                                    <li>La transmission reste refusée tant que l’insuffisance de {fcfa(credit.insuffisance)} FCFA n’est pas résorbée.</li>
                                </ul>
                            </Alert>
                        )}
                    </div>
                    <aside className="stack">
                        <SectionCard title="Contrôles automatiques" icon={ICON.entry} tag={<ChecklistSummary items={checks} />}>
                            <Checklist items={checks} />
                        </SectionCard>
                        <SectionCard title="Pièces obligatoires" icon={ICON.attachment} tag={<Badge tone={presentPieces.length === piecesAttendues.length ? 'success' : 'orange'} size="sm">{presentPieces.length} / {piecesAttendues.length}</Badge>}>
                            <DocumentList
                                items={piecesAttendues.map((type) => {
                                    const found = (dossier.documents || []).find((document) => document.type === type);
                                    return { name: found ? found.nom : type, meta: found ? `${type} · jointe` : 'Pièce manquante', missing: !found };
                                })}
                                emptyTitle="Aucune pièce obligatoire paramétrée"
                            />
                            {dossier.actions.joindre && piecesManquantes.length > 0 && (
                                <Button size="sm" icon={ICON.upload} onClick={() => setTab('pieces')}>Joindre la pièce manquante</Button>
                            )}
                        </SectionCard>
                    </aside>
                </div>
            )}

            {tab === 'controle' && (
                <div className="liq-split">
                    <div className="stack">
                        <SectionCard title="Grille de contrôle financier" icon={ICON.visa} tag={<ChecklistSummary items={checks} />}>
                            <Checklist items={checks} />
                        </SectionCard>
                        <SectionCard title="Historique du workflow" icon={ICON.history}>
                            <WorkflowTimeline events={history} />
                        </SectionCard>
                    </div>
                    <aside className="stack">
                        <SectionCard title="EB source" icon={ICON.need}>
                            <Link to={`/expressions-besoin/${dossier.expression_besoin_id}`} className="mono strong">{dossier.eb_reference}</Link>
                            <div>{dossier.objet}</div>
                            {dossier.justification && <p className="quote">{dossier.justification}</p>}
                        </SectionCard>
                        <SectionCard title="Rattachement PAP" icon={ICON.planning}>
                            {dossier.enrichissement
                                ? <KeyValueList items={[{ label: 'Pilier', value: dossier.enrichissement.pilier }, { label: 'Objectif', value: dossier.enrichissement.objectif }, { label: 'Activité', value: dossier.enrichissement.activite }]} />
                                : <p className="muted">Hors PAP, ou référentiel non chargé sur la ligne {dossier.ligne}.</p>}
                        </SectionCard>
                        <Alert tone="neutral" icon={ICON.comment} title="Commentaires internes">
                            Les observations de transmission et le motif de retour ou de rejet restent les décisions formelles du workflow ; ils figurent dans l’historique.
                        </Alert>
                    </aside>
                </div>
            )}

            {tab === 'eb' && (
                <SectionCard title="Expression de besoin source" icon={ICON.need} actions={<Button size="sm" to={`/expressions-besoin/${dossier.expression_besoin_id}`} iconRight={ICON.open}>Ouvrir l’EB</Button>}>
                    <KeyValueList items={[
                        { label: 'Référence', value: dossier.eb_reference, mono: true },
                        { label: 'Montant de l’EB', value: `${fcfa(dossier.montant_eb)} FCFA`, mono: true },
                        { label: 'Justification', value: dossier.justification },
                    ]} />
                </SectionCard>
            )}

            {tab === 'budget' && (
                <SectionCard title={`Ligne ${dossier.ligne}`} subtitle={dossier.ligne_libelle} icon={ICON.budget} flush>
                    {credit && (
                        <div style={{ padding: '14px 20px', borderBottom: '1px solid var(--color-divider)' }}>
                            <KeyValueList compact items={[{ label: 'Disponible avant', value: `${fcfa(credit.disponible_avant)} FCFA` }, { label: 'Disponible après', value: `${fcfa(credit.disponible_apres)} FCFA` }]} />
                        </div>
                    )}
                    <DataTable
                        columns={[
                            { key: 'ligne', header: 'Ligne', className: 'mono', render: (row: any) => row.ligne },
                            { key: 'libelle', header: 'Libellé', render: (row: any) => row.libelle },
                            { key: 'montant', header: 'Montant (FCFA)', align: 'right', className: 'cell-amount', render: (row: any) => fcfa(row.montant) },
                        ]}
                        rows={dossier.imputations || []}
                        rowKey={(row: any) => row.ligne}
                    />
                </SectionCard>
            )}

            {tab === 'detail' && <SectionCard title="Détail des prestations" icon={ICON.need} flush><Lines dossier={dossier} /></SectionCard>}

            {tab === 'pieces' && (
                <SectionCard title="Pièces du dossier" icon={ICON.attachment}>
                    {dossier.actions.joindre && piecesManquantes.length > 0 && (
                        <form className="stack" onSubmit={joindrePiece} style={{ marginBottom: 16 }}>
                            <div className="form-grid" style={{ ['--cols' as string]: 3, alignItems: 'end' }}>
                                <FormField label="Pièce manquante" required>
                                    <select className="inp" value={pieceType} onChange={(event) => setPieceType(event.target.value)} required>
                                        <option value="">Choisir</option>
                                        {piecesManquantes.map((type) => <option key={type}>{type}</option>)}
                                    </select>
                                </FormField>
                                <div className="span-2"><FileDrop file={piece} onFile={setPiece} hint="PDF ou image, 10 Mo maximum." /></div>
                            </div>
                            <div className="form-actions">
                                <Button variant="primary" type="submit" icon={ICON.upload} disabled={!piece || !pieceType} loading={pending === 'piece'}>Joindre la pièce</Button>
                            </div>
                        </form>
                    )}
                    <DocumentList items={(dossier.documents || []).map((document) => ({ name: document.nom, meta: document.type }))} emptyTitle="Aucune pièce" emptyText="Aucune pièce héritée de l’expression de besoin." />
                    {id && <GedDossier type="engagement" entityId={Number(id)} />}
                    {id && <AuditChronologie type="engagement" entityId={Number(id)} />}
                </SectionCard>
            )}

            {tab === 'historique' && <SectionCard title="Historique du workflow" icon={ICON.history}><WorkflowTimeline events={history} /></SectionCard>}

            {visaOpen && (
                <Modal
                    title="Apposer le visa du Contrôleur Financier"
                    description={`${dossier.reference} · issu de ${dossier.eb_reference}`}
                    icon={ICON.visa}
                    tone="success"
                    onClose={() => setVisaOpen(false)}
                    footer={(
                        <>
                            <span className="modal-footer-note">Action formelle et horodatée.</span>
                            <Button onClick={() => setVisaOpen(false)}>Annuler</Button>
                            <Button variant="primary" icon={ICON.visa} loading={pending === 'viser'} onClick={() => act('viser', { observations })}>Confirmer le visa</Button>
                        </>
                    )}
                >
                    <div className="form-grid">
                        <FormField label="Référence du visa"><div className="ro">attribuée à la confirmation</div></FormField>
                        <FormField label="Montant visé"><div className="ro mono">{fcfa(dossier.montant)} FCFA</div></FormField>
                        <FormField label="Auteur · fonction"><div className="ro">Contrôleur Financier</div></FormField>
                        <FormField label="Date et heure"><div className="ro">à la confirmation</div></FormField>
                    </div>
                    <FormField label="Observations" optional>
                        <textarea className="inp" rows={3} value={observations} onChange={(event) => setObservations(event.target.value)} />
                    </FormField>
                    <Alert tone="info" title="Conséquences du visa">
                        <ul style={{ listStyle: 'disc', paddingLeft: 18 }}>
                            <li>Le dossier est figé.</li>
                            <li>La fiche PDF d’engagement est générée.</li>
                            <li>Une liquidation est ouverte pour le service fait.</li>
                        </ul>
                    </Alert>
                </Modal>
            )}
        </main>
    );
}

function controlPoints(dossier): CheckItem[] {
    const credit = dossier.credit;
    const imputed = (dossier.imputations || []).reduce((sum, row) => sum + Number(row.montant || 0), 0);
    const attendues = dossier.pieces_attendues ?? [];
    const pieces = (dossier.documents || []).filter((document) => attendues.includes(document.type)).length;
    return [
        { label: 'EB source', ok: Boolean(dossier.eb_reference), detail: dossier.eb_reference || 'absente' },
        { label: 'Crédit disponible', ok: credit ? credit.suffisant : false, detail: credit?.suffisant ? 'suffisant' : `insuffisant de ${fcfa(credit?.insuffisance || 0)}` },
        { label: 'Imputation', ok: imputed === 0 || imputed === Number(dossier.montant), detail: imputed === 0 ? 'ligne unique' : (imputed === Number(dossier.montant) ? 'égale au montant' : fcfa(imputed)) },
        { label: 'Bénéficiaire', ok: Boolean(dossier.beneficiaire), detail: dossier.beneficiaire || 'non renseigné' },
        { label: 'Pièces obligatoires', ok: pieces === attendues.length, detail: `${pieces} / ${attendues.length}` },
        { label: 'Rattachement PAP', ok: dossier.nature !== 'pap' || Boolean(dossier.enrichissement?.activite), detail: dossier.nature === 'pap' ? (dossier.enrichissement?.activite || 'incomplet') : 'hors PAP' },
        { label: 'Montant engagé', ok: Number(dossier.montant) > 0, detail: `${fcfa(dossier.montant)} FCFA` },
    ];
}

function Lines({ dossier }) {
    const columns: Column<any>[] = [
        { key: 'designation', header: 'Désignation', render: (row) => row.designation },
        { key: 'quantite', header: 'Qté', align: 'right', className: 'num', render: (row) => row.quantite },
        { key: 'prix', header: 'Prix unitaire', align: 'right', className: 'mono', render: (row) => fcfa(row.prix_unitaire) },
        { key: 'montant', header: 'Montant', align: 'right', className: 'cell-amount', render: (row) => fcfa(row.montant) },
    ];

    return <DataTable columns={columns} rows={dossier.lignes || []} rowKey={(row, index) => `${row.designation}-${index}`} empty={<EmptyState compact icon={ICON.need} title="Aucune prestation détaillée">Les sous-lignes de l’expression de besoin apparaissent ici.</EmptyState>} />;
}
