import { FormEvent, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, EmptyState, ErrorMessage, FileDrop, FormField, ICON, KeyValueList, PageError, PageHeader, PageSkeleton, SectionCard, useToast } from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

export default function GedFichePage() {
    const { id } = useParams();
    const toast = useToast();
    const [fiche, setFiche] = useState<any>(null);
    const [error, setError] = useState('');
    const [motif, setMotif] = useState('');
    const [fichier, setFichier] = useState<File | null>(null);
    const [tag, setTag] = useState('');
    const [apercu, setApercu] = useState('');
    const [apercuMime, setApercuMime] = useState('');

    async function charger() {
        setError('');
        try {
            const response = await api.get(`/ged/${id}`);
            setFiche(response.data.data);
        } catch (caught) {
            setError(errorsOf(caught));
        }
    }

    useEffect(() => {
        charger();
    }, [id]);

    useEffect(() => {
        const mime = fiche?.versions?.find((version: { courante?: boolean; mime?: string }) => version.courante)?.mime ?? '';
        if (!fiche || fiche.document?.scan === 'quarantaine' || !['application/pdf', 'image/png', 'image/jpeg'].includes(mime)) {
            setApercu('');
            setApercuMime('');
            return;
        }
        let url = '';
        let actif = true;
        api.get(`/ged/${id}/fichier`, { responseType: 'blob' }).then((response) => {
            if (!actif) {
                return;
            }
            url = URL.createObjectURL(response.data);
            setApercu(url);
            setApercuMime(mime);
        }).catch(() => {
            if (actif) {
                setApercu('');
            }
        });
        return () => {
            actif = false;
            if (url) {
                URL.revokeObjectURL(url);
            }
        };
    }, [id, fiche?.document?.reference, fiche?.document?.scan]);

    async function versionner(event: FormEvent) {
        event.preventDefault();
        if (!fichier) {
            return;
        }
        const form = new FormData();
        form.append('fichier', fichier);
        form.append('motif', motif);
        try {
            await api.post(`/ged/${id}/versions`, form);
            toast.success('Nouvelle version enregistrée.');
            setMotif('');
            setFichier(null);
            charger();
        } catch (caught) {
            toast.error(errorsOf(caught));
        }
    }

    async function decider(decision: string) {
        try {
            await api.post(`/ged/${id}/decision`, { decision, motif: motif || null });
            toast.success('Décision enregistrée.');
            charger();
        } catch (caught) {
            toast.error(errorsOf(caught));
        }
    }

    async function telecharger(version?: number) {
        try {
            const response = await api.get(`/ged/${id}/fichier`, { params: version ? { version } : {}, responseType: 'blob' });
            const url = URL.createObjectURL(response.data);
            const lien = document.createElement('a');
            lien.href = url;
            lien.download = fiche?.document?.reference || 'document';
            lien.click();
            URL.revokeObjectURL(url);
        } catch (caught) {
            toast.error(errorsOf(caught));
        }
    }

    async function etiqueter(event: FormEvent) {
        event.preventDefault();
        try {
            await api.post(`/ged/${id}/tags`, { label: tag });
            toast.success('Tag enregistré.');
            setTag('');
        } catch (caught) {
            toast.error(errorsOf(caught));
        }
    }

    if (error && !fiche) {
        return <main className="app-content"><PageError message={error} /></main>;
    }
    if (!fiche) {
        return <main className="app-content"><PageSkeleton /></main>;
    }

    const document = fiche.document;

    return (
        <main className="app-content">
            <PageHeader
                title={document.titre}
                eyebrow={document.reference}
                subtitle={`${document.statut} · ${document.confidentialite}${document.gele ? ' · gelé' : ''}`}
                back={{ to: '/ged', label: 'Retour à la GED' }}
                actions={<Button icon={ICON.download} onClick={() => telecharger()} disabled={document.scan === 'quarantaine'}>Télécharger</Button>}
            />
            {document.scan === 'quarantaine' && <ErrorMessage error="Ce fichier est en quarantaine. Le téléchargement est bloqué." />}
            {apercu && apercuMime === 'application/pdf' && <iframe title="Aperçu du document" src={apercu} style={{ width: '100%', height: 480, border: 0 }} />}
            {apercu && apercuMime !== 'application/pdf' && <img src={apercu} alt="Aperçu du document" style={{ maxWidth: '100%' }} />}
            {!apercu && document.scan !== 'quarantaine' && <p className="subtle">Aperçu non disponible pour ce format. Le fichier reste téléchargeable.</p>}
            <div className="layout-aside is-wide">
                <div className="stack">
                    <SectionCard title="Versions" icon={ICON.history}>
                        {fiche.versions.length === 0 ? <EmptyState compact title="Aucune version" /> : (
                            <ul className="stack-sm">
                                {fiche.versions.map((version: any) => (
                                    <li key={version.id}>
                                        v{version.numero} · {version.nom} · {new Date(version.le).toLocaleString('fr-FR')}
                                        {version.courante ? ' · courante' : ''}
                                        {version.signee ? ' · signée' : ''}
                                        {' · '}{version.scan}
                                        <div className="mono subtle">{version.empreinte}</div>
                                        {!version.courante && version.scan !== 'quarantaine' && (
                                            <Button size="sm" icon={ICON.download} onClick={() => telecharger(version.id)}>Cette version</Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <form className="stack" onSubmit={versionner}>
                            <FormField label="Motif de la nouvelle version" required>
                                <input className="inp" value={motif} onChange={(event) => setMotif(event.target.value)} required />
                            </FormField>
                            <FormField label="Fichier de la nouvelle version" required>
                                <FileDrop file={fichier} onFile={setFichier} hint="PDF, image ou document, 10 Mo maximum." />
                            </FormField>
                            <div className="form-actions">
                                <Button type="submit" variant="primary" icon={ICON.upload} disabled={!fichier}>Enregistrer la version</Button>
                            </div>
                        </form>
                    </SectionCard>
                    <SectionCard title="Journal" icon={ICON.history}>
                        {fiche.historique.length === 0 ? <EmptyState compact title="Aucun événement">Aucune action n’a encore été journalisée sur ce document.</EmptyState> : (
                            <ul>
                                {fiche.historique.map((event: any) => (
                                    <li key={`${event.action}-${event.created_at}`}>{new Date(event.created_at).toLocaleString('fr-FR')} · {event.action}{event.motif ? ` · ${event.motif}` : ''}</li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                </div>
                <aside className="stack">
                    <SectionCard title="Métadonnées" icon={ICON.document}>
                        <KeyValueList items={[
                            { label: 'Origine', value: document.origine },
                            { label: 'Exercice', value: document.exercice ?? '—' },
                            { label: 'Empreinte', value: document.empreinte ?? '—' },
                            { label: 'Doublon visible', value: fiche.doublon_visible ? fiche.doublon_visible.reference : 'Aucun' },
                        ]} />
                    </SectionCard>
                    <SectionCard title="Liaisons" icon={ICON.commitment}>
                        {fiche.liens.length === 0 ? <p className="muted">Aucune liaison.</p> : (
                            <ul>{fiche.liens.map((lien: any) => <li key={`${lien.type}-${lien.id}`}>{lien.type} #{lien.id} · {lien.relation}</li>)}</ul>
                        )}
                    </SectionCard>
                    <SectionCard title="Tag" icon={ICON.edit}>
                        <form className="stack" onSubmit={etiqueter}>
                            <input className="inp" value={tag} onChange={(event) => setTag(event.target.value)} maxLength={64} placeholder="Libellé" />
                            <Button type="submit" disabled={tag.trim() === ''}>Ajouter</Button>
                        </form>
                    </SectionCard>
                    <SectionCard title="Décision" icon={ICON.validate}>
                        <div className="form-actions">
                            <Button onClick={() => decider('valider')}>Valider</Button>
                            <Button onClick={() => decider('rejeter')}>Rejeter</Button>
                            <Button onClick={() => decider('archiver')}>Archiver</Button>
                            <Button onClick={() => decider('geler')}>Geler</Button>
                            <Button onClick={() => decider('restaurer')}>Restaurer</Button>
                        </div>
                    </SectionCard>
                </aside>
            </div>
        </main>
    );
}
