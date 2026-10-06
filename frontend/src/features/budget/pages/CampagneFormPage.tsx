import { FormEvent, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, ErrorMessage, FormField, ICON, MultiCheck, PageHeader, SectionCard, useToast } from '../../../components/ui';
import { errorsOf } from '../../../utils/format';
import { PreparationNav } from '../preparation/shell';

const VIDE = { code: '', label: '', exercice_id: '', description: '', perimetre: '', date_ouverture: '', date_cloture: '', responsable_id: '', version_cadrage: '1', structures: [] as string[] };

export default function CampagneFormPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const toast = useToast();
    const [ref, setRef] = useState<any>(null);
    const [form, setForm] = useState(VIDE);
    const [error, setError] = useState('');
    const [pending, setPending] = useState(false);

    useEffect(() => {
        api.get('/preparation/referentiel').then((response) => setRef(response.data)).catch((caught) => setError(errorsOf(caught)));
        if (id) {
            api.get(`/preparation/campagnes/${id}`).then((response) => {
                const row = response.data.data;
                setForm({
                    code: row.code,
                    label: row.libelle,
                    exercice_id: String(row.exercice_id),
                    description: row.description ?? '',
                    perimetre: row.perimetre ?? '',
                    date_ouverture: row.date_ouverture ?? '',
                    date_cloture: row.date_cloture ?? '',
                    responsable_id: row.responsable_id ? String(row.responsable_id) : '',
                    version_cadrage: String(row.version_cadrage ?? 1),
                    structures: (row.structures ?? []).map((item: any) => String(item.id)),
                });
            }).catch((caught) => setError(errorsOf(caught)));
        }
    }, [id]);

    function set<K extends keyof typeof VIDE>(cle: K, valeur: (typeof VIDE)[K]) {
        setForm((current) => ({ ...current, [cle]: valeur }));
    }

    async function enregistrer(event: FormEvent) {
        event.preventDefault();
        setPending(true);
        setError('');
        const payload = {
            ...form,
            exercice_id: Number(form.exercice_id),
            responsable_id: form.responsable_id ? Number(form.responsable_id) : null,
            version_cadrage: Number(form.version_cadrage || 1),
            structures: form.structures.map(Number),
        };
        try {
            const response = id
                ? await api.patch(`/preparation/campagnes/${id}`, payload)
                : await api.post('/preparation/campagnes', payload);
            toast.success('Campagne enregistrée.');
            navigate(`/preparation/campagnes/${id ?? response.data.data.id}`);
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    return (
        <main className="app-content">
            <PageHeader title={id ? 'Modifier la campagne' : 'Nouvelle campagne'} subtitle="La devise reste le franc CFA, en montants entiers." actions={<Button to="/preparation/campagnes" icon={ICON.back}>Annuler</Button>} />
            <PreparationNav />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <form onSubmit={enregistrer}>
                <SectionCard title="Identité" icon={ICON.budget}>
                    <div className="form-grid">
                        <FormField label="Code" required><input className="inp" required value={form.code} onChange={(event) => set('code', event.target.value)} /></FormField>
                        <FormField label="Intitulé" required><input className="inp" required value={form.label} onChange={(event) => set('label', event.target.value)} /></FormField>
                        <FormField label="Exercice" required hint="Seuls les exercices en préparation acceptent une campagne.">
                            <select className="inp" required value={form.exercice_id} onChange={(event) => set('exercice_id', event.target.value)}>
                                <option value="">Choisir</option>
                                {(ref?.exercices ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.annee} · {row.statut}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Version de cadrage"><input className="inp" inputMode="numeric" value={form.version_cadrage} onChange={(event) => set('version_cadrage', event.target.value)} /></FormField>
                        <FormField label="Description" className="span-all"><textarea className="inp" value={form.description} onChange={(event) => set('description', event.target.value)} /></FormField>
                        <FormField label="Périmètre"><input className="inp" value={form.perimetre} onChange={(event) => set('perimetre', event.target.value)} /></FormField>
                        <FormField label="Responsable">
                            <select className="inp" value={form.responsable_id} onChange={(event) => set('responsable_id', event.target.value)}>
                                <option value="">Choisir</option>
                                {(ref?.responsables ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.name}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Ouverture"><input className="inp" type="date" value={form.date_ouverture} onChange={(event) => set('date_ouverture', event.target.value)} /></FormField>
                        <FormField label="Clôture"><input className="inp" type="date" value={form.date_cloture} onChange={(event) => set('date_cloture', event.target.value)} /></FormField>
                        <FormField label="Structures participantes" className="span-all" hint="Cochez les structures appelées à saisir leurs besoins.">
                            <MultiCheck
                                options={(ref?.structures ?? []).map((row: any) => ({ value: String(row.id), label: <><span className="strong">{row.sigle}</span> · {row.name}</>, text: `${row.sigle} ${row.name}` }))}
                                value={form.structures}
                                onChange={(structures) => set('structures', structures)}
                                searchPlaceholder="Filtrer les structures…"
                            />
                        </FormField>
                    </div>
                </SectionCard>
                <div className="cluster" style={{ marginTop: 16 }}>
                    <Button variant="primary" icon={ICON.check} type="submit" loading={pending}>Enregistrer</Button>
                    <Button to={id ? `/preparation/campagnes/${id}` : '/preparation/campagnes'}>Annuler</Button>
                </div>
            </form>
        </main>
    );
}
