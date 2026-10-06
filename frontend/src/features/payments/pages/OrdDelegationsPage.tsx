import { faCalculator, faCrown, faGavel, faRoute, faUserClock, faUserTie } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { FormEvent, useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import {
    Alert,
    AmountInput,
    Badge,
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
    StatCard,
    useToast,
} from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';

const EMPTY_DELEGATION = { seuil_max: '', debut: '', fin: '', document: '', type_depense: '' };
const EMPTY_SUPPLEANCE = { titulaire: '', suppleant: '', debut: '', fin: '', fondement: '' };

export default function OrdDelegations() {
    const toast = useToast();
    const [payload, setPayload] = useState<any>(null);
    const [loadError, setLoadError] = useState('');
    const [montant, setMontant] = useState('');
    const [simulating, setSimulating] = useState(false);
    const [delegation, setDelegation] = useState(EMPTY_DELEGATION);
    const [suppleance, setSuppleance] = useState(EMPTY_SUPPLEANCE);
    const [modal, setModal] = useState<'delegation' | 'suppleance' | null>(null);
    const [pending, setPending] = useState(false);
    const [error, setError] = useState('');

    function load(value = montant) {
        return api.get('/ordonnancements/delegations', { params: { montant: Number(value) || 0 } })
            .then((response) => { setPayload(response.data); setLoadError(''); })
            .catch((caught) => setLoadError(errorsOf(caught)));
    }

    useEffect(() => {
        load();
    }, []);

    async function simulate(event: FormEvent) {
        event.preventDefault();
        setSimulating(true);
        await load(montant);
        setSimulating(false);
    }

    async function saveDelegation(event: FormEvent) {
        event.preventDefault();
        setError('');
        setPending(true);
        try {
            await api.post('/ordonnancements/delegations', { ...delegation, seuil_max: Number(delegation.seuil_max) });
            toast.success('Délégation enregistrée. Si sa période couvre aujourd’hui, elle remplace le seuil actif.');
            setDelegation(EMPTY_DELEGATION);
            setModal(null);
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    async function saveSuppleance(event: FormEvent) {
        event.preventDefault();
        setError('');
        setPending(true);
        try {
            await api.post('/ordonnancements/suppleances', suppleance);
            toast.success('Suppléance déclarée.');
            setSuppleance(EMPTY_SUPPLEANCE);
            setModal(null);
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    if (!payload) {
        return loadError ? <PageError message={loadError} onRetry={() => load()} /> : <PageSkeleton />;
    }

    const active = payload?.data?.find((row) => row.active);

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/ordonnancements', label: 'Ordonnancements' }}
                title="Délégations d’ordonnancement"
                subtitle="Référentiel utilisé par le moteur de détermination de l’ordonnateur. Aucun seuil n’est codé en dur."
                actions={(
                    <>
                        {payload.peut_suppleer && <Button icon={faUserClock} onClick={() => { setError(''); setModal('suppleance'); }}>Déclarer une absence</Button>}
                        {payload.peut_parametrer && <Button variant="primary" icon={ICON.create} onClick={() => { setError(''); setModal('delegation'); }}>Nouvelle délégation</Button>}
                    </>
                )}
            />

            <div className="layout-aside is-wide">
                <SectionCard title="Seuil de l’ordonnateur délégué" icon={faGavel} tag={<Badge tone={active ? 'success' : 'neutral'} size="sm" dot>{active ? 'Actif' : 'Aucun seuil actif'}</Badge>}>
                    <div className="grid-halves" style={{ alignItems: 'stretch' }}>
                        <StatCard dark label="Seuil actif" value={payload.seuil_actif ? fcfa(payload.seuil_actif) : '—'} unit={payload.seuil_actif ? 'FCFA' : undefined} hint="Borne incluse (≤) pour le délégué" icon={faGavel} />
                        <KeyValueList items={[
                            { label: 'Date d’effet', value: active?.debut, mono: true },
                            { label: 'Fin de validité', value: active?.fin, mono: true },
                            { label: 'Texte de référence', value: active?.document },
                        ]} />
                    </div>
                </SectionCard>

                <SectionCard title="Moteur de détermination" icon={faRoute}>
                    <p className="muted">Entrée : montant net à ordonnancer, exercice, type de dépense, délégation active, période de validité. Une alerte de cohérence est levée si le signataire enregistré ne correspond pas à cette règle.</p>
                    <ul className="list-rows">
                        <li className="list-row">
                            <span className="card-title-icon" aria-hidden="true"><FontAwesomeIcon icon={faUserTie} /></span>
                            <span className="list-row-main"><span className="list-row-title">Secrétaire Général · Ordonnateur délégué</span><span className="list-row-sub mono">≤ {fcfa(payload.seuil_actif)} FCFA</span></span>
                        </li>
                        <li className="list-row">
                            <span className="card-title-icon" aria-hidden="true"><FontAwesomeIcon icon={faCrown} /></span>
                            <span className="list-row-main"><span className="list-row-title">Président de la Commission · Ordonnateur principal</span><span className="list-row-sub mono">&gt; {fcfa(payload.seuil_actif)} FCFA</span></span>
                        </li>
                    </ul>
                    <form onSubmit={simulate} className="cluster" style={{ alignItems: 'flex-end' }}>
                        <FormField label="Montant net à simuler" style={{ flex: '1 1 200px' }}>
                            <AmountInput value={montant} onChange={setMontant} type="number" min="1" />
                        </FormField>
                        <Button variant="brand" type="submit" icon={faCalculator} disabled={!Number(montant)} loading={simulating}>Simuler</Button>
                    </form>
                    {payload.simulation && (
                        <Alert tone="success" title={payload.simulation.ordonnateur_label}>{payload.simulation.fondement}</Alert>
                    )}
                </SectionCard>
            </div>

            <SectionCard title="Référentiel des délégations" subtitle="Seules les délégations actives et dans leur période sont utilisées." icon={ICON.roles} flush>
                <DataTable
                    columns={[
                        { key: 'delegant', header: 'Délégant', render: (row: any) => row.delegant },
                        { key: 'delegataire', header: 'Délégataire · fonction', render: (row: any) => <div><div>{row.delegataire}</div><div className="cell-sub">{row.fonction}</div></div> },
                        { key: 'seuil', header: 'Seuil', align: 'right', className: 'mono', render: (row: any) => `≤ ${fcfa(row.seuil_max)}` },
                        { key: 'type', header: 'Types de dépenses', render: (row: any) => row.type_depense || '—' },
                        { key: 'periode', header: 'Période', className: 'mono', render: (row: any) => `${row.debut} → ${row.fin}` },
                        { key: 'document', header: 'Justificatif', className: 'mono', render: (row: any) => row.document },
                        { key: 'statut', header: 'Statut', render: (row: any) => <Badge tone={row.active ? 'success' : 'neutral'} icon={row.active ? ICON.success : ICON.cancel}>{row.active ? 'Active' : 'Expirée'}</Badge> },
                    ]}
                    rows={payload.data}
                    rowKey={(row: any) => row.id}
                    minWidth={900}
                    empty={<EmptyState icon={ICON.roles} title="Aucune délégation" compact>Aucune délégation d’ordonnancement n’est enregistrée.</EmptyState>}
                />
            </SectionCard>

            <div className="grid-halves">
                <SectionCard title="Suppléances" icon={faUserClock} flush>
                    <DataTable
                        columns={[
                            { key: 'titulaire', header: 'Titulaire', render: (row: any) => row.titulaire },
                            { key: 'suppleant', header: 'Suppléant', render: (row: any) => row.suppleant },
                            { key: 'periode', header: 'Période', className: 'mono', render: (row: any) => `${row.debut} → ${row.fin}` },
                            { key: 'fondement', header: 'Fondement', render: (row: any) => row.fondement },
                            { key: 'statut', header: 'Statut', render: (row: any) => <Badge tone="neutral" size="sm">{row.statut}</Badge> },
                        ]}
                        rows={payload.suppleances ?? []}
                        rowKey={(row: any) => row.id}
                        empty={<EmptyState icon={faUserClock} title="Aucune suppléance" compact>Le titulaire reste disponible.</EmptyState>}
                    />
                </SectionCard>
                <SectionCard title="Derniers routages" icon={faRoute} flush>
                    <DataTable
                        columns={[
                            { key: 'reference', header: 'Dossier', className: 'mono', render: (row: any) => row.reference },
                            { key: 'montant', header: 'Net', align: 'right', className: 'mono', render: (row: any) => fcfa(row.montant) },
                            { key: 'regle', header: 'Règle', className: 'mono', render: (row: any) => row.regle },
                            { key: 'ordonnateur', header: 'Ordonnateur', render: (row: any) => row.ordonnateur_label },
                            { key: 'coherent', header: 'Cohérence', render: (row: any) => <Badge tone={row.coherent ? 'success' : 'danger'} icon={row.coherent ? ICON.success : ICON.warning} size="sm">{row.coherent ? 'Cohérent' : 'Écart de signataire'}</Badge> },
                        ]}
                        rows={payload.routages ?? []}
                        rowKey={(row: any) => row.reference}
                        empty={<EmptyState icon={faRoute} title="Aucun routage récent" compact />}
                    />
                </SectionCard>
            </div>

            {modal === 'delegation' && (
                <Modal
                    title="Nouvelle délégation"
                    description="La délégation remplace le seuil actif dès que sa période couvre la date du jour."
                    icon={ICON.roles}
                    onClose={() => setModal(null)}
                    footer={(
                        <>
                            <Button onClick={() => setModal(null)}>Annuler</Button>
                            <Button variant="primary" type="submit" form="delegation-form" icon={ICON.save} loading={pending}>Enregistrer la délégation</Button>
                        </>
                    )}
                >
                    <form id="delegation-form" className="form-grid" onSubmit={saveDelegation}>
                        <FormField label="Seuil maximal" required className="span-all">
                            <AmountInput type="number" min="1" value={delegation.seuil_max} onChange={(value) => setDelegation({ ...delegation, seuil_max: value })} required />
                        </FormField>
                        <FormField label="Début" required><input className="inp" type="date" value={delegation.debut} onChange={(event) => setDelegation({ ...delegation, debut: event.target.value })} /></FormField>
                        <FormField label="Fin" required><input className="inp" type="date" value={delegation.fin} onChange={(event) => setDelegation({ ...delegation, fin: event.target.value })} /></FormField>
                        <FormField label="Décision de référence" required className="span-all"><input className="inp" value={delegation.document} onChange={(event) => setDelegation({ ...delegation, document: event.target.value })} /></FormField>
                        <FormField label="Type de dépense" optional className="span-all"><input className="inp" value={delegation.type_depense} onChange={(event) => setDelegation({ ...delegation, type_depense: event.target.value })} /></FormField>
                    </form>
                    <ErrorMessage error={error} title="Enregistrement refusé" />
                </Modal>
            )}

            {modal === 'suppleance' && (
                <Modal
                    title="Déclarer une absence"
                    description="La suppléance désigne l’acteur qui signe pendant l’absence du titulaire."
                    icon={faUserClock}
                    onClose={() => setModal(null)}
                    footer={(
                        <>
                            <Button onClick={() => setModal(null)}>Annuler</Button>
                            <Button variant="primary" type="submit" form="suppleance-form" icon={ICON.save} loading={pending}>Déclarer la suppléance</Button>
                        </>
                    )}
                >
                    <form id="suppleance-form" className="form-grid" onSubmit={saveSuppleance}>
                        <FormField label="Titulaire" required><input className="inp" value={suppleance.titulaire} onChange={(event) => setSuppleance({ ...suppleance, titulaire: event.target.value })} /></FormField>
                        <FormField label="Suppléant" required><input className="inp" value={suppleance.suppleant} onChange={(event) => setSuppleance({ ...suppleance, suppleant: event.target.value })} /></FormField>
                        <FormField label="Début" required><input className="inp" type="date" value={suppleance.debut} onChange={(event) => setSuppleance({ ...suppleance, debut: event.target.value })} /></FormField>
                        <FormField label="Fin" required><input className="inp" type="date" value={suppleance.fin} onChange={(event) => setSuppleance({ ...suppleance, fin: event.target.value })} /></FormField>
                        <FormField label="Acte de suppléance" required className="span-all"><input className="inp" value={suppleance.fondement} onChange={(event) => setSuppleance({ ...suppleance, fondement: event.target.value })} /></FormField>
                    </form>
                    <ErrorMessage error={error} title="Enregistrement refusé" />
                </Modal>
            )}
        </main>
    );
}
