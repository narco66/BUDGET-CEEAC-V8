import { faCalculator, faCodeBranch, faFileInvoice, faSignature, faTruckRampBox } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import AuditChronologie from '../../audit/AuditChronologie';
import GedDossier from '../../ged/GedDossier';
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
    ErrorMessage,
    FormField,
    ICON,
    KeyValueList,
    Modal,
    NatureBadge,
    PageError,
    PageHeader,
    PageSkeleton,
    SectionCard,
    StatusBadge,
    Stepper,
    Tabs,
    useDialogs,
    useToast,
    WorkflowTimeline,
    type CheckItem,
    type Column,
} from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';
import ActesDossier from '../../commitments/ActesDossier';

const STEPS = ['Engagement', 'Service fait', 'Facture', 'Détail', 'Imputations', 'Pièces', 'Récapitulatif', 'Soumission'];

const CONTROL_TABS = [['0', 'Contrôle'], ['1', 'Engagement'], ['2', 'Service fait'], ['3', 'Facture'], ['4', 'Détail'], ['5', 'Imputations'], ['6', 'Pièces'], ['9', 'Versions'], ['10', 'Historique']];

const SUCCESS: Record<string, string> = {
    certifier: 'Service fait certifié et signé.',
    facture: 'Facture enregistrée.',
    soumettre: 'Liquidation soumise au Contrôleur Financier.',
    complement: 'Demande de complément transmise.',
    retourner: 'Liquidation retournée.',
    rejeter: 'Liquidation rejetée.',
    viser: 'Visa apposé : l’ordonnancement est créé.',
    rectifier: 'Rectification enregistrée.',
    'demande-doublon': 'Demande consignée au journal.',
};

