import { faCircleCheck, faCircleExclamation, faLightbulb, faTrash } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    Alert,
    AmountInput,
    Badge,
    Button,
    DocumentList,
    EmptyState,
    ErrorMessage,
    FileDrop,
    FormField,
    ICON,
    KeyValueList,
    NatureBadge,
    PageHeader,
    ProgressBar,
    SearchInput,
    SectionCard,
    Skeleton,
    Stepper,
    useToast,
} from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';

const STEPS = ['Ligne budgétaire', 'Contexte budgétaire', 'Contexte programmatique', 'Description du besoin', 'Tâches et détails', 'Imputation', 'Pièces jointes', 'Récapitulatif', 'Soumission'];

const EMPTY_LINE = { designation: '', quantite: 1, unite: 'forfait', prix_unitaire: 0, pap_task_id: '', description: '', beneficiaire: '', lieu: '', periode: '', observation: '' };

export default function EbWizard() {
    const { id } = useParams();
    const navigate = useNavigate();
    const toast = useToast();
    const [step, setStep] = useState(id ? 3 : 0);
    const [dossier, setDossier] = useState<any>(null);
    const [search, setSearch] = useState('');
    const [lines, setLines] = useState<any[]>([]);
    const [linesLoading, setLinesLoading] = useState(true);
    const [form, setForm] = useState<any>({ objet: '', contexte: '', justification: '', urgence: 'normale', priorite: 'normale', resultats_attendus: '', lignes: [{ ...EMPTY_LINE }], imputations: [] });
    const [error, setError] = useState('');
    const [taskLabel, setTaskLabel] = useState('');
    const [docType, setDocType] = useState('Note justificative');
    const [file, setFile] = useState<File | null>(null);
    const [pending, setPending] = useState<string | null>(null);

    useEffect(() => {
        if (!id) return;
        api.get(`/expressions-besoin/${id}`).then((response) => hydrate(response.data.data)).catch((caught) => setError(errorsOf(caught)));
    }, [id]);

    useEffect(() => {
        setLinesLoading(true);
        const timer = setTimeout(() => {
            api.get('/lignes-budgetaires', { params: { q: search } })
                .then((response) => setLines(response.data.data))
                .finally(() => setLinesLoading(false));
        }, 250);
        return () => clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        if (dossier?.types_pieces?.length && !dossier.types_pieces.includes(docType)) {
            setDocType(dossier.types_pieces[0]);
        }
    }, [dossier?.types_pieces]);

    function hydrate(data) {
        setDossier(data);
        setForm({
            objet: data.objet || '',
            contexte: data.contexte || '',
            justification: data.justification || '',
            urgence: data.urgence || 'normale',
            priorite: data.priorite || 'normale',
            resultats_attendus: data.resultats_attendus || '',
            lignes: data.lignes?.length ? data.lignes.map((line) => ({ ...line, pap_task_id: line.pap_task_id || '' })) : [{ ...EMPTY_LINE }],
            imputations: data.imputations?.length ? data.imputations : [],
        });
    }

    async function chooseLine(line) {
        setError('');
        setPending(`ligne-${line.id}`);
        try {
            const response = await api.post('/expressions-besoin', { budget_line_id: line.id });
            hydrate(response.data.data);
            setStep(1);
            toast.success(`Brouillon ${response.data.data.reference} créé sur la ligne ${line.code}.`);
            navigate(`/expressions-besoin/${response.data.data.id}/modifier`, { replace: true });
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function save() {
        if (!dossier) {
            return;
        }
        const payload = {
            ...form,
            lignes: form.lignes.filter((line) => line.designation).map((line) => ({
                ...line,
                quantite: Number(line.quantite),
                prix_unitaire: Number(line.prix_unitaire),
                pap_task_id: line.pap_task_id || null,
            })),
            imputations: form.imputations.map((row) => ({ budget_line_id: row.budget_line_id, montant: Number(row.montant) })),
        };
        const response = await api.patch(`/expressions-besoin/${dossier.id}`, payload);
        hydrate(response.data.data);
    }

    async function saveDraft() {
        setError('');
        setPending('save');
        try {
            await save();
            toast.success('Brouillon enregistré.');
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function next() {
        setError('');
        setPending('next');
        try {
            if (dossier && step >= 3) await save();
            setStep((current) => Math.min(current + 1, STEPS.length - 1));
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function submit() {
        setError('');
        setPending('submit');
        try {
            await save();
            await api.post(`/expressions-besoin/${dossier.id}/soumettre`);
            toast.success('Expression de besoin soumise au circuit de validation.');
            navigate(`/expressions-besoin/${dossier.id}`);
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function proposeTask() {
        if (!taskLabel || !dossier) return;
        setPending('task');
        try {
            await api.post(`/lignes-budgetaires/${dossier.ligne.id}/taches`, { libelle: taskLabel });
            setTaskLabel('');
            const response = await api.get(`/expressions-besoin/${dossier.id}`);
            hydrate(response.data.data);
            toast.success('Tâche proposée au référentiel.');
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function upload() {
        if (!file || !dossier) return;
        setPending('upload');
        try {
            const body = new FormData();
            body.append('type', docType);
            body.append('fichier', file);
            const response = await api.post(`/expressions-besoin/${dossier.id}/documents`, body);
            hydrate(response.data.data);
            setFile(null);
            toast.success('Pièce jointe ajoutée.');
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    function updateLine(index, key, value) {
        const lignes = form.lignes.map((line, lineIndex) => lineIndex === index ? { ...line, [key]: value } : line);
        setForm({ ...form, lignes });
    }

    function removeLine(index) {
        const lignes = form.lignes.filter((_, lineIndex) => lineIndex !== index);
        setForm({ ...form, lignes: lignes.length ? lignes : [{ ...EMPTY_LINE }] });
    }

    const total = form.lignes.reduce((sum, line) => sum + Math.round(Number(line.quantite || 0) * Number(line.prix_unitaire || 0)), 0);

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: dossier ? `/expressions-besoin/${dossier.id}` : '/expressions-besoin', label: dossier ? 'Fiche de l’expression de besoin' : 'Expressions de besoin' }}
                eyebrow={dossier ? <><span className="mono strong">{dossier.reference}</span><NatureBadge nature={dossier.nature} libelle={dossier.nature_libelle} /></> : 'Assistant de saisie'}
                title={id ? 'Modifier l’expression de besoin' : 'Nouvelle expression de besoin'}
                subtitle="Neuf étapes, du choix de la ligne budgétaire à la soumission. Le brouillon est enregistré à chaque étape à partir de la description."
                figure={dossier ? { label: 'Total de l’EB', value: fcfa(total), unit: 'FCFA' } : undefined}
            />

            <div className="card">
                <Stepper
                    label="Étapes de l’expression de besoin"
                    steps={STEPS.map((label, index) => ({ label, state: index < step ? 'done' : index === step ? 'current' : 'todo' }))}
                    onSelect={dossier ? (index) => setStep(index) : undefined}
                />
            </div>

            <ErrorMessage error={error} onClose={() => setError('')} />

            <SectionCard title={`Étape ${step + 1} sur ${STEPS.length} · ${STEPS[step]}`} icon={ICON.need}>
                {step === 0 && (
                    <div className="stack">
                        <p className="muted">Choisissez la ligne du budget voté sur laquelle porte le besoin. La nature PAP / Hors PAP et le disponible en découlent.</p>
                        <SearchInput value={search} onChange={setSearch} placeholder="Code, libellé, structure, activité…" label="Rechercher une ligne budgétaire" autoFocus />
                        {linesLoading && lines.length === 0 && (
                            <div className="stack-sm">{Array.from({ length: 4 }).map((_, index) => <Skeleton key={index} height={58} radius={8} />)}</div>
                        )}
                        {!linesLoading && lines.length === 0 && <EmptyState icon={ICON.budget} title="Aucune ligne trouvée" compact>Modifiez votre recherche.</EmptyState>}
                        <ul className="stack-sm" role="list">
                            {lines.map((line) => (
                                <li key={line.id}>
                                    <button type="button" className="choice" style={{ width: '100%', textAlign: 'left', alignItems: 'center', font: 'inherit' }} onClick={() => chooseLine(line)} disabled={pending !== null}>
                                        <span className="card-title-icon" aria-hidden="true"><FontAwesomeIcon icon={ICON.budget} /></span>
                                        <span style={{ flex: 1, minWidth: 0 }}>
                                            <span className="mono strong">{line.code}</span> · {line.libelle}
                                            <span className="subtle" style={{ display: 'block' }}>{line.structure}</span>
                                        </span>
                                        <span className="stack-sm" style={{ alignItems: 'flex-end', gap: 4 }}>
                                            <NatureBadge nature={line.nature} libelle={line.nature_libelle} />
                                            <span className="mono" style={{ fontSize: 'var(--text-sm)' }}>{fcfa(line.disponible)} <span className="subtle">disponible</span></span>
                                        </span>
                                        {pending === `ligne-${line.id}` ? <span className="spinner" aria-label="Création du brouillon" /> : <FontAwesomeIcon icon={ICON.next} style={{ color: 'var(--slate-400)' }} />}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                {dossier && step === 1 && <BudgetContext dossier={dossier} />}
                {dossier && step === 2 && <ProgramContext dossier={dossier} />}
                {dossier && step === 3 && (
                    <div className="form-grid">
                        <FormField label="Objet" required className="span-all">
                            <input className="inp" value={form.objet} onChange={(event) => setForm({ ...form, objet: event.target.value })} />
                        </FormField>
                        <FormField label="Contexte" className="span-all">
                            <textarea className="inp" rows={3} value={form.contexte} onChange={(event) => setForm({ ...form, contexte: event.target.value })} />
                        </FormField>
                        <FormField label="Justification" required className="span-all" hint="Pourquoi ce besoin, et pourquoi maintenant. Elle est reprise dans l’engagement.">
                            <textarea className="inp" rows={4} value={form.justification} onChange={(event) => setForm({ ...form, justification: event.target.value })} />
                        </FormField>
                        <FormField label="Urgence">
                            <select className="inp" value={form.urgence} onChange={(event) => setForm({ ...form, urgence: event.target.value })}>
                                <option value="faible">Faible</option><option value="normale">Normale</option><option value="urgente">Urgente</option>
                            </select>
                        </FormField>
                        <FormField label="Priorité">
                            <select className="inp" value={form.priorite} onChange={(event) => setForm({ ...form, priorite: event.target.value })}>
                                <option value="basse">Basse</option><option value="normale">Normale</option><option value="haute">Haute</option>
                            </select>
                        </FormField>
                        <FormField label="Résultats attendus" className="span-all">
                            <textarea className="inp" rows={3} value={form.resultats_attendus} onChange={(event) => setForm({ ...form, resultats_attendus: event.target.value })} />
                        </FormField>
                    </div>
                )}
                {dossier && step === 4 && (
                    <div className="stack">
                        {dossier.referentiel?.taches?.length > 0 && (
                            <Alert tone="neutral" icon={faLightbulb} title="Tâches du référentiel PAP">{dossier.referentiel.taches.map((task) => task.libelle).join(' · ')}</Alert>
                        )}
                        {dossier.nature === 'pap' && (
                            <div className="cluster" style={{ alignItems: 'flex-end' }}>
                                <FormField label="Proposer une tâche au référentiel" optional style={{ flex: '1 1 320px' }}>
                                    <input className="inp" value={taskLabel} onChange={(event) => setTaskLabel(event.target.value)} />
                                </FormField>
                                <Button icon={ICON.create} onClick={proposeTask} disabled={!taskLabel.trim()} loading={pending === 'task'}>Proposer</Button>
                            </div>
                        )}
                        <div className="table-wrap card" style={{ boxShadow: 'none' }}>
                            <table className="tbl is-compact" style={{ minWidth: 1180 }}>
                                <thead>
                                    <tr>
                                        <th>Tâche</th><th>Désignation *</th><th className="r">Qté</th><th>Unité</th><th className="r">PU (FCFA)</th>
                                        <th>Bénéficiaire</th><th>Lieu</th><th>Période</th><th>Observation</th><th className="r">Montant</th><th><span className="sr-only">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {form.lignes.map((line, index) => (
                                        <tr key={index}>
                                            <td style={{ minWidth: 150 }}>
                                                <select className="inp inp-sm" aria-label={`Tâche ${index + 1}`} value={line.pap_task_id || ''} onChange={(event) => updateLine(index, 'pap_task_id', event.target.value)}>
                                                    <option value="">—</option>
                                                    {(dossier.referentiel?.taches || []).map((task) => <option key={task.id} value={task.id}>{task.libelle}</option>)}
                                                </select>
                                            </td>
                                            <td style={{ minWidth: 200 }}><input className="inp inp-sm" aria-label={`Désignation ${index + 1}`} value={line.designation} onChange={(event) => updateLine(index, 'designation', event.target.value)} /></td>
                                            <td style={{ width: 80 }}><input className="inp inp-sm align-right num" type="number" min="0" aria-label={`Quantité ${index + 1}`} value={line.quantite} onChange={(event) => updateLine(index, 'quantite', event.target.value)} /></td>
                                            <td style={{ width: 100 }}><input className="inp inp-sm" aria-label={`Unité ${index + 1}`} value={line.unite} onChange={(event) => updateLine(index, 'unite', event.target.value)} /></td>
                                            <td style={{ width: 130 }}><input className="inp inp-sm align-right num" type="number" min="0" aria-label={`Prix unitaire ${index + 1}`} value={line.prix_unitaire} onChange={(event) => updateLine(index, 'prix_unitaire', event.target.value)} /></td>
                                            <td><input className="inp inp-sm" aria-label={`Bénéficiaire ${index + 1}`} value={line.beneficiaire || ''} onChange={(event) => updateLine(index, 'beneficiaire', event.target.value)} /></td>
                                            <td><input className="inp inp-sm" aria-label={`Lieu ${index + 1}`} value={line.lieu || ''} onChange={(event) => updateLine(index, 'lieu', event.target.value)} /></td>
                                            <td><input className="inp inp-sm" aria-label={`Période ${index + 1}`} value={line.periode || ''} onChange={(event) => updateLine(index, 'periode', event.target.value)} /></td>
                                            <td><input className="inp inp-sm" aria-label={`Observation ${index + 1}`} value={line.observation || ''} onChange={(event) => updateLine(index, 'observation', event.target.value)} /></td>
                                            <td className="cell-amount">{fcfa(Math.round(Number(line.quantite || 0) * Number(line.prix_unitaire || 0)))}</td>
                                            <td className="cell-actions">
                                                <Button variant="ghost" size="sm" iconOnly icon={faTrash} onClick={() => removeLine(index)} disabled={form.lignes.length === 1 && !line.designation}>{`Retirer la sous-ligne ${index + 1}`}</Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr><td colSpan={9}>Total de l’expression de besoin</td><td className="cell-amount">{fcfa(total)}</td><td /></tr>
                                </tfoot>
                            </table>
                        </div>
                        <div className="split">
                            <Button icon={ICON.create} onClick={() => setForm({ ...form, lignes: [...form.lignes, { ...EMPTY_LINE }] })}>Ajouter une sous-ligne</Button>
                            <span className="subtle">Seules les sous-lignes avec une désignation sont enregistrées.</span>
                        </div>
                    </div>
                )}
                {dossier && step === 5 && (
                    <div className="stack">
                        <Alert tone="info">Le total des imputations doit être égal à {fcfa(total)} FCFA. La ligne de référence est héritée et n’est pas ressaisie.</Alert>
                        <ImputationEditor dossier={dossier} form={form} setForm={setForm} total={total} />
                    </div>
                )}
                {dossier && step === 6 && (
                    <div className="stack">
                        <div className="form-grid" style={{ ['--cols' as string]: 3, alignItems: 'end' }}>
                            <FormField label="Type de pièce" required>
                                <select className="inp" value={docType} onChange={(event) => setDocType(event.target.value)}>
                                    {(dossier.types_pieces ?? []).map((type) => <option key={type}>{type}</option>)}
                                </select>
                            </FormField>
                            <div className="span-2"><FileDrop file={file} onFile={setFile} hint="PDF ou image du justificatif." /></div>
                        </div>
                        <div className="form-actions">
                            <Button variant="primary" icon={ICON.upload} onClick={upload} disabled={!file} loading={pending === 'upload'}>Joindre la pièce</Button>
                        </div>
                        <DocumentList items={(dossier.documents ?? []).map((document) => ({ name: document.nom, meta: document.type }))} emptyTitle="Aucune pièce jointe" emptyText="Ajoutez les justificatifs attendus par le circuit de validation." />
                    </div>
                )}
                {dossier && step >= 7 && (
                    <div className="stack">
                        <KeyValueList items={[
                            { label: 'Objet', value: form.objet || 'Objet non renseigné', strong: true },
                            { label: 'Nature', value: <NatureBadge nature={dossier.nature} libelle={dossier.nature_libelle} /> },
                            { label: 'Ligne budgétaire', value: <span className="mono">{dossier.ligne.code}</span> },
                            { label: 'Montant total', value: `${fcfa(total)} FCFA`, mono: true, strong: true },
                            { label: 'Justification', value: form.justification },
                            { label: 'Sous-lignes', value: `${form.lignes.filter((line) => line.designation).length} sous-ligne(s)` },
                            { label: 'Pièces', value: `${dossier.documents?.length || 0} pièce(s)` },
                        ]} />
                        {step === 8 && (
                            <Alert tone="info" title="Prêt pour la soumission">La soumission transmet le dossier au premier acteur du circuit. Vous ne pourrez plus le modifier, sauf en cas de retour pour correction.</Alert>
                        )}
                    </div>
                )}
            </SectionCard>

            <div className="form-actions between">
                <Button icon={ICON.back} disabled={step === 0} onClick={() => setStep((current) => current - 1)}>Précédent</Button>
                <div className="btn-group">
                    {dossier && <Button icon={ICON.save} onClick={saveDraft} loading={pending === 'save'}>Enregistrer le brouillon</Button>}
                    {step < 8 && <Button variant="brand" iconRight={ICON.next} disabled={step === 0 && !dossier} loading={pending === 'next'} onClick={next}>Suivant</Button>}
                    {step === 8 && <Button variant="primary" icon={ICON.submit} onClick={submit} loading={pending === 'submit'}>Soumettre</Button>}
                </div>
            </div>
        </main>
    );
}

function BudgetContext({ dossier }) {
    return (
        <div className="grid-halves">
            <div className="card" style={{ padding: 16, boxShadow: 'none', background: 'var(--slate-50)' }}>
                <div className="split" style={{ marginBottom: 8 }}><span className="lbl">Ligne budgétaire</span><span className="tag tag-budget">BUDGET</span></div>
                <KeyValueList items={[
                    { label: 'Code', value: <span className="mono">{dossier.ligne.code}</span> },
                    { label: 'Libellé', value: dossier.ligne.libelle },
                ]} />
            </div>
            <div className="card" style={{ padding: 16, boxShadow: 'none', background: 'var(--slate-50)' }}>
                <KeyValueList items={[
                    { label: 'Disponible', value: `${fcfa(dossier.ligne.disponible)} FCFA`, mono: true, strong: true },
                    { label: 'Classification', value: dossier.nature_libelle },
                    { label: 'Structure', value: dossier.structure },
                ]} />
            </div>
        </div>
    );
}

function ProgramContext({ dossier }) {
    const line = dossier.ligne;
    const ref = dossier.referentiel;
    const validated = ref?.statut === 'valide' || ref?.statut === 'validee';
    if (dossier.nature !== 'pap') {
        return <Alert tone="neutral" title="Ligne Hors PAP">Les informations programmatiques du PAP ne sont pas exigées. Le besoin reste rattaché au Budget unique de l’exercice {dossier.exercice || ''}.</Alert>;
    }
    return (
        <div className="liq-split">
            <div className="stack">
                <div className="stack">
                    <h3 className="form-section-title">A · Données du Budget adopté <span className="tag tag-budget">BUDGET</span></h3>
                    <div className="liq-fields">
                        <Locked label="Exercice" value={dossier.exercice} />
                        <Locked label="Code budgétaire" value={line.code} />
                        <Locked label="Classification" value={dossier.nature_libelle} />
                        <Locked label="Libellé officiel" value={line.libelle} />
                        <Locked label="Structure" value={dossier.structure} />
                        <Locked label="Nature de la dépense" value={line.nature_depense} />
                        <Locked label="Montant voté" value={`${fcfa(line.montant_vote)} FCFA`} />
                        <Locked label="Chapitre" value={line.chapitre} />
                        <Locked label="Article" value={line.article} />
                        <Locked label="Paragraphe" value={line.paragraphe} />
                    </div>
                </div>
                <div className="stack" style={{ paddingTop: 16, borderTop: '1px solid var(--color-divider)' }}>
                    <h3 className="form-section-title">B · Référentiel d’enrichissement PAP <span className="tag tag-ref">RÉFÉRENTIEL</span></h3>
                    {ref ? (
                        <div className="stack-sm">
                            <RefRow label="Pilier" value={ref.pilier} validated={validated} />
                            <RefRow label="Axe stratégique" value={ref.axe} validated={validated} />
                            <RefRow label="Produit" value={ref.produit} validated={validated} />
                            <RefRow label="Activité" value={ref.activite} validated={validated} />
                            <RefRow label="Résultat attendu" value={ref.resultats_attendus} validated={validated} />
                            <RefRow label="Unité responsable" value={ref.unite_responsable} validated={validated} />
                            <RefRow label="Période de réalisation" value={ref.periode} validated={validated} />
                        </div>
                    ) : <Alert tone="danger">Informations programmatiques incomplètes.</Alert>}
                </div>
            </div>
            <aside className="card" style={{ padding: 16, boxShadow: 'none', background: 'var(--slate-50)', display: 'flex', flexDirection: 'column', gap: 12 }}>
                <span className="lbl">Complétude programmatique</span>
                <span className="stat-value">{dossier.completude ?? 0} %</span>
                <ProgressBar value={dossier.completude} label="Complétude programmatique" />
                <ul className="checklist">
                    {dossier.completude_elements?.map((item) => (
                        <li key={item.cle} className={`checklist-item is-${item.renseigne ? 'ok' : 'ko'}`} style={{ gridTemplateColumns: '20px minmax(0, 1fr)' }}>
                            <FontAwesomeIcon icon={item.renseigne ? faCircleCheck : faCircleExclamation} className="checklist-icon" />
                            <span className="checklist-label">{item.libelle} <span className="subtle">— {item.renseigne ? 'renseigné' : 'non renseigné'}</span></span>
                        </li>
                    ))}
                </ul>
                <KeyValueList items={[
                    { label: 'Budget officiel', value: <span className="mono">{line.code} · {fcfa(line.disponible)} disponible</span> },
                    { label: 'Expression de besoin', value: `${dossier.reference} · ${fcfa(dossier.montant)} FCFA` },
                    { label: 'Workflow applicable', value: (dossier.circuit || []).map((step) => step.libelle).join(' → ') || 'Initiateur, puis circuit de validation' },
                ]} />
            </aside>
        </div>
    );
}

function Locked({ label, value }) {
    return (
        <div className="field">
            <span className="field-label" style={{ fontSize: 'var(--text-xs)' }}><FontAwesomeIcon icon={ICON.lock} style={{ color: 'var(--slate-400)', fontSize: 10 }} />{label}</span>
            <div className="ro">{value || '—'}</div>
        </div>
    );
}

function RefRow({ label, value, validated }) {
    return (
        <div className="split" style={{ padding: '8px 0', borderBottom: '1px solid var(--color-divider)' }}>
            <div><div className="lbl">{label}</div><div>{value || <span className="muted">Non renseigné</span>}</div></div>
            <Badge tone={validated ? 'success' : 'warning'} icon={validated ? faCircleCheck : faCircleExclamation}>{validated ? 'Validée' : 'À compléter'}</Badge>
        </div>
    );
}

function ImputationEditor({ dossier, form, setForm, total }) {
    const rows = form.imputations.length ? form.imputations : [{ budget_line_id: dossier.ligne.id, code: dossier.ligne.code, libelle: dossier.ligne.libelle, montant: total }];
    const imputed = rows.reduce((sum, row) => sum + Number(row.montant || 0), 0);
    const balanced = imputed === total;

    return (
        <div className="stack">
            {rows.map((row, index) => (
                <div key={index} className="form-grid" style={{ alignItems: 'end' }}>
                    <FormField label="Ligne d’imputation">
                        <div className="ro"><span className="mono strong" style={{ marginRight: 8 }}>{row.code || dossier.ligne.code}</span>{row.libelle || dossier.ligne.libelle}</div>
                    </FormField>
                    <FormField label="Montant imputé" required>
                        <AmountInput value={row.montant} onChange={(value) => {
                            const imputations = rows.map((item, itemIndex) => itemIndex === index ? { ...item, budget_line_id: item.budget_line_id || dossier.ligne.id, montant: Number(value) } : item);
                            setForm({ ...form, imputations });
                        }} type="number" />
                    </FormField>
                </div>
            ))}
            <KeyValueList compact items={[
                { label: 'Total des sous-lignes', value: `${fcfa(total)} FCFA` },
                { label: 'Total imputé', value: `${fcfa(imputed)} FCFA`, warning: !balanced },
                { label: 'Solde prévisionnel de la ligne', value: `${fcfa((dossier.ligne.disponible || 0) - total)} FCFA`, strong: true },
            ]} />
            {!balanced && <Alert tone="warning">Le total imputé diffère du total de l’expression de besoin.</Alert>}
        </div>
    );
}
