import { FormEvent, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/httpClient';
import { Button, EmptyState, ErrorMessage, FileDrop, FormField, ICON, SectionCard, useToast } from '../../components/ui';
import { errorsOf } from '../../utils/format';

type Piece = {
    id: number;
    reference: string;
    titre: string;
    statut: string;
    confidentialite: string;
    scan: string;
    version?: number;
    heritage?: string;
    empreinte?: string;
};

type Dossier = {
    peut_deposer: boolean;
    completude: { taux: number | null; message: string; manques: string[] };
    documents: Piece[];
};

export default function GedDossier({ type, entityId }: { type: string; entityId: number }) {
    const toast = useToast();
    const [dossier, setDossier] = useState<Dossier | null>(null);
    const [error, setError] = useState('');
    const [titre, setTitre] = useState('');
    const [fichier, setFichier] = useState<File | null>(null);
    const [pending, setPending] = useState(false);

    async function charger() {
        setError('');
        try {
            const response = await api.get('/ged/dossier', { params: { type, id: entityId } });
            setDossier(response.data.data);
        } catch (caught) {
            setError(errorsOf(caught));
        }
    }

    useEffect(() => {
        charger();
    }, [type, entityId]);

    async function deposer(event: FormEvent) {
        event.preventDefault();
        if (!fichier) {
            return;
        }
        setPending(true);
        const form = new FormData();
        form.append('fichier', fichier);
        form.append('title', titre);
        form.append('entity_type', type);
        form.append('entity_id', String(entityId));
        try {
            const response = await api.post('/ged', form);
            toast.success(response.data.data.scan === 'quarantaine'
                ? 'Pièce déposée et mise en quarantaine.'
                : `Pièce ${response.data.data.reference} déposée.`);
            setTitre('');
            setFichier(null);
            await charger();
        } catch (caught) {
            toast.error(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    return (
        <SectionCard title="Dossier documentaire" icon={ICON.attachment} subtitle="Pièces propres et pièces héritées de la chaîne, sans duplication de fichier.">
            <ErrorMessage error={error} />
            {!dossier && !error && <p className="muted">Chargement du dossier documentaire…</p>}
            {dossier && (
                <div className="stack">
                    <p>
                        {dossier.completude.taux === null
                            ? dossier.completude.message
                            : `Complétude ${dossier.completude.taux} %. ${dossier.completude.message}`}
                    </p>
                    {dossier.completude.manques.length > 0 && (
                        <ul>
                            {dossier.completude.manques.map((manque) => <li key={manque}>{manque}</li>)}
                        </ul>
                    )}
                    {dossier.peut_deposer && (
                        <form className="stack" onSubmit={deposer}>
                            <div className="form-grid" style={{ ['--cols' as string]: 2 }}>
                                <FormField label="Titre" required>
                                    <input className="inp" value={titre} onChange={(event) => setTitre(event.target.value)} required maxLength={180} />
                                </FormField>
                                <FormField label="Fichier" required>
                                    <FileDrop file={fichier} onFile={setFichier} hint="10 Mo maximum." />
                                </FormField>
                            </div>
                            <div className="form-actions">
                                <Button variant="primary" type="submit" icon={ICON.upload} loading={pending} disabled={!fichier || titre.trim() === ''}>Déposer</Button>
                            </div>
                        </form>
                    )}
                    {dossier.documents.length === 0 ? (
                        <EmptyState compact title="Aucune pièce dans la GED">Les pièces déposées ou générées apparaîtront ici.</EmptyState>
                    ) : (
                        <ul className="stack-sm">
                            {dossier.documents.map((piece) => (
                                <li key={piece.id}>
                                    <Link to={`/ged/${piece.id}`}>{piece.reference}</Link>
                                    {' · '}{piece.titre}
                                    {' · '}{piece.heritage === 'herite' ? 'héritée' : 'propre'}
                                    {' · '}{piece.statut}
                                    {piece.scan === 'quarantaine' ? ' · quarantaine' : ''}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </SectionCard>
    );
}