export default function LiqFiche() {
    const { id } = useParams();
    const toast = useToast();
    const { prompt } = useDialogs();
    const [dossier, setDossier] = useState<any>(null);
    const [loadError, setLoadError] = useState('');
    const [step, setStep] = useState(2);
    const [error, setError] = useState('');
    const [pending, setPending] = useState<string | null>(null);
    const [visaOpen, setVisaOpen] = useState(false);
    const [visaObservations, setVisaObservations] = useState('');
    const [doublonOpen, setDoublonOpen] = useState(false);
    const [doublonMotif, setDoublonMotif] = useState('');
    const [rectOpen, setRectOpen] = useState(false);
    const [taxMode, setTaxMode] = useState('');
    const [atteste, setAtteste] = useState(false);
    const [reception, setReception] = useState({ date: '', bon: '', nature: '', lieu: '' });
    const [reserves, setReserves] = useState('');
    const [lines, setLines] = useState<any[]>([]);
    const [facture, setFacture] = useState({ numero: '', date: '', echeance: '', montant_ht: '', taxes: '', retenue: '0', penalite: '0' });
    const [rectKind, setRectKind] = useState('avoir');
    const [rectMontant, setRectMontant] = useState('');
    const [rectMotif, setRectMotif] = useState('');

    function apply(data) {
        setDossier(data);
        setReserves(data.reserves || '');
        setReception({
            date: data.date_service || new Date().toISOString().slice(0, 10),
            bon: data.bon_livraison || '',
            nature: data.nature_prestation || data.natures_prestation?.[0] || '',
            lieu: data.lieu_reception || '',
        });
        setLines(data.sous_lignes || []);
        setFacture({
            numero: data.facture || '',
            date: data.date_facture || '',
            echeance: data.echeance_facture || '',
            montant_ht: String(data.montant_ht || ''),
            taxes: data.facture ? String(data.taxes ?? 0) : '',
            retenue: String(data.retenue_garantie || 0),
            penalite: String(data.penalite || 0),
        });
        const taxes = Number(data.taxes || 0);
        setTaxMode(data.facture ? (taxes === 0 ? 'exo' : 'autre') : '');
        setAtteste(Boolean(data.service_fait_le));
    }

    function openOn(data) {
        if (data.actions?.viser || data.actions?.retourner) {
            setStep(0);
            return;
        }
        if (!data.service_fait_le) {
            setStep(2);
        } else if (!data.facture) {
            setStep(3);
        } else {
            setStep(7);
        }
    }

    function trySubmit() {
        if (dossier.doublon) {
            setDoublonOpen(true);
            return;
        }
        act('soumettre');
    }

    function load() {
        api.get(`/liquidations/${id}`).then((response) => {
            apply(response.data.data);
            openOn(response.data.data);
            setLoadError('');
        }).catch((caught) => setLoadError(errorsOf(caught)));
    }

    useEffect(load, [id]);

    async function act(action: string, payload = {}) {
        setError('');
        setPending(action);
        try {
            const response = await api.post(`/liquidations/${id}/${action}`, payload);
            apply(response.data.data);
            setVisaOpen(false);
            setDoublonOpen(false);
            setRectOpen(false);
            setVisaObservations('');
            if (action === 'rectifier') {
                setRectMontant('');
                setRectMotif('');
            }
            if (action === 'certifier') {
                setStep(3);
            }
            if (action === 'facture') {
                setStep(4);
            }
            if (SUCCESS[action]) toast.success(SUCCESS[action]);
        } catch (caught) {
            setError(errorsOf(caught));
            toast.error(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function decision(action: 'complement' | 'retourner' | 'rejeter') {
        const meta = {
            complement: { title: 'Demander un complément', confirm: 'Demander le complément', tone: 'warning' as const, icon: ICON.return },
            retourner: { title: 'Retourner la liquidation', confirm: 'Retourner', tone: 'warning' as const, icon: ICON.return },
            rejeter: { title: 'Rejeter la liquidation', confirm: 'Confirmer le rejet', tone: 'danger' as const, icon: ICON.reject },
        }[action];
        const values = await prompt({
            title: meta.title,
            description: `${dossier.reference} · ${dossier.fournisseur || ''}`,
            confirmLabel: meta.confirm,
            tone: meta.tone,
            icon: meta.icon,
            fields: [{ name: 'motif', label: 'Motif', type: 'textarea', required: true }],
        });
        if (values) act(action, { motif: values.motif });
    }

    function updateLine(index, field, value) {
        setLines((current) => current.map((line, position) => (position === index ? { ...line, [field]: value } : line)));
    }

    if (!dossier) {
        return loadError ? <PageError message={loadError} onRetry={load} /> : <PageSkeleton variant="detail" />;
    }

    const editable = dossier.actions.certifier;
    const controller = dossier.actions.viser || dossier.actions.retourner;
    const liquidable = lines.reduce((sum, line) => sum + Math.round(Number(line.quantite_acceptee || 0) * Number(line.prix_unitaire || 0)), 0);
    const deja = Math.max(0, (dossier.cumul_liquide || 0) - (dossier.montant_brut || 0));
    const solde = Math.max(0, (dossier.montant_engage || 0) - deja - liquidable);
    const ht = Number(facture.montant_ht || 0);
    const taxes = Number(facture.taxes || 0);
    const ttc = ht + taxes;
    const brut = dossier.montant_accepte ? Math.min(ttc || dossier.montant_accepte, dossier.montant_accepte) : ttc;
    const net = (dossier.montant_net && step > 3) ? dossier.montant_net : brut - Number(facture.retenue || 0) - Number(facture.penalite || 0);
    const partiel = lines.some((line) => Number(line.quantite_acceptee) < Number(line.quantite_commandee));
    const presente = step <= 2 ? liquidable : (dossier.montant_brut || liquidable);

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/liquidations', label: 'Liquidations' }}
                eyebrow={(
                    <>
                        <span>Liquidation n° <span className="mono strong">{dossier.reference}</span></span>
                        <StatusBadge statut={dossier.statut} libelle={dossier.statut_libelle} />
                        <NatureBadge nature={dossier.nature} libelle={dossier.nature_libelle} />
                    </>
                )}
                title={dossier.objet}
                subtitle={dossier.service_fait_le
                    ? `${dossier.service_fait}${dossier.service_fait_par ? ` par ${dossier.service_fait_par}` : ''} le ${dossier.service_fait_le}`
                    : `Créée au visa de l’engagement · fournisseur ${dossier.fournisseur || '—'}`}
                meta={(
                    <ChainTrail current="LIQ" links={{
                        EB: { reference: dossier.eb_reference },
                        ENG: { reference: dossier.engagement },
                        LIQ: { reference: dossier.reference },
                        ORD: { reference: dossier.ordonnancement, pending: 'à venir' },
                        PAY: { reference: dossier.paiement, pending: 'à venir' },
                    }} />
                )}
                figure={{ label: step <= 2 ? 'Montant engagé' : 'Net à payer', value: fcfa(step <= 2 ? dossier.montant_engage : net), unit: 'FCFA' }}
                actions={(
                    <>
                        {dossier.actions.complement && <Button variant="warning" icon={ICON.return} onClick={() => decision('complement')}>Demander un complément</Button>}
                        {dossier.actions.retourner && <Button variant="warning" icon={ICON.return} onClick={() => decision('retourner')}>Retourner</Button>}
                        {dossier.actions.rejeter && <Button variant="danger-outline" icon={ICON.reject} onClick={() => decision('rejeter')}>Rejeter</Button>}
                        {dossier.actions.soumettre && <Button variant="primary" icon={ICON.submit} onClick={trySubmit} loading={pending === 'soumettre'}>Soumettre au CF</Button>}
                        {dossier.actions.viser && <Button variant="primary" icon={ICON.visa} onClick={() => setVisaOpen(true)}>Viser</Button>}
                        <ActionMenu actions={[
                            { label: 'Fiche PDF', icon: ICON.pdf, href: `/api/v1/liquidations/${dossier.id}/pdf`, hidden: !dossier.actions.pdf },
                            { label: 'Rectifier (avoir ou complément)', icon: faCodeBranch, onSelect: () => setRectOpen(true), hidden: !dossier.actions.rectifier },
                        ]} />
                    </>
                )}
            />

            <ActesDossier dossier={dossier} base="/api/v1/liquidations" />

            {controller && (
                <Tabs label="Sections du dossier" items={CONTROL_TABS.map(([value, label]) => ({ value, label }))} value={String(step)} onChange={(value) => setStep(Number(value))} />
            )}

            {step !== 0 && step <= 8 && (
                <div className="card">
                    <Stepper
                        label="Étapes de la liquidation"
                        steps={STEPS.map((label, index) => ({ label, state: index + 1 < step ? 'done' : index + 1 === step ? 'current' : 'todo' }))}
                        onSelect={(index) => setStep(index + 1)}
                    />
                </div>
            )}

            {dossier.doublon && <Alert tone="danger" title="Blocage · facture en doublon" strong>La facture {dossier.facture} du fournisseur {dossier.fournisseur} est déjà enregistrée. La soumission est bloquée.</Alert>}
            <ErrorMessage error={error} onClose={() => setError('')} />

            <div className="liq-split">
                <div className="stack">
                    {step === 0 && <ControleLiquidation dossier={dossier} lines={lines} net={net} />}
                    {step === 1 && <EngagementStep dossier={dossier} />}
                    {step === 2 && (
                        <ServiceFait
                            editable={editable}
                            reception={reception}
                            setReception={setReception}
                            lines={lines}
                            updateLine={updateLine}
                            liquidable={liquidable}
                            reserves={reserves}
                            setReserves={setReserves}
                            atteste={atteste}
                            setAtteste={setAtteste}
                            signataire={dossier.signataire}
                            natures={dossier.natures_prestation ?? []}
                            pending={pending === 'certifier'}
                            onCertify={() => act('certifier', {
                                montant_accepte: liquidable,
                                reserves: reserves || null,
                                date_service: reception.date,
                                bon_livraison: reception.bon || null,
                                nature_prestation: reception.nature,
                                lieu_reception: reception.lieu || null,
                                lignes: lines,
                            })}
                        />
                    )}
                    {step === 9 && <Versions dossier={dossier} />}
                    {step === 10 && <SectionCard title="Historique" icon={ICON.history}><History dossier={dossier} /></SectionCard>}
                    {step === 3 && (
                        <FactureStep
                            dossier={dossier}
                            facture={facture}
                            setFacture={setFacture}
                            ttc={ttc}
                            brut={brut}
                            net={net}
                            lines={lines}
                            taxMode={taxMode}
                            setTaxMode={setTaxMode}
                            pending={pending === 'facture'}
                            onSave={() => act('facture', {
                                numero: facture.numero,
                                date: facture.date,
                                echeance: facture.echeance || null,
                                montant_ht: Number(facture.montant_ht),
                                taxes: Number(facture.taxes || 0),
                                retenue: Number(facture.retenue || 0),
                                penalite: Number(facture.penalite || 0),
                            })}
                        />
                    )}
                    {step === 4 && <DetailStep lines={lines} dossier={dossier} />}
                    {step === 5 && <ImputationStep dossier={dossier} />}
                    {step === 6 && <PiecesStep dossier={dossier} />}
                    {step === 7 && <Recap dossier={dossier} lines={lines} liquidable={liquidable} net={net} />}
                    {step === 8 && (
                        <SectionCard title="Étape 8 · Soumission" icon={ICON.submit}>
                            <p>La soumission transmet le dossier au Contrôleur Financier. Elle est refusée sans service fait certifié, sans facture, ou en cas de doublon.</p>
                            {dossier.actions.soumettre
                                ? <div><Button variant="primary" icon={ICON.submit} onClick={trySubmit} loading={pending === 'soumettre'}>Soumettre au Contrôleur Financier</Button></div>
                                : <Alert tone="neutral">Ce dossier n’est pas à votre étape de soumission.</Alert>}
                        </SectionCard>
                    )}
                    {step >= 1 && step <= 8 && (
                        <div className="form-actions between">
                            <Button icon={ICON.back} disabled={step === 1} onClick={() => setStep((current) => Math.max(1, current - 1))}>Précédent</Button>
                            <span className="subtle">Étape {step} sur 8</span>
                            <Button variant="brand" iconRight={ICON.next} disabled={step === 8} onClick={() => setStep((current) => Math.min(8, current + 1))}>Suivant</Button>
                        </div>
                    )}
                </div>

                <aside className="stack">
                    <SectionCard title="Panneau synthétique" icon={faCalculator} tag={<span className="mono subtle" style={{ fontWeight: 500 }}>{dossier.engagement}</span>}>
                        <KeyValueList compact items={[
                            { label: 'Engagement', value: fcfa(dossier.montant_engage) },
                            { label: 'Déjà liquidé', value: fcfa(deja) },
                            { label: 'Présente liquidation', value: fcfa(presente), strong: true },
                            { label: 'Solde restant à liquider', value: fcfa(solde), warning: true },
                        ]} />
                        <div className="progress is-thick" role="img" aria-label={`Déjà liquidé ${share(deja, dossier.montant_engage)} %, présente liquidation ${share(presente, dossier.montant_engage)} %, solde ${share(solde, dossier.montant_engage)} %`}>
                            <span style={{ width: `${share(deja, dossier.montant_engage)}%`, background: 'var(--green-600)' }} />
                            <span style={{ width: `${share(presente, dossier.montant_engage)}%`, background: 'var(--warning-solid)' }} />
                            <span style={{ width: `${share(solde, dossier.montant_engage)}%`, background: 'var(--slate-300)' }} />
                        </div>
                        <div className="legend">
                            <span className="legend-item"><span className="legend-swatch" style={{ background: 'var(--green-600)' }} />Déjà liquidé</span>
                            <span className="legend-item"><span className="legend-swatch" style={{ background: 'var(--warning-solid)' }} />Présente</span>
                            <span className="legend-item"><span className="legend-swatch" style={{ background: 'var(--slate-300)' }} />Solde</span>
                        </div>
                        <KeyValueList items={[
                            { label: 'Service fait', value: dossier.service_fait },
                            { label: 'Statut', value: <StatusBadge statut={dossier.statut} libelle={dossier.statut_libelle} /> },
                        ]} />
                    </SectionCard>
                    {partiel && <Alert tone="info" title="Liquidation partielle">Des quantités livrées ne sont pas toutes acceptées.</Alert>}
                    {solde > 0 && <Alert tone="warning" title="Solde à liquider">Il restera {fcfa(solde)} FCFA à liquider sur cet engagement.</Alert>}
                    {!dossier.service_fait_le && <Alert tone="danger" title="Soumission bloquée">Impossible de soumettre tant que le service fait n’est pas certifié.</Alert>}
                    <SectionCard title="Pièces attendues" icon={ICON.attachment}>
                        <DocumentList items={[
                            { name: 'Bon de livraison', meta: reception.bon || 'à joindre', missing: !reception.bon },
                            { name: 'Procès-verbal de réception', meta: dossier.service_fait_le ? 'généré à la certification' : 'à la certification', missing: !dossier.service_fait_le },
                            { name: 'Facture', meta: dossier.facture || 'étape 3', missing: !dossier.facture },
                        ]} />
                    </SectionCard>
                    {(dossier.rectifications ?? []).length > 0 && (
                        <SectionCard title="Rectifications" icon={faCodeBranch}>
                            <ul className="list-rows">
                                {dossier.rectifications.map((row) => (
                                    <li key={row.reference} className="list-row">
                                        <span className="list-row-main"><span className="list-row-title mono">{row.reference}</span><span className="list-row-sub">{row.kind}</span></span>
                                        <span className="mono strong">{fcfa(row.montant)}</span>
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>
                    )}
                </aside>
            </div>

            {doublonOpen && (
                <Modal
                    title="Cette facture semble avoir déjà été enregistrée"
                    description={`Soumission de ${dossier.reference} bloquée · facture ${dossier.facture} · ${dossier.fournisseur}`}
                    icon={ICON.warning}
                    tone="danger"
                    onClose={() => setDoublonOpen(false)}
                    footer={(
                        <>
                            <Button onClick={() => setDoublonOpen(false)}>Fermer</Button>
                            <Button variant="brand" disabled={!doublonMotif} loading={pending === 'demande-doublon'} onClick={() => act('demande-doublon', { motif: doublonMotif })}>Consigner la demande</Button>
                        </>
                    )}
                >
                    {dossier.doublon_de && <Alert tone="neutral">Déjà portée par <Link to={`/liquidations/${dossier.doublon_de.id}`}>{dossier.doublon_de.reference}</Link> · {dossier.doublon_de.statut}.</Alert>}
                    <FormField label="Motif de la demande d’autorisation" required>
                        <textarea className="inp" rows={3} value={doublonMotif} onChange={(event) => setDoublonMotif(event.target.value)} />
                    </FormField>
                    <p className="subtle">La demande est consignée au journal. Elle ne lève pas le blocage : une même facture ne peut pas être soumise deux fois.</p>
                </Modal>
            )}

            {visaOpen && (
                <Modal
                    title="Visa de liquidation"
                    description={`${dossier.reference} · net ${fcfa(dossier.montant_net)} FCFA`}
                    icon={ICON.visa}
                    tone="success"
                    onClose={() => setVisaOpen(false)}
                    footer={(
                        <>
                            <Button onClick={() => setVisaOpen(false)}>Annuler</Button>
                            <Button variant="primary" icon={ICON.visa} loading={pending === 'viser'} onClick={() => act('viser', { observations: visaObservations || null })}>Confirmer le visa</Button>
                        </>
                    )}
                >
                    <Alert tone="info">Le visa fige le dossier et crée l’ordonnancement.</Alert>
                    <FormField label="Observations" optional>
                        <textarea className="inp" rows={3} value={visaObservations} onChange={(event) => setVisaObservations(event.target.value)} />
                    </FormField>
                </Modal>
            )}

            {rectOpen && (
                <Modal
                    title="Rectification sans réécrire le visa"
                    description={dossier.reference}
                    icon={faCodeBranch}
                    onClose={() => setRectOpen(false)}
                    footer={(
                        <>
                            <Button onClick={() => setRectOpen(false)}>Annuler</Button>
                            <Button variant="primary" icon={ICON.save} disabled={!rectMontant || !rectMotif} loading={pending === 'rectifier'} onClick={() => act('rectifier', { kind: rectKind, montant: Number(rectMontant), motif: rectMotif })}>Enregistrer la rectification</Button>
                        </>
                    )}
                >
                    <FormField label="Nature" required>
                        <select className="inp" value={rectKind} onChange={(event) => setRectKind(event.target.value)}>
                            <option value="avoir">Avoir</option>
                            <option value="complementaire">Complément</option>
                        </select>
                    </FormField>
                    <FormField label="Montant" required>
                        <AmountInput value={rectMontant} onChange={setRectMontant} />
                    </FormField>
                    <FormField label="Motif" required>
                        <input className="inp" value={rectMotif} onChange={(event) => setRectMotif(event.target.value)} />
                    </FormField>
                </Modal>
            )}
        </main>
    );
}

function ServiceFait({ editable, reception, setReception, lines, updateLine, liquidable, reserves, setReserves, atteste, setAtteste, signataire, natures, onCertify, pending }) {
    return (
        <>
            <div className="stack-sm">
                <h2 style={{ fontSize: 'var(--text-lg)' }}>Étape 2 · Constat du service fait</h2>
                <p className="muted">Indiquez ce qui a été livré et ce qui est accepté. Seules les quantités acceptées deviennent liquidables. Les quantités commandées et les prix unitaires viennent de l’engagement.</p>
            </div>
            {!editable && <Alert tone="neutral" icon={ICON.lock}>Le service fait n’est pas modifiable à cette étape ou par votre profil.</Alert>}
            <SectionCard title="Réception" icon={faTruckRampBox}>
                <div className="liq-fields">
                    <FormField label="Date du service fait" required>
                        <input className="inp mono" type="date" disabled={!editable} value={reception.date} onChange={(event) => setReception({ ...reception, date: event.target.value })} />
                    </FormField>
                    <FormField label="Bon de livraison">
                        <input className="inp mono" disabled={!editable} value={reception.bon} onChange={(event) => setReception({ ...reception, bon: event.target.value })} />
                    </FormField>
                    <FormField label="Nature de la prestation" required>
                        <select className="inp" disabled={!editable} value={reception.nature} onChange={(event) => setReception({ ...reception, nature: event.target.value })}>
                            {natures.map((nature) => <option key={nature}>{nature}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Lieu de réception">
                        <input className="inp" disabled={!editable} value={reception.lieu} onChange={(event) => setReception({ ...reception, lieu: event.target.value })} />
                    </FormField>
                </div>
            </SectionCard>
            <SectionCard title="Quantités et conformité" icon={ICON.entry} tag={<><Badge tone="sky" size="sm">ENG</Badge><Badge tone="warning" size="sm">LIQ</Badge></>} flush>
                <div className="table-wrap">
                    <table className="tbl" style={{ minWidth: 860 }}>
                        <thead>
                            <tr>
                                <th>Tâche</th><th>Désignation</th><th className="r">Commandé</th><th>Livré</th><th>Accepté</th><th>Qualité</th><th className="r">PU</th><th className="r">Liquidable</th>
                            </tr>
                        </thead>
                        <tbody>
                            {lines.map((line, index) => {
                                const ordered = Number(line.quantite_commandee);
                                const accepted = Number(line.quantite_acceptee);
                                const gap = Math.max(0, ordered - accepted);
                                return (
                                    <tr key={line.designation}>
                                        <td className="mono" style={{ fontSize: 'var(--text-xs)', color: '#7A5500', fontWeight: 600 }}>{line.tache || '—'}</td>
                                        <td>
                                            <div style={{ fontWeight: 500 }}>{line.designation}</div>
                                            {line.observation && <div className="cell-sub text-warning">{line.observation}</div>}
                                        </td>
                                        <td className="r mono muted">{line.quantite_commandee}</td>
                                        <td style={{ width: 96 }}><input className="inp inp-sm mono align-right" disabled={!editable} aria-label={`Quantité livrée · ${line.designation}`} value={line.quantite_livree} onChange={(event) => updateLine(index, 'quantite_livree', event.target.value)} /></td>
                                        <td style={{ width: 96 }}><input className={`inp inp-sm mono align-right${gap > 0 ? ' is-invalid' : ''}`} style={gap > 0 ? { borderColor: 'var(--warning-solid)', background: '#FFFBEB' } : undefined} disabled={!editable} aria-label={`Quantité acceptée · ${line.designation}`} value={line.quantite_acceptee} onChange={(event) => updateLine(index, 'quantite_acceptee', event.target.value)} /></td>
                                        <td>{gap > 0 ? <Badge tone="warning" icon={ICON.warning} size="sm">{gap} non conforme</Badge> : <Badge tone="success" icon={ICON.success} size="sm">Conforme</Badge>}</td>
                                        <td className="r mono">{fcfa(line.prix_unitaire)}</td>
                                        <td className="cell-amount">{fcfa(Math.round(accepted * Number(line.prix_unitaire || 0)))}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colSpan={7}>Montant liquidable après service fait</td>
                                <td className="cell-amount">{fcfa(liquidable)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </SectionCard>
            <SectionCard title="Observations et réserves" icon={ICON.comment}>
                <FormField label="Réserves" optional hint="Le solde pourra faire l’objet d’une liquidation ultérieure.">
                    <textarea className="inp" rows={3} disabled={!editable} value={reserves} onChange={(event) => setReserves(event.target.value)} />
                </FormField>
            </SectionCard>
            <SectionCard title="Certification du service fait" icon={faSignature} tone="success">
                <label className={`choice${atteste ? ' is-checked' : ''}`} style={{ background: '#fff' }}>
                    <input type="checkbox" checked={atteste} disabled={!editable} onChange={(event) => setAtteste(event.target.checked)} style={{ width: 18, height: 18 }} />
                    <span>Je certifie que les biens, services ou prestations ont été effectivement reçus ou exécutés conformément aux conditions convenues{reserves ? ', sous la réserve mentionnée ci-dessus' : ''}.</span>
                </label>
                <div className="info-grid card" style={{ boxShadow: 'none' }}>
                    <div className="info-item"><span className="info-label">Certificateur</span><span className="info-value">{signataire?.nom || '—'}</span></div>
                    <div className="info-item"><span className="info-label">Fonction</span><span className="info-value">{signataire?.fonction || '—'}</span></div>
                    <div className="info-item"><span className="info-label">Structure</span><span className="info-value">{signataire?.structure || '—'}</span></div>
                    <div className="info-item"><span className="info-label">Horodatage</span><span className="info-value mono">{editable ? 'à la signature' : 'enregistré'}</span></div>
                </div>
                {editable && <div><Button variant="primary" size="lg" icon={faSignature} disabled={!atteste} loading={pending} onClick={onCertify}>Certifier et signer électroniquement</Button></div>}
                <p className="subtle" style={{ display: 'flex', gap: 8 }}><FontAwesomeIcon icon={ICON.security} style={{ marginTop: 3 }} />Séparation des fonctions : la personne qui certifie n’est ni le fournisseur, ni le Contrôleur Financier, ni l’Ordonnateur.</p>
            </SectionCard>
        </>
    );
}

function FactureStep({ dossier, facture, setFacture, ttc, brut, net, lines, taxMode, setTaxMode, onSave, pending }) {
    const editable = dossier.actions.facture;
    function changeMode(mode) {
        setTaxMode(mode);
        setFacture({ ...facture, taxes: mode === 'exo' ? '0' : facture.taxes });
    }
    const checks: CheckItem[] = [
        { ok: Boolean(facture.numero), label: 'Numéro de facture renseigné' },
        { ok: Boolean(facture.date), label: 'Date de facture renseignée' },
        { ok: Number(facture.montant_ht) > 0, label: 'Montant HT positif' },
        { ok: taxMode === 'exo' || (taxMode === 'autre' && facture.taxes !== '' && Number(facture.taxes) >= 0), label: 'Régime et montant des taxes renseignés' },
        { ok: !dossier.doublon, label: dossier.doublon ? `Doublon avec ${dossier.doublon_de?.reference || 'une autre liquidation'}` : 'Aucun doublon détecté' },
    ];
    return (
        <>
            <div className="stack-sm">
                <h2 style={{ fontSize: 'var(--text-lg)' }}>Étape 3 · Facture</h2>
                <p className="muted">Le montant à payer est plafonné par le service fait : ce qui est facturé mais non accepté n’est pas liquidé.</p>
            </div>
            <SectionCard title="Références de la facture" icon={faFileInvoice}>
                <div className="liq-fields">
                    <FormField label="Numéro" required><input className="inp mono" disabled={!editable} value={facture.numero} onChange={(event) => setFacture({ ...facture, numero: event.target.value })} /></FormField>
                    <FormField label="Date" required><input className="inp mono" type="date" disabled={!editable} value={facture.date} onChange={(event) => setFacture({ ...facture, date: event.target.value })} /></FormField>
                    <FormField label="Échéance"><input className="inp mono" type="date" disabled={!editable} value={facture.echeance} onChange={(event) => setFacture({ ...facture, echeance: event.target.value })} /></FormField>
                    <FormField label="Devise"><div className="ro">XAF · FCFA</div></FormField>
                    <FormField label="Fournisseur"><div className="ro">{dossier.fournisseur || '—'}</div></FormField>
                    <FormField label="Référence engagement"><div className="ro mono">{dossier.engagement}</div></FormField>
                    <FormField label="Montant HT" required><AmountInput type="number" disabled={!editable} value={facture.montant_ht} onChange={(value) => setFacture({ ...facture, montant_ht: value })} /></FormField>
                    <FormField label="Régime des taxes" required>
                        <select className="inp" disabled={!editable} value={taxMode} onChange={(event) => changeMode(event.target.value)}>
                            <option value="">Choisir le régime fiscal</option>
                            <option value="exo">Exonération TVA</option>
                            <option value="autre">Taxes selon la facture</option>
                        </select>
                    </FormField>
                    {taxMode === 'autre' && (
                        <FormField label="Montant des taxes" required>
                            <AmountInput type="number" min="0" disabled={!editable} value={facture.taxes} onChange={(value) => setFacture({ ...facture, taxes: value })} aria-label="Montant des taxes indiqué sur la facture" />
                        </FormField>
                    )}
                    <FormField label="Montant TTC facturé"><div className="ro mono strong">{fcfa(ttc)}</div></FormField>
                </div>
            </SectionCard>
            <div className="layout-aside">
                <SectionCard title="Retenues et déductions" icon={faCalculator} flush>
                    <div className="table-wrap">
                        <table className="tbl">
                            <thead><tr><th>Nature</th><th>Commentaire</th><th className="r">Montant (FCFA)</th></tr></thead>
                            <tbody>
                                <tr><td>Retenue de garantie</td><td className="subtle">Déduite du net</td><td className="r" style={{ width: 170 }}><input className="inp inp-sm mono align-right" aria-label="Retenue de garantie" disabled={!editable} value={facture.retenue} onChange={(event) => setFacture({ ...facture, retenue: event.target.value })} /></td></tr>
                                <tr><td>Avance à récupérer</td><td className="subtle">Aucune avance versée</td><td className="r mono">0</td></tr>
                                <tr><td>Pénalité de retard</td><td className="subtle">Déduite du net</td><td className="r"><input className="inp inp-sm mono align-right" aria-label="Pénalité de retard" disabled={!editable} value={facture.penalite} onChange={(event) => setFacture({ ...facture, penalite: event.target.value })} /></td></tr>
                                <tr><td>Avoir</td><td className="subtle">—</td><td className="r mono">0</td></tr>
                            </tbody>
                        </table>
                    </div>
                </SectionCard>
                <SectionCard title="Calcul du montant liquidable" icon={faCalculator} tone="info">
                    <KeyValueList compact items={[
                        { label: 'Montant facturé TTC', value: fcfa(ttc) },
                        { label: 'Non accepté au service fait', value: fcfa(Math.max(0, ttc - brut)), warning: true },
                        { label: 'Montant brut liquidable', value: fcfa(brut), strong: true },
                        { label: 'Retenue de garantie', value: fcfa(facture.retenue) },
                        { label: 'Pénalité de retard', value: fcfa(facture.penalite), warning: true },
                    ]} />
                    <div className="readonly-value is-emphasis" style={{ justifyContent: 'space-between' }}><span style={{ fontFamily: 'var(--font-sans)' }}>Net à payer</span><span>{fcfa(net)}</span></div>
                </SectionCard>
            </div>
            <DetailStep lines={lines} dossier={dossier} />
            <SectionCard title="Contrôles de la facture" icon={ICON.entry} tag={<ChecklistSummary items={checks} />}>
                <Checklist items={checks} />
            </SectionCard>
            {editable && <div className="form-actions"><Button variant="primary" icon={ICON.save} disabled={checks.some((item) => !item.ok)} loading={pending} onClick={onSave}>Enregistrer la facture</Button></div>}
        </>
    );
}

function DetailStep({ lines, dossier }) {
    const columns: Column<any>[] = [
        { key: 'designation', header: 'Désignation', render: (line) => line.designation },
        { key: 'commande', header: 'Commandé', align: 'right', className: 'mono', render: (line) => line.quantite_commandee },
        { key: 'livre', header: 'Livré', align: 'right', className: 'mono', render: (line) => line.quantite_livree },
        { key: 'accepte', header: 'Accepté', align: 'right', className: 'mono', render: (line) => line.quantite_acceptee },
        { key: 'pu', header: 'PU', align: 'right', className: 'mono', render: (line) => fcfa(line.prix_unitaire) },
        { key: 'engage', header: 'Engagé', align: 'right', className: 'mono', render: (line) => fcfa(Math.round(Number(line.quantite_commandee) * Number(line.prix_unitaire))) },
        { key: 'liquider', header: 'À liquider', align: 'right', className: 'cell-amount', render: (line) => fcfa(Math.round(Number(line.quantite_acceptee) * Number(line.prix_unitaire))) },
        { key: 'solde', header: 'Solde', align: 'right', className: 'mono', render: (line) => fcfa(Math.round(Number(line.quantite_commandee) * Number(line.prix_unitaire)) - Math.round(Number(line.quantite_acceptee) * Number(line.prix_unitaire))) },
    ];

    return (
        <SectionCard title="Détail par sous-ligne" icon={ICON.need} flush footer={<span>Imputation héritée de l’engagement : <span className="mono strong">{dossier.ligne}</span>. Elle n’est pas modifiable ici.</span>}>
            <DataTable columns={columns} rows={lines} rowKey={(line, index) => `${line.designation}-${index}`} minWidth={820} />
        </SectionCard>
    );
}

function EngagementStep({ dossier }) {
    return (
        <SectionCard title="Étape 1 · Informations de l’engagement" subtitle="Ces données sont héritées. Elles ne se ressaisissent pas." icon={ICON.commitment}>
            <KeyValueList items={[
                { label: 'Engagement', value: dossier.engagement, mono: true },
                { label: 'Expression de besoin', value: dossier.eb_reference, mono: true },
                { label: 'Objet', value: dossier.objet },
                { label: 'Structure', value: dossier.structure },
                { label: 'Fournisseur', value: dossier.fournisseur },
                { label: 'Ligne', value: dossier.ligne, mono: true },
                { label: 'Montant engagé', value: `${fcfa(dossier.montant_engage)} FCFA`, mono: true, strong: true },
            ]} />
            {(dossier.successives || []).length > 1 && <Successives dossier={dossier} />}
        </SectionCard>
    );
}

function Successives({ dossier }) {
    return (
        <div className="stack-sm">
            <span className="lbl">Liquidations du même engagement</span>
            <ul className="list-rows">
                {(dossier.successives || []).map((row) => (
                    <li key={row.id} className="list-row">
                        <span className="list-row-main"><span className="list-row-title mono">{row.reference}</span><span className="list-row-sub">{row.statut}</span></span>
                        {row.courante && <Badge tone="brand" size="sm">Présente</Badge>}
                        <span className="mono">{fcfa(row.montant_brut)}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function ImputationStep({ dossier }) {
    const rows = dossier.imputations?.length ? dossier.imputations : [{ ligne: dossier.ligne, libelle: '', montant: dossier.montant_brut || dossier.montant_accepte }];
    return (
        <SectionCard title="Étape 5 · Imputations" subtitle="Répartition héritée de l’engagement." icon={ICON.budget} flush>
            <DataTable
                columns={[
                    { key: 'ligne', header: 'Ligne', className: 'mono', render: (row: any) => row.ligne },
                    { key: 'libelle', header: 'Libellé', render: (row: any) => row.libelle || '—' },
                    { key: 'montant', header: 'Montant (FCFA)', align: 'right', className: 'cell-amount', render: (row: any) => fcfa(row.montant) },
                ]}
                rows={rows}
                rowKey={(row: any, index) => `${row.ligne}-${index}`}
            />
        </SectionCard>
    );
}

function PiecesStep({ dossier }) {
    const pieces = dossier.pieces || [];
    return (
        <SectionCard title="Étape 6 · Pièces justificatives" icon={ICON.attachment}>
            <DocumentList items={[
                { name: 'Bon de livraison', meta: dossier.bon_livraison || 'non renseigné', missing: !dossier.bon_livraison },
                { name: 'Attestation de service fait', meta: dossier.service_fait },
                { name: 'Facture', meta: dossier.facture || 'non enregistrée', missing: !dossier.facture },
                ...pieces.map((piece) => ({ name: piece.nom, meta: piece.type })),
            ]} />
            {pieces.length === 0 && <span className="subtle">Les pièces de l’expression de besoin sont reprises automatiquement lorsqu’elles existent.</span>}
            {dossier.id && <GedDossier type="liquidation" entityId={Number(dossier.id)} />}
            {dossier.id && <AuditChronologie type="liquidation" entityId={Number(dossier.id)} />}
        </SectionCard>
    );
}

function Recap({ dossier, lines, liquidable, net }) {
    return (
        <SectionCard title="Étape 7 · Récapitulatif" icon={ICON.entry}>
            <KeyValueList items={[
                { label: 'Service fait', value: dossier.service_fait },
                { label: 'Montant liquidable', value: `${fcfa(liquidable)} FCFA`, mono: true },
                { label: 'Facture', value: dossier.facture, mono: true },
                { label: 'Net à payer', value: `${fcfa(dossier.montant_net || net)} FCFA`, mono: true, strong: true },
                { label: 'Sous-lignes', value: String(lines.length) },
                { label: 'Doublon', value: dossier.doublon ? <Badge tone="danger" icon={ICON.warning}>Oui, soumission bloquée</Badge> : <Badge tone="success" icon={ICON.success}>Aucun</Badge> },
            ]} />
        </SectionCard>
    );
}

function share(part, total) {
    if (!total) {
        return 0;
    }
    return Math.max(0, Math.min(100, Math.round((Number(part) / Number(total)) * 100)));
}

function ControleLiquidation({ dossier, lines, net }) {
    const ordered = lines.reduce((sum, line) => sum + Math.round(Number(line.quantite_commandee) * Number(line.prix_unitaire)), 0);
    const accepted = lines.reduce((sum, line) => sum + Math.round(Number(line.quantite_acceptee) * Number(line.prix_unitaire)), 0);
    const checks: CheckItem[] = [
        { ok: Boolean(dossier.service_fait_le), label: 'Service fait certifié' },
        { ok: Boolean(dossier.facture), label: 'Facture enregistrée' },
        { ok: !dossier.doublon, label: 'Facture sans doublon' },
        { ok: Number(dossier.montant_net) > 0, label: 'Net à payer positif' },
        { ok: Number(dossier.montant_brut) <= Number(dossier.montant_engage), label: 'Brut dans le plafond de l’engagement' },
        { ok: accepted <= ordered || ordered === 0, label: 'Quantités acceptées dans le commandé' },
    ];
    const complete = checks.every((item) => item.ok);
    const returns = (dossier.historique || []).filter((event) => event.action === 'retour' || event.action === 'complement');
    return (
        <>
            <SectionCard title="Check-list de liquidation" icon={ICON.visa} tag={<ChecklistSummary items={checks} />} tone={complete ? 'default' : 'warning'}>
                <Checklist items={checks} />
                <Alert tone={complete ? 'success' : 'warning'} title={complete ? 'Dossier complet' : 'Dossier incomplet'} />
            </SectionCard>
            <SectionCard title="Du brut au net" icon={faCalculator}>
                <KeyValueList compact items={[
                    { label: 'Brut liquidable', value: fcfa(dossier.montant_brut) },
                    { label: 'Retenue', value: fcfa(dossier.retenue_garantie) },
                    { label: 'Pénalité', value: fcfa(dossier.penalite), warning: Number(dossier.penalite) > 0 },
                    { label: 'Net à payer', value: fcfa(dossier.montant_net || net), strong: true },
                ]} />
            </SectionCard>
            <SectionCard title="Contrôle par livrable" icon={ICON.entry} flush>
                <DataTable
                    columns={[
                        { key: 'designation', header: 'Désignation', render: (line: any) => line.designation },
                        { key: 'commande', header: 'Commandé', align: 'right', className: 'mono', render: (line: any) => line.quantite_commandee },
                        { key: 'accepte', header: 'Accepté', align: 'right', className: 'mono', render: (line: any) => line.quantite_acceptee },
                        { key: 'taux', header: 'Taux', align: 'right', className: 'mono', render: (line: any) => `${Number(line.quantite_commandee) > 0 ? Math.round((Number(line.quantite_acceptee) / Number(line.quantite_commandee)) * 100) : 0} %` },
                    ]}
                    rows={lines}
                    rowKey={(line: any, index) => `${line.designation}-${index}`}
                />
            </SectionCard>
            <SectionCard title="Liquidations du même engagement" icon={ICON.commitment}>
                {(dossier.successives || []).length < 2 ? <p className="muted">Une seule liquidation est ouverte sur cet engagement.</p> : <Successives dossier={dossier} />}
            </SectionCard>
            <SectionCard title="Versions" icon={faCodeBranch}>
                <p>{returns.length === 0 ? 'Version unique. Aucun retour ni complément n’a encore modifié ce dossier.' : `${returns.length} retour ou complément. La version courante est celle soumise après correction.`}</p>
            </SectionCard>
        </>
    );
}

function Versions({ dossier }) {
    const returns = (dossier.historique || []).filter((event) => ['retour', 'complement', 'demande_doublon'].includes(event.action));
    return (
        <SectionCard title="Versions" icon={faCodeBranch}>
            <WorkflowTimeline
                emptyText="Version unique, sans écart depuis un retour."
                events={returns.map((event) => ({ action: event.action, actor: event.acteur, date: event.le, note: event.motif }))}
            />
        </SectionCard>
    );
}

function History({ dossier }) {
    return <WorkflowTimeline events={(dossier.historique || []).map((event) => ({ action: event.action, actor: event.acteur || 'Système', date: event.le, note: event.motif, system: !event.acteur }))} />;
}
