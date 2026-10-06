import { FormField } from '../../../components/ui';

export const PROCEDURES: Record<string, string> = {
    appel_offres: 'Appel d’offres',
    consultation: 'Consultation',
    gre_a_gre: 'Gré à gré',
    contrat: 'Contrat',
};

export type MarcheSaisie = { objet: string; montant: string; procedure: string; tiers_id: string };

export const MARCHE_VIDE: MarcheSaisie = { objet: '', montant: '', procedure: 'consultation', tiers_id: '' };

/** Champs communs à la création et à la modification d’un marché. */
export default function MarcheForm({ value, onChange, tiers }: {
    value: MarcheSaisie;
    onChange: (value: MarcheSaisie) => void;
    tiers: { id: number; code: string; raison_sociale: string }[];
}) {
    const set = (champ: keyof MarcheSaisie) => (event: { target: { value: string } }) => onChange({ ...value, [champ]: event.target.value });

    return (
        <>
            <FormField label="Procédure" required>
                <select className="inp" value={value.procedure} onChange={set('procedure')}>
                    {Object.entries(PROCEDURES).map(([code, label]) => <option key={code} value={code}>{label}</option>)}
                </select>
            </FormField>
            <FormField label="Objet" required className="span-all">
                <input className="inp" required maxLength={255} value={value.objet} onChange={set('objet')} />
            </FormField>
            <FormField label="Montant (FCFA)" required>
                <input className="inp num" required inputMode="numeric" pattern="[0-9]*" value={value.montant} onChange={(event) => onChange({ ...value, montant: event.target.value.replace(/\D/g, '') })} />
            </FormField>
            <FormField label="Titulaire" hint="Seuls les tiers actifs sont proposés.">
                <select className="inp" value={value.tiers_id} onChange={set('tiers_id')}>
                    <option value="">Non désigné</option>
                    {tiers.map((row) => <option key={row.id} value={row.id}>{row.code} · {row.raison_sociale}</option>)}
                </select>
            </FormField>
        </>
    );
}
