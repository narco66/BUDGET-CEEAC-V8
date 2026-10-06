import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    Badge,
    Button,
    EmptyState,
    ErrorMessage,
    FileDrop,
    FormField,
    ICON,
    KeyValueList,
    PageHeader,
    SectionCard,
    TableSkeleton,
    useToast,
} from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';

type Execution = { reference: string; dateValeur: string; preuve: File | null };
const EMPTY_EXECUTION: Execution = { reference: '', dateValeur: '', preuve: null };

export default function PayLots() {
    const toast = useToast();
    const [lots, setLots] = useState<any[]>([]);
    const [eligibles, setEligibles] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [choisis, setChoisis] = useState<number[]>([]);
    const [libelle, setLibelle] = useState('Virements du jour');
    const [executions, setExecutions] = useState<Record<number, Execution>>({});
    const [error, setError] = useState('');
    const [pending, setPending] = useState<string | null>(null);

    function load() {
        setLoading(true);
        api.get('/paiements/lots')
            .then((response) => {
                setLots(response.data.data);
                setEligibles(response.data.eligibles);
            })
            .catch((caught) => setError(errorsOf(caught)))
            .finally(() => setLoading(false));
    }

    useEffect(() => {
        load();
    }, []);

    function toggle(id) {
        setChoisis((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));
    }

    function execution(id: number): Execution {
        return executions[id] ?? EMPTY_EXECUTION;
    }

    function setExecution(id: number, partial: Partial<Execution>) {
        setExecutions((current) => ({ ...current, [id]: { ...execution(id), ...partial } }));
    }

    async function creer() {
        setError('');
        setPending('creer');
        try {
            await api.post('/paiements/lots', { libelle, paiements: choisis });
            setChoisis([]);
            toast.success('Lot de paiement créé.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function executer(id) {
        setError('');
        const { reference, dateValeur, preuve } = execution(id);
        if (!preuve) {
            setError('Joignez l’avis bancaire du lot.');
            return;
        }
        setPending(`lot-${id}`);
        try {
            const body = new FormData();
            body.append('reference', reference);
            body.append('date_valeur', dateValeur);
            body.append('preuve', preuve);
            await api.post(`/paiements/lots/${id}/executer`, body);
            setExecutions((current) => ({ ...current, [id]: EMPTY_EXECUTION }));
            toast.success('Lot exécuté.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    const total = eligibles.filter((row) => choisis.includes(row.id)).reduce((sum, row) => sum + Number(row.montant || 0), 0);

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/paiements', label: 'Paiements' }}
                title="Lots de paiement"
                subtitle="Un lot regroupe des virements déjà autorisés. Un paiement non conforme reste visible et n’entre pas dans le total."
            />

            <ErrorMessage error={error} onClose={() => setError('')} />

            <SectionCard
                title="Nouveau lot"
                icon={ICON.paymentBatch}
                subtitle="Sélectionnez au moins deux virements autorisés."
                footer={eligibles.length > 0 && (
                    <>
                        <span><b className="mono">{choisis.length}</b> virement(s) sélectionné(s) · <b className="mono">{fcfa(total)}</b> FCFA</span>
                        <span style={{ marginLeft: 'auto' }}>
                            <Button variant="primary" icon={ICON.create} disabled={choisis.length < 2 || !libelle.trim()} loading={pending === 'creer'} onClick={creer}>Créer le lot</Button>
                        </span>
                    </>
                )}
            >
                <FormField label="Libellé du lot" required style={{ maxWidth: 420 }}>
                    <input className="inp" value={libelle} onChange={(event) => setLibelle(event.target.value)} />
                </FormField>
                {loading && eligibles.length === 0 ? <TableSkeleton rows={3} columns={3} /> : eligibles.length === 0 ? (
                    <EmptyState icon={ICON.payment} title="Aucun virement en attente de lot" compact>Les virements autorisés apparaîtront ici.</EmptyState>
                ) : (
                    <div className="stack-sm">
                        {eligibles.map((row) => (
                            <label key={row.id} className={`choice${choisis.includes(row.id) ? ' is-checked' : ''}`} style={{ alignItems: 'center' }}>
                                <input type="checkbox" checked={choisis.includes(row.id)} onChange={() => toggle(row.id)} />
                                <span className="mono strong" style={{ minWidth: 150 }}>{row.reference}</span>
                                <span style={{ flex: 1 }}>{row.titulaire}</span>
                                <span className="mono">{fcfa(row.montant)} <span className="subtle">FCFA</span></span>
                            </label>
                        ))}
                    </div>
                )}
            </SectionCard>

            {!loading && lots.length === 0 && (
                <div className="card"><EmptyState icon={ICON.paymentBatch} title="Aucun lot constitué">Les lots créés et leurs exécutions figureront ici.</EmptyState></div>
            )}

            {lots.map((lot) => {
                const form = execution(lot.id);

                return (
                    <SectionCard
                        key={lot.id}
                        title={<span className="mono">{lot.reference}</span>}
                        subtitle={lot.libelle}
                        icon={ICON.paymentBatch}
                        tag={<Badge tone={lot.statut === 'ouvert' ? 'warning' : 'success'} dot>{lot.statut}</Badge>}
                        actions={<span className="mono strong">{fcfa(lot.montant)} FCFA</span>}
                    >
                        <KeyValueList items={[
                            { label: 'Compte débiteur', value: lot.compte_debiteur || 'Compte débiteur non renseigné' },
                            { label: 'Exécution', value: lot.reference_reglement ? `${lot.reference_reglement} · ${lot.date_valeur}` : null, hidden: !lot.reference_reglement, mono: true },
                        ]} />
                        <ul className="list-rows">
                            {lot.paiements.map((row) => (
                                <li key={row.id}>
                                    <Link to={`/paiements/${row.id}`} className="list-row">
                                        <span className="list-row-main"><span className="list-row-title mono">{row.reference}</span></span>
                                        <Badge tone="neutral" size="sm">{row.statut}</Badge>
                                        <span className="mono">{fcfa(row.montant)} FCFA</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        {lot.statut === 'ouvert' && (
                            <div className="stack" style={{ paddingTop: 12, borderTop: '1px solid var(--color-divider)' }}>
                                <h3 className="form-section-title">Exécution du lot</h3>
                                <div className="form-grid">
                                    <FormField label="Référence du virement de lot" required>
                                        <input className="inp mono" value={form.reference} onChange={(event) => setExecution(lot.id, { reference: event.target.value })} />
                                    </FormField>
                                    <FormField label="Date de valeur" required>
                                        <input className="inp" type="date" value={form.dateValeur} onChange={(event) => setExecution(lot.id, { dateValeur: event.target.value })} />
                                    </FormField>
                                    <div className="field span-all">
                                        <span className="field-label">Avis bancaire du lot <span className="field-required" aria-hidden="true">*</span></span>
                                        <FileDrop file={form.preuve} onFile={(preuve) => setExecution(lot.id, { preuve })} accept="application/pdf,image/png,image/jpeg" hint="PDF, PNG ou JPEG." label="Choisir l’avis" />
                                    </div>
                                </div>
                                <div className="form-actions">
                                    <Button variant="primary" icon={ICON.execute} disabled={!form.reference || !form.dateValeur} loading={pending === `lot-${lot.id}`} onClick={() => executer(lot.id)}>Exécuter le lot</Button>
                                </div>
                            </div>
                        )}
                    </SectionCard>
                );
            })}
        </main>
    );
}
