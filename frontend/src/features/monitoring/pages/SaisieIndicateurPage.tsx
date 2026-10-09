import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import CiblesIndicateur from '../components/CiblesIndicateur';
import { Alert, Button, ErrorMessage, ICON, PageError, PageHeader, PageSkeleton, useDialogs, useToast } from '../../../components/ui';
import { Card, CardHead, Icon, PerformancePill, Pill, dayMonth, errorMessage, fmt, pct } from '../components/se';

/** Écran 4 de docs/maquette-SE : saisie et validation d’une valeur d’indicateur. */
const STATUT: Record<string, { label: string; tone: 'gris' | 'bleu' | 'ambre' | 'vert' | 'rouge' | 'orange' }> = {
    brouillon: { label: 'Brouillon', tone: 'gris' },
    a_corriger: { label: 'À corriger', tone: 'orange' },
    soumis: { label: 'Soumis', tone: 'bleu' },
    valide_responsable: { label: 'Validé responsable', tone: 'bleu' },
    valide: { label: 'Validé', tone: 'vert' },
    consolide: { label: 'Consolidé', tone: 'vert' },
    rejete: { label: 'Rejeté', tone: 'rouge' },
};
const TYPE: Record<string, string> = { produit: 'Indicateur de produit', resultat: 'Indicateur de résultat', activite: 'Indicateur d’activité', effet: 'Indicateur d’effet', impact: 'Indicateur d’impact', intrant: 'Indicateur d’intrant' };
const ETAPE: Record<string, { bg: string; border: string; puce: string; texte: string }> = {
    fait: { bg: 'var(--green-50)', border: 'var(--success-border)', puce: 'var(--success-solid)', texte: 'var(--color-surface)' },
    en_cours: { bg: 'var(--warning-bg)', border: 'var(--warning-border)', puce: 'var(--warning-solid)', texte: 'var(--color-surface)' },
    a_venir: { bg: 'var(--color-surface)', border: 'var(--slate-200)', puce: 'var(--color-surface)', texte: 'var(--color-text-subtle)' },
    rejete: { bg: 'var(--danger-bg)', border: 'var(--danger-border)', puce: 'var(--danger-solid)', texte: 'var(--color-surface)' },
};

