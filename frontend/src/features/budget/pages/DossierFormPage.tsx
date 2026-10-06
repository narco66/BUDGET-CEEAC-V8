import { FormEvent, useEffect, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, ErrorMessage, FormField, ICON, PageHeader, SectionCard, useToast } from '../../../components/ui';
import { errorsOf } from '../../../utils/format';
import { PreparationNav } from '../preparation/shell';

export default function DossierFormPage() {
    const { id } = useParams();
    const [params] = useSearchParams();
    const navigate = useNavigate();
    const toast = useToast();
    const [ref, setRef] = useState<any>(null);
    const [error, setError] = useState('');
    const [pending, setPending] = useState(false);
    const [form, setForm] = useState({
        campaign_id: params.get('campagne') ?? '',
        organization_unit_id: '',
        titre: '',
        description: '',
        justification: '',
        responsable_id: '',
        observations: '',
    });

    useEffect(() => {
        api.get('/preparation/referentiel').then((response) => setRef(response.data)).catch((caught) => setError(errorsOf(caught)));
        if (id) {
            api.get(`/preparation/dossiers/${id}`).then((response) => {
                const row = response.data.data;
                setForm({
                    campaign_id: String(row.campaign_id),
                    organization_unit_id: String(row.organization_unit_id),
                    titre: row.titre,
                    description: row.description ?? '',
                    justification: row.justification ?? '',
                    responsable_id: row.responsable_id ? String(row.responsable_id) : '',
                    observations: row.observations ?? '',
                });
            }).catch((caught) => setError(errorsOf(caught)));
        }
    }, [id]);

    async function enregistrer(event: FormEvent) {
        event.preventDefault();
        setPending(true);
        setError('');
        try {
            if (id) {
                await api.patch(`/preparation/dossiers/${id}`, {
                    titre: form.titre,
                    description: form.description,
                    justification: form.justification,
                    responsable_id: form.responsable_id ? Number(form.responsable_id) : null,
                    observations: form.observations,
                });
                toast.success('Proposition enregistrée.');
                navigate(`/preparation/dossiers/${id}`);
            } else {
                const response = await api.post('/preparation/dossiers', {
                    ...form,
                    campaign_id: Number(form.campaign_id),
                    organization_unit_id: Number(form.organization_unit_id),
                    responsable_id: form.responsable_id ? Number(form.responsable_id) : null,
                });
                toast.success('Proposition créée.');
                navigate(`/preparation/dossiers/${response.data.data.id}`);
            }
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    return (
        <main className="app-content">
            <PageHeader title={id ? 'Modifier la proposition' : 'Nouvelle proposition'} subtitle="Le brouillon reste modifiable jusqu’à la soumission." actions={<Button to="/preparation/dossiers" icon={ICON.back}>Annuler</Button>} />
            <PreparationNav />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <form onSubmit={enregistrer}>
                <SectionCard title="Dossier" icon={ICON.tasks}>
                    <div className="form-grid">
                        {!id && (
                            <>
                                <FormField label="Campagne" required>
                                    <select className="inp" required value={form.campaign_id} onChange={(event) => setForm({ ...form, campaign_id: event.target.value })}>
                                        <option value="">Choisir</option>
                                        {(ref?.campagnes ?? []).filter((row: any) => row.statut === 'ouverte').map((row: any) => <option key={row.id} value={row.id}>{row.code} · {row.label}</option>)}
                                    </select>
                                </FormField>
                                <FormField label="Structure porteuse" required>
                                    <select className="inp" required value={form.organization_unit_id} onChange={(event) => setForm({ ...form, organization_unit_id: event.target.value })}>
                                        <option value="">Choisir</option>
                                        {(ref?.structures ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.sigle} · {row.name}</option>)}
                                    </select>
                                </FormField>
                            </>
                        )}
                        <FormField label="Titre" required className="span-all"><input className="inp" required value={form.titre} onChange={(event) => setForm({ ...form, titre: event.target.value })} /></FormField>
                        <FormField label="Description" className="span-all"><textarea className="inp" value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} /></FormField>
                        <FormField label="Justification" className="span-all"><textarea className="inp" value={form.justification} onChange={(event) => setForm({ ...form, justification: event.target.value })} /></FormField>
                        <FormField label="Responsable">
                            <select className="inp" value={form.responsable_id} onChange={(event) => setForm({ ...form, responsable_id: event.target.value })}>
                                <option value="">Choisir</option>
                                {(ref?.responsables ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.name}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Observations" className="span-all"><textarea className="inp" value={form.observations} onChange={(event) => setForm({ ...form, observations: event.target.value })} /></FormField>
                    </div>
                </SectionCard>
                <div className="cluster" style={{ marginTop: 16 }}>
                    <Button variant="primary" icon={ICON.check} type="submit" loading={pending}>Enregistrer</Button>
                    <Button to={id ? `/preparation/dossiers/${id}` : '/preparation/dossiers'}>Annuler</Button>
                </div>
            </form>
        </main>
    );
}
