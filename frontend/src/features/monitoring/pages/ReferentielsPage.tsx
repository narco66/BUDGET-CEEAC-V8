import { faCalculator, faListUl, faScaleBalanced } from '@fortawesome/free-solid-svg-icons';
import { FormEvent, useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import {
    Alert,
    Badge,
    Button,
    DataTable,
    EmptyState,
    ErrorMessage,
    FormField,
    ICON,
    InputGroup,
    PageHeader,
    SectionCard,
    useToast,
} from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

type Ligne = { id: number; code: string; label: string; active: boolean; weight?: number };

export default function ReferentielsPage() {
    const toast = useToast();
    const [causes, setCauses] = useState<Ligne[]>([]);
    const [criteres, setCriteres] = useState<Ligne[]>([]);
    const [score, setScore] = useState<Ligne[]>([]);
    const [error, setError] = useState('');
    const [pending, setPending] = useState<string | null>(null);

    function charger() {
        api.get('/suivi/referentiels/cause').then((response) => setCauses(response.data.data));
        api.get('/suivi/referentiels/critere').then((response) => setCriteres(response.data.data));
        api.get('/suivi/referentiels/score').then((response) => setScore(response.data.data.filter((row: Ligne) => row.active)));
    }

    useEffect(() => { charger(); }, []);

    async function ajouter(kind: string, event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const formulaire = event.currentTarget;
        const form = new FormData(formulaire);
        setError('');
        setPending(kind);
        try {
            await api.post(`/suivi/referentiels/${kind}`, { code: form.get('code'), label: form.get('label') });
            formulaire.reset();
            toast.success('Référentiel mis à jour.');
            charger();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function basculer(row: Ligne) {
        try {
            await api.patch(`/suivi/referentiels/${row.id}`, { active: !row.active });
            toast.success(row.active ? 'Entrée désactivée.' : 'Entrée activée.');
            charger();
        } catch (caught) {
            toast.error(errorsOf(caught));
        }
    }

    async function enregistrerScore(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError('');
        setPending('score');
        try {
            await api.put('/suivi/referentiels/score', {
                composantes: score.map((row) => ({ code: row.code, label: row.label, weight: Number(row.weight) })),
            });
            toast.success('Formule du score enregistrée.');
            charger();
        } catch (caught: any) {
            const details = caught?.response?.data?.errors;
            setError(details ? Object.values(details).flat().join(' ') : 'Enregistrement refusé.');
        } finally {
            setPending(null);
        }
    }

    const total = score.reduce((sum, row) => sum + Number(row.weight || 0), 0);

    return (
        <main className="app-content">
            <PageHeader
                title="Référentiels du suivi"
                subtitle="Causes d’écart, critères d’évaluation et poids du score. La modification est réservée au directeur du budget et à l’administrateur fonctionnel."
            />
            <ErrorMessage error={error} onClose={() => setError('')} />

            <div className="grid-halves">
                <Liste titre="Causes d’écart" icon={faListUl} lignes={causes} onToggle={basculer} pending={pending === 'cause'} onAdd={(event) => ajouter('cause', event)} />
                <Liste titre="Critères d’évaluation" icon={faScaleBalanced} lignes={criteres} onToggle={basculer} pending={pending === 'critere'} onAdd={(event) => ajouter('critere', event)} />
            </div>

            <SectionCard
                title="Score composite"
                icon={faCalculator}
                subtitle="Score = somme (taux × poids / 100). Les poids actifs doivent totaliser 100."
                tag={<Badge tone={total === 100 ? 'success' : 'warning'} icon={total === 100 ? ICON.success : ICON.warning} size="sm">Total {total}</Badge>}
            >
                <form onSubmit={enregistrerScore} className="stack">
                    {score.length === 0 ? <EmptyState compact icon={faCalculator} title="Aucune composante active" /> : (
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 16 }}>
                            {score.map((row) => (
                                <FormField key={row.id} label={row.label}>
                                    <InputGroup unit="%">
                                        <input className="inp num align-right" type="number" min="0" max="100" step="0.01" value={row.weight ?? 0} onChange={(event) => setScore(score.map((item) => item.id === row.id ? { ...item, weight: Number(event.target.value) } : item))} />
                                    </InputGroup>
                                </FormField>
                            ))}
                        </div>
                    )}
                    {total !== 100 && score.length > 0 && <Alert tone="warning">Les poids totalisent {total} : ils doivent totaliser 100.</Alert>}
                    <div className="form-actions">
                        <Button variant="primary" type="submit" icon={ICON.save} loading={pending === 'score'}>Enregistrer la formule</Button>
                    </div>
                </form>
            </SectionCard>
        </main>
    );
}

function Liste({ titre, icon, lignes, onToggle, onAdd, pending }: { titre: string; icon: any; lignes: Ligne[]; onToggle: (row: Ligne) => void; onAdd: (event: FormEvent<HTMLFormElement>) => void; pending: boolean }) {
    return (
        <SectionCard
            title={titre}
            icon={icon}
            flush
            footer={(
                <form onSubmit={onAdd} className="cluster" style={{ width: '100%', alignItems: 'flex-end' }}>
                    <input className="inp inp-sm mono" name="code" placeholder="Code" aria-label="Code" required style={{ width: 120 }} />
                    <input className="inp inp-sm" name="label" placeholder="Libellé" aria-label="Libellé" required style={{ flex: '1 1 180px', width: 'auto' }} />
                    <Button size="sm" variant="primary" type="submit" icon={ICON.create} loading={pending}>Ajouter</Button>
                </form>
            )}
        >
            <DataTable
                compact
                columns={[
                    { key: 'code', header: 'Code', className: 'mono', render: (row: Ligne) => row.code },
                    { key: 'label', header: 'Libellé', render: (row: Ligne) => row.label },
                    { key: 'active', header: 'État', render: (row: Ligne) => <Badge tone={row.active ? 'success' : 'neutral'} size="sm" dot>{row.active ? 'Actif' : 'Inactif'}</Badge> },
                    { key: 'toggle', header: 'Action', srHeader: true, className: 'cell-actions', render: (row: Ligne) => <Button size="sm" variant={row.active ? 'ghost' : 'secondary'} onClick={() => onToggle(row)}>{row.active ? 'Désactiver' : 'Activer'}</Button> },
                ]}
                rows={lignes}
                rowKey={(row) => row.id}
                empty={<EmptyState compact icon={icon} title="Aucune entrée" />}
            />
        </SectionCard>
    );
}