export default function SaisieIndicateurPage() {
    const { id } = useParams();
    const [periode, setPeriode] = useState<string>('');
    const [ctx, setCtx] = useState<any>(null);
    const [form, setForm] = useState({ numerator: '', denominator: '', value: '', comment: '', source: '', justification: '' });
    const [apercu, setApercu] = useState<any>(null);
    const [fichier, setFichier] = useState<File | null>(null);
    const toast = useToast();
    const { prompt } = useDialogs();
    const setMessage = (text: string) => toast.success(text);
    const [erreur, setErreur] = useState('');
    const [personnes, setPersonnes] = useState<Array<{ id: number; name: string }>>([]);
    const [responsableId, setResponsableId] = useState('');
    const [designation, setDesignation] = useState(false);
    const input = useRef<HTMLInputElement>(null);

    function charger() {
        api.get(`/suivi/indicateurs/${id}/saisie`, { params: periode ? { periode } : {} }).then((response) => {
            const data = response.data.data;
            setCtx(data);
            const mesure = data.mesure;
            setForm({
                numerator: mesure?.numerateur ?? '',
                denominator: mesure?.denominateur ?? '',
                value: mesure?.valeur ?? '',
                comment: mesure?.commentaire ?? '',
                source: mesure?.source ?? '',
                justification: mesure?.justification ?? '',
            });
            if (!periode && data.periode) setPeriode(String(data.periode.id));
        }).catch((error) => setErreur(errorMessage(error, 'Indicateur inaccessible.')));
    }

    useEffect(charger, [id, periode]);

    useEffect(() => {
        const activiteId = ctx?.indicateur?.activite?.id;
        if (!activiteId) {
            return;
        }
        api.get(`/suivi/activites/${activiteId}/responsables`)
            .then((response) => setPersonnes(response.data.data))
            .catch(() => setPersonnes([]));
    }, [ctx?.indicateur?.activite?.id]);

    useEffect(() => {
        if (ctx?.indicateur?.responsable_id) {
            setResponsableId(String(ctx.indicateur.responsable_id));
        }
    }, [ctx?.indicateur?.responsable_id]);

    const ratio = ctx?.indicateur.ratio;
    useEffect(() => {
        if (!ctx?.periode) return;
        const payload: Record<string, unknown> = { monitoring_period_id: ctx.periode.id };
        if (ratio) {
            if (form.numerator === '' || form.denominator === '') { setApercu(null); return; }
            payload.numerator = Number(form.numerator);
            payload.denominator = Number(form.denominator);
        } else {
            if (form.value === '') { setApercu(null); return; }
            payload.value = Number(form.value);
        }
        const timer = window.setTimeout(() => {
            api.post(`/suivi/indicateurs/${id}/apercu`, payload).then((response) => setApercu(response.data.data)).catch(() => setApercu(null));
        }, 250);

        return () => window.clearTimeout(timer);
    }, [ctx?.periode?.id, ratio, form.numerator, form.denominator, form.value]);

    function donnees() {
        const base = { comment: form.comment || null, source: form.source || null, justification: form.justification || null };

        return ratio
            ? { ...base, numerator: Number(form.numerator), denominator: Number(form.denominator) }
            : { ...base, value: Number(form.value) };
    }

    async function enregistrer(): Promise<number | null> {
        setErreur('');
        try {
            let mesureId = ctx.mesure?.id ?? null;
            if (mesureId) {
                await api.patch(`/suivi/mesures/${mesureId}`, donnees());
            } else {
                const response = await api.post('/suivi/mesures', { indicator_id: Number(id), monitoring_period_id: ctx.periode.id, ...donnees() });
                mesureId = response.data.data.id;
            }
            if (fichier && mesureId) {
                const upload = new FormData();
                upload.append('type', 'mesure');
                upload.append('id', String(mesureId));
                upload.append('category', 'preuve');
                upload.append('fichier', fichier);
                await api.post('/suivi/preuves', upload);
                setFichier(null);
            }

            return mesureId;
        } catch (error) {
            setErreur(errorMessage(error));

            return null;
        }
    }

    async function brouillon() {
        if (await enregistrer()) { setMessage('Brouillon enregistré.'); charger(); }
    }

    async function soumettre() {
        const mesureId = await enregistrer();
        if (!mesureId) return;
        try {
            await api.post(`/suivi/mesures/${mesureId}/soumettre`);
            setMessage('Valeur soumise pour validation.');
        } catch (error) {
            setErreur(errorMessage(error));
        }
        charger();
    }

    async function decider(action: 'valider' | 'corriger' | 'rejeter' | 'consolider') {
        let motif: string | null = null;
        if (action === 'corriger' || action === 'rejeter') {
            const values = await prompt({
                title: action === 'rejeter' ? 'Rejeter la valeur' : 'Retourner en correction',
                description: `${ctx.indicateur.code} · ${ctx.periode?.label ?? ''}`,
                confirmLabel: action === 'rejeter' ? 'Rejeter' : 'Retourner',
                tone: action === 'rejeter' ? 'danger' : 'warning',
                fields: [{ name: 'motif', label: action === 'rejeter' ? 'Motif du rejet' : 'Motif du retour en correction', type: 'textarea', required: true }],
            });
            if (!values) return;
            motif = values.motif;
        }
        try {
            await api.post(`/suivi/mesures/${ctx.mesure.id}/${action}`, motif ? { motif } : {});
            setMessage('Décision enregistrée.');
            charger();
        } catch (error) {
            setErreur(errorMessage(error));
        }
    }

    if (!ctx) {
        return erreur ? <PageError message={erreur} onRetry={charger} /> : <PageSkeleton variant="detail" />;
    }

    const indicateur = ctx.indicateur;
    const mesure = ctx.mesure;
    const statut = STATUT[mesure?.statut ?? 'brouillon'] ?? STATUT.brouillon;
    const editable = !mesure || mesure.actions.modifier;
    const unite = indicateur.unite === '%' ? ' %' : indicateur.unite ? ` ${indicateur.unite}` : '';
    const jours = ctx.periode?.jours;
    const controlesOk = ctx.controles.filter((row: any) => row.ok).length;
    const precedent = apercu?.precedent ?? ctx.precedent;
    const set = (cle: keyof typeof form) => (event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setForm({ ...form, [cle]: event.target.value });

    return (
        <main className="app-content">
            <PageHeader
                back={indicateur.activite ? { to: `/suivi/activites/${indicateur.activite.id}`, label: 'Fiche activité' } : { to: '/suivi/saisie', label: 'Saisies' }}
                eyebrow={(
                    <>
                        <span className="mono strong">{indicateur.code}</span>
                        <Pill tone={statut.tone} size="md" dot>{statut.label}{mesure?.version > 1 ? ` · v${mesure.version}` : ''}</Pill>
                        {ctx.periode?.echeance && (
                            <Pill tone={jours !== null && jours <= 3 ? 'ambre' : 'gris'} icon="clock">
                                Échéance de saisie {dayMonth(ctx.periode.echeance)}{jours !== null ? (jours >= 0 ? ` · J-${jours}` : ` · dépassée de ${-jours} j`) : ''}
                            </Pill>
                        )}
                    </>
                )}
                title={indicateur.libelle}
                subtitle={<>
                    {[TYPE[indicateur.type] ?? indicateur.type, indicateur.frequence, indicateur.sens ? `sens ${indicateur.sens}` : null,
                        indicateur.reference !== null ? `référence ${fmt(indicateur.reference)}${unite}${indicateur.reference_annee ? ` (${indicateur.reference_annee})` : ''}` : null].filter(Boolean).join(' · ')}
                    {indicateur.cible !== null && <> · cible <b>{fmt(indicateur.cible)}{unite}</b></>}
                </>}
                actions={editable ? <>
                    <Button icon={ICON.save} onClick={brouillon}>Enregistrer le brouillon</Button>
                    <Button variant="primary" icon={ICON.submit} onClick={soumettre}>Soumettre pour validation</Button>
                </> : undefined}
            />
            <ErrorMessage error={erreur} onClose={() => setErreur('')} />
            {indicateur.peut_designer && indicateur.activite && (
                <form className="card" style={{ padding: 16, display: 'flex', gap: 12, alignItems: 'end', flexWrap: 'wrap' }} onSubmit={(event) => {
                    event.preventDefault();
                    setDesignation(true);
                    setErreur('');
                    api.patch(`/suivi/indicateurs/${id}/responsable`, { responsible_user_id: Number(responsableId) })
                        .then(() => { toast.success('Responsable de l’indicateur désigné.'); charger(); })
                        .catch((error) => setErreur(errorMessage(error)))
                        .finally(() => setDesignation(false));
                }}>
                    <label className="field" style={{ flex: '1 1 280px', margin: 0 }}>
                        <span className="lbl">Responsable de l’indicateur</span>
                        <select className="inp" value={responsableId} onChange={(event) => setResponsableId(event.target.value)} required>
                            <option value="">Choisir une personne</option>
                            {(indicateur.responsable_id && !personnes.some((personne) => personne.id === indicateur.responsable_id)
                                ? [{ id: indicateur.responsable_id, name: indicateur.responsable ?? 'Responsable actuel' }, ...personnes]
                                : personnes
                            ).map((personne) => <option key={personne.id} value={personne.id}>{personne.name}</option>)}
                        </select>
                    </label>
                    <Button variant="primary" type="submit" icon={ICON.users} loading={designation}>Désigner</Button>
                </form>
            )}
            {mesure?.motif_retour && <Alert tone="warning" title="Valeur retournée en correction">{mesure.motif_retour}</Alert>}
            <CiblesIndicateur indicatorId={indicateur.id ?? id} peutCibler={Boolean(indicateur.peut_cibler)} unite={indicateur.unite} />

            <section className="card" aria-label="Circuit de validation" style={{ padding: '14px 16px', display: 'flex', flexDirection: 'column', gap: 10 }}>
                <ol style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    {ctx.circuit.map((etape: any, index: number) => {
                        const style = ETAPE[etape.etat] ?? ETAPE.a_venir;

                        return [
                            index > 0 && <li key={`f${etape.numero}`} aria-hidden="true" style={{ alignSelf: 'center', color: 'var(--slate-400)' }}><Icon name="chevron" /></li>,
                            <li key={etape.numero} style={{ flex: '1 1 180px', display: 'flex', flexDirection: 'column', gap: 4, padding: '12px 14px', borderRadius: 8, border: `1px solid ${style.border}`, background: style.bg }}>
                                <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                    <span className="mono" style={{ width: 20, height: 20, borderRadius: 99, background: style.puce, border: `2px solid ${etape.etat === 'a_venir' ? 'var(--slate-300)' : style.puce}`, boxSizing: 'border-box', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 10, fontWeight: 700, color: style.texte }}>{etape.numero}</span>
                                    <span className="lbl" style={{ fontSize: 10 }}>{etape.etape}</span>
                                </span>
                                <span style={{ fontSize: 12.5, fontWeight: 600 }}>{etape.acteur} · {etape.qualite}</span>
                                {etape.le && <span style={{ fontSize: 11, color: 'var(--color-text-subtle)' }}>le {dayMonth(etape.le)}</span>}
                            </li>,
                        ];
                    })}
                </ol>
                <span style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, color: 'var(--slate-500)' }}><Icon name="lock" size={13} />Séparation des fonctions : la personne qui saisit ne valide pas sa propre réalisation.</span>
                {mesure && (mesure.actions.valider || mesure.actions.retourner || mesure.actions.rejeter || mesure.actions.consolider) && (
                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                        {mesure.actions.valider && <Button variant="primary" icon={ICON.validate} onClick={() => decider('valider')}>Valider</Button>}
                        {mesure.actions.retourner && <Button variant="warning" icon={ICON.return} onClick={() => decider('corriger')}>Retourner en correction</Button>}
                        {mesure.actions.rejeter && <Button variant="danger-outline" icon={ICON.reject} onClick={() => decider('rejeter')}>Rejeter</Button>}
                        {mesure.actions.consolider && <Button variant="brand" icon={ICON.archive} onClick={() => decider('consolider')}>Consolider</Button>}
                    </div>
                )}
            </section>

            <div className="se-grid-side">
                <section className="card" aria-label="Valeur de la période" style={{ padding: '16px 18px', display: 'flex', flexDirection: 'column', gap: 14 }}>
                    <h2 style={{ margin: 0, fontSize: 15, fontWeight: 700, color: 'var(--navy-900)' }}>Valeur de la période</h2>
                    <div className="se-grid-4" style={{ alignItems: 'end' }}>
                        <label className="field"><span className="lbl">Période</span>
                            <select className="inp" value={periode} onChange={(event) => setPeriode(event.target.value)}>
                                {ctx.periodes.map((row: any) => <option key={row.id} value={row.id}>{row.label}</option>)}
                            </select>
                        </label>
                        {ratio ? (
                            <>
                                <label className="field"><span className="lbl">{indicateur.numerateur} (numérateur)</span><input className="inp mono" type="number" min="0" step="any" value={form.numerator} onChange={set('numerator')} disabled={!editable} /></label>
                                <label className="field"><span className="lbl">{indicateur.denominateur} (dénominateur)</span><input className="inp mono" type="number" min="0" step="any" value={form.denominator} onChange={set('denominator')} disabled={!editable} /></label>
                            </>
                        ) : (
                            <label className="field" style={{ gridColumn: 'span 2' }}><span className="lbl">Valeur réalisée{unite ? ` (${unite.trim()})` : ''}</span><input className="inp mono" type="number" step="any" value={form.value} onChange={set('value')} disabled={!editable} /></label>
                        )}
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 5 }}>
                            <span className="lbl">Valeur calculée</span>
                            <div style={{ height: 38, padding: '0 12px', borderRadius: 6, background: 'var(--navy-900)', color: 'var(--color-on-solid)', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 6 }}>
                                <span className="mono" style={{ fontSize: 17, fontWeight: 700 }}>{apercu?.valeur !== null && apercu?.valeur !== undefined ? `${fmt(apercu.valeur)}${unite}` : mesure?.valeur !== null && mesure?.valeur !== undefined ? `${fmt(mesure.valeur)}${unite}` : '—'}</span>
                                {apercu?.formule && <span style={{ fontSize: 10.5, color: 'var(--navy-200)', whiteSpace: 'nowrap' }}>{apercu.formule}</span>}
                            </div>
                        </div>
                    </div>
                    <div className="se-grid-3" style={{ padding: '12px 14px', borderRadius: 8, background: 'var(--slate-50)' }}>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                            <span style={{ fontSize: 11, color: 'var(--slate-500)' }}>Taux de réalisation</span>
                            <span className="mono" style={{ fontSize: 15, fontWeight: 700 }}>{apercu?.taux !== null && apercu?.taux !== undefined ? `${pct(apercu.taux, 0)} de la cible` : '—'}</span>
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                            <span style={{ fontSize: 11, color: 'var(--slate-500)' }}>Évolution / {precedent?.periode ?? 'période précédente'}</span>
                            <span className="mono" style={{ fontSize: 15, fontWeight: 700 }}>{apercu?.evolution !== null && apercu?.evolution !== undefined ? `${apercu.evolution > 0 ? '+' : ''}${fmt(apercu.evolution)} ${indicateur.unite === '%' ? 'points' : indicateur.unite ?? ''}` : '—'}</span>
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 2, alignItems: 'flex-start' }}>
                            <span style={{ fontSize: 11, color: 'var(--slate-500)' }}>Statut calculé</span>
                            {apercu?.statut ? <PerformancePill status={apercu.statut} /> : <span className="mono">—</span>}
                        </div>
                    </div>
                    <label className="field"><span className="lbl">Commentaire</span>
                        <textarea className="inp" value={form.comment} onChange={set('comment')} disabled={!editable} style={{ height: 80, padding: '10px 12px', lineHeight: 1.5, resize: 'none' }} />
                    </label>
                    <div className="se-grid-2">
                        <label className="field"><span className="lbl">Source de la donnée</span><input className="inp" value={form.source} onChange={set('source')} disabled={!editable} /></label>
                        <label className="field"><span className="lbl">Justification de l’écart à la cible</span><input className="inp" value={form.justification} onChange={set('justification')} disabled={!editable} maxLength={255} /></label>
                    </div>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                        <span className="lbl">Preuve · versée automatiquement à la GED</span>
                        {(mesure?.preuves ?? []).map((preuve: any) => (
                            <div key={preuve.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 14px', borderRadius: 8, border: '1px solid var(--slate-200)' }}>
                                <span style={{ width: 34, height: 34, borderRadius: 6, background: 'var(--sky-bg)', color: 'var(--sky-fg)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}><Icon name="file" /></span>
                                <div style={{ display: 'flex', flexDirection: 'column', gap: 1, minWidth: 0 }}>
                                    <span style={{ fontSize: 12.5, fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis' }}>{preuve.nom}</span>
                                    <span style={{ fontSize: 11, color: 'var(--color-text-subtle)' }}>{preuve.taille !== null ? `${Math.max(1, Math.round(preuve.taille / 1024))} Ko · ` : ''}{ctx.classement_ged} · <span className="mono">{String(preuve.empreinte).slice(0, 10)}</span></span>
                                </div>
                            </div>
                        ))}
                        {editable && (
                            <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 14px', borderRadius: 8, border: '1.5px dashed var(--slate-300)' }}>
                                <span style={{ width: 34, height: 34, borderRadius: 6, background: 'var(--sky-bg)', color: 'var(--sky-fg)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}><Icon name="upload" /></span>
                                <div style={{ flexGrow: 1, display: 'flex', flexDirection: 'column', gap: 1, minWidth: 0 }}>
                                    <span style={{ fontSize: 12.5, fontWeight: 600 }}>{fichier ? fichier.name : 'Aucune nouvelle pièce'}</span>
                                    <span style={{ fontSize: 11, color: 'var(--color-text-subtle)' }}>{fichier ? `${Math.max(1, Math.round(fichier.size / 1024))} Ko · ` : ''}{ctx.classement_ged}</span>
                                </div>
                                <input ref={input} type="file" hidden onChange={(event) => setFichier(event.target.files?.[0] ?? null)} />
                                <Button size="sm" icon={ICON.upload} onClick={() => input.current?.click()}>{fichier ? 'Remplacer' : 'Ajouter'}</Button>
                            </div>
                        )}
                    </div>
                </section>

                <aside style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                    <Card label="Contrôles de qualité">
                        <CardHead title="Contrôles de qualité" tag={<Pill tone={controlesOk === ctx.controles.length ? 'vert' : 'ambre'}>{controlesOk} / {ctx.controles.length}</Pill>} />
                        <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 8 }}>
                            {ctx.controles.map((row: any) => (
                                <li key={row.code} style={{ display: 'flex', gap: 9, fontSize: 12.5 }}>
                                    <Icon name={row.ok ? 'checkCircle' : 'alert'} color={row.ok ? 'var(--success-solid)' : 'var(--warning-solid)'} />
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 1 }}>
                                        <span style={{ color: 'var(--slate-800)' }}>{row.libelle}</span>
                                        {row.detail && <span style={{ fontSize: 11, color: 'var(--color-text-subtle)' }}>{row.detail}</span>}
                                    </div>
                                </li>
                            ))}
                            {!mesure && <li style={{ fontSize: 11.5, color: 'var(--color-text-subtle)' }}>Les contrôles portent sur la valeur enregistrée : enregistrez le brouillon pour les actualiser.</li>}
                        </ul>
                    </Card>
                    <Card label="Historique des valeurs" padded={false}>
                        <CardHead padded title="Historique des valeurs" tag={indicateur.cible !== null ? <Pill tone="marine">cible {fmt(indicateur.cible)}{unite}</Pill> : undefined} />
                        <div className="se-scroll">
                            <table className="tbl">
                                <thead><tr><th>Période</th><th className="r">Valeur</th><th>Statut</th><th>Validée le</th><th>Snapshot</th></tr></thead>
                                <tbody>
                                    {ctx.historique.length === 0 && <tr><td colSpan={5} className="muted">Aucune valeur saisie.</td></tr>}
                                    {ctx.historique.map((row: any) => (
                                        <tr key={row.id}>
                                            <td>{row.periode}</td>
                                            <td className="r mono" style={{ fontWeight: 700 }}>{row.valeur !== null ? `${fmt(row.valeur)}${unite}` : '—'}</td>
                                            <td><Pill tone={(STATUT[row.statut] ?? STATUT.brouillon).tone}>{(STATUT[row.statut] ?? STATUT.brouillon).label}</Pill></td>
                                            <td className="mono">{dayMonth(row.valide_le)}</td>
                                            <td style={{ fontSize: 12, color: 'var(--slate-500)' }}>{row.snapshot ? `Snapshot ${dayMonth(row.snapshot)}` : '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p style={{ margin: '0 18px 16px', fontSize: 11.5, color: 'var(--color-text-subtle)', lineHeight: 1.5 }}>
                            Une valeur validée ne peut être ni supprimée ni modifiée silencieusement : toute correction conserve l’ancienne valeur, l’auteur, la date, la justification et la preuve. Les situations figées ne sont jamais écrasées.
                        </p>
                    </Card>
                </aside>
            </div>
        </main>
    );
}
