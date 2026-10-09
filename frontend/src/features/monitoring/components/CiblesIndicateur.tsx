import { FormEvent, useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, FormField, ICON, SectionCard, useToast, type Column } from '../../../components/ui';
import { errorsOf, percent } from '../../../utils/format';

type Point = { periode: string; libelle: string; cible: number | null; realise: number | null; taux: number | null };
type Periode = { id: number; code: string; label: string; status: string };

/**
 * Cibles d’un indicateur par période de suivi : consultation pour tous,
 * fixation réservée au référentiel S&E (le serveur contrôle le rôle).
 */
export default function CiblesIndicateur({ indicatorId, peutCibler, unite }: { indicatorId: number | string; peutCibler: boolean; unite?: string | null }) {
    const toast = useToast();
    const [points, setPoints] = useState<Point[] | null>(null);
    const [periodes, setPeriodes] = useState<Periode[]>([]);
    const [form, setForm] = useState({ periode: '', valeur: '' });
    const [error, setError] = useState('');
    const [pending, setPending] = useState(false);

    function charger() {
        api.get(`/suivi/indicateurs/${indicatorId}/evolution`).then((response) => setPoints(response.data.data ?? [])).catch((caught) => setError(errorsOf(caught)));
    }

    useEffect(() => {
        charger();
        if (peutCibler) {
            api.get('/suivi/periodes').then((response) => setPeriodes(response.data.data ?? [])).catch(() => setPeriodes([]));
        }
    }, [indicatorId, peutCibler]);

    async function enregistrer(event: FormEvent) {
        event.preventDefault();
        setPending(true);
        setError('');
        try {
            await api.post(`/suivi/indicateurs/${indicatorId}/cibles`, { monitoring_period_id: Number(form.periode), value: Number(form.valeur.replace(',', '.')) });
            toast.success('Cible enregistrée.');
            setForm({ periode: '', valeur: '' });
            charger();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    const suffixe = unite ? ` ${unite}` : '';
    const colonnes: Column<Point>[] = [
        { key: 'periode', header: 'Période', render: (row) => row.libelle || row.periode },
        { key: 'cible', header: 'Cible', align: 'right', className: 'num', render: (row) => (row.cible === null ? '—' : `${row.cible}${suffixe}`) },
        { key: 'realise', header: 'Réalisé', align: 'right', className: 'num', render: (row) => (row.realise === null ? '—' : `${row.realise}${suffixe}`) },
        { key: 'taux', header: 'Atteinte', align: 'right', className: 'num', render: (row) => (row.taux === null ? '—' : percent(row.taux, 0)) },
    ];

    return (
        <SectionCard title="Cibles par période" icon={ICON.report} subtitle="La cible est l’étalon de la performance ; sa modification est tracée." flush>
            <ErrorMessage error={error} onClose={() => setError('')} />
            <DataTable
                columns={colonnes}
                rows={points ?? []}
                rowKey={(row) => row.periode}
                loading={points === null}
                compact
                empty={<EmptyState icon={ICON.report} title="Aucune cible fixée" compact />}
            />
            {peutCibler && (
                <form className="cluster" style={{ alignItems: 'flex-end', padding: '12px 16px' }} onSubmit={enregistrer}>
                    <FormField label="Période" required style={{ flex: '1 1 220px' }}>
                        <select className="inp" required value={form.periode} onChange={(event) => setForm({ ...form, periode: event.target.value })}>
                            <option value="">Choisir</option>
                            {periodes.map((row) => <option key={row.id} value={row.id}>{row.label}</option>)}
                        </select>
                    </FormField>
                    <FormField label={`Cible${suffixe ? ` (${unite})` : ''}`} required style={{ flex: '0 1 180px' }}>
                        <input className="inp num" required inputMode="decimal" value={form.valeur} onChange={(event) => setForm({ ...form, valeur: event.target.value })} />
                    </FormField>
                    <Button type="submit" variant="primary" icon={ICON.save} loading={pending}>Fixer la cible</Button>
                </form>
            )}
        </SectionCard>
    );
}
