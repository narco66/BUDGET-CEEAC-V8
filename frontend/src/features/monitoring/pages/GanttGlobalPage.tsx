import { useEffect, useMemo, useRef, useState, type KeyboardEvent, type MouseEvent, type ReactNode } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    Alert,
    Badge,
    Button,
    EmptyState,
    ErrorMessage,
    FilterBar,
    FilterSelect,
    ICON,
    PageHeader,
    PageSkeleton,
    SectionCard,
    Segmented,
    StatStrip,
    StripCell,
} from '../../../components/ui';
import { errorMessage, fullDate, pct } from '../components/se';

/** Gantt global de l’exécution du PAP : toutes les activités suivies, sur une période. */

type Barre = { gauche: number; largeur: number; coupe_debut: boolean; coupe_fin: boolean } | null;

type Tache = {
    id: number;
    code: string | null;
    libelle: string;
    debut: string;
    fin: string;
    avancement: number;
    retard: boolean;
    barre: Barre;
    barre_reelle: Barre;
};

type Activite = {
    id: number;
    code: string | null;
    libelle: string;
    pilier: string | null;
    axe: string | null;
    statut: string;
    unite: { id: number; libelle: string } | null;
    responsable: string | null;
    source: 'activite' | 'taches' | 'pap';
    periode_pap: string | null;
    debut: string;
    fin: string;
    debut_reel: string | null;
    fin_reelle: string | null;
    fin_projetee: string | null;
    avancement: number;
    attendu: number | null;
    etat: Etat;
    retard_jours: number;
    barre: Barre;
    barre_reelle: Barre;
    barre_projetee: Barre;
    jalons: { libelle: string; prevu_le: string; franchi: boolean; position: number }[];
    taches: Tache[];
    taches_sans_date: number;
};

type Etat = 'a_venir' | 'en_cours' | 'en_retard' | 'termine' | 'indicative';

type Portefeuille = {
    periode: { annee: number; code: string; libelle: string; debut: string; fin: string; echelle: 'mois' | 'semaines' };
    jours: number;
    aujourdhui: number | null;
    colonnes: { libelle: string; debut: string; gauche: number; largeur: number }[];
    periodes: { libelle: string; gauche: number; largeur: number }[];
    activites: Activite[];
    non_planifiees: { id: number; code: string | null; libelle: string; unite: { libelle: string } | null; pilier: string | null; taches_sans_date: number }[];
    synthese: {
        activites: number; dans_la_periode: number; planifiees: number; indicatives: number; non_planifiees: number;
        en_retard: number; en_cours: number; terminees: number; a_venir: number; affichees: number; avancement_moyen: number | null;
    };
    filtres: { unites: { id: number; libelle: string }[]; piliers: string[]; annees: number[] };
};

const ETATS: Record<Etat, { libelle: string; ton: 'neutral' | 'info' | 'orange' | 'success'; couleur: string }> = {
    a_venir: { libelle: 'À venir', ton: 'neutral', couleur: 'var(--slate-500)' },
    en_cours: { libelle: 'En cours', ton: 'info', couleur: 'var(--navy-600)' },
    en_retard: { libelle: 'En retard', ton: 'orange', couleur: 'var(--orange-solid)' },
    termine: { libelle: 'Terminée', ton: 'success', couleur: 'var(--green-600)' },
    indicative: { libelle: 'Période indicative', ton: 'neutral', couleur: 'var(--slate-400)' },
};

const MOIS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
const REGROUPEMENTS = [
    { value: 'unite', label: 'Par structure' },
    { value: 'pilier', label: 'Par pilier' },
    { value: 'aucun', label: 'Sans regroupement' },
];
/** Largeur minimale d’une journée : un trimestre en semaines reste lisible, un exercice tient à l’écran. */
const PX_PAR_JOUR = { mois: 3, semaines: 14 };

type Survol = { x: number; y: number; contenu: ReactNode } | null;

export default function GanttGlobalPage() {
    const navigate = useNavigate();
    const [annee, setAnnee] = useState(() => new Date().getFullYear());
    const [periode, setPeriode] = useState('annee');
    const [unite, setUnite] = useState('');
    const [pilier, setPilier] = useState('');
    const [etat, setEtat] = useState('');
    const [recherche, setRecherche] = useState('');
    const [regroupement, setRegroupement] = useState(() => lire('gantt-global.regroupement', 'unite'));
    const [donnees, setDonnees] = useState<Portefeuille | null>(null);
    const [chargement, setChargement] = useState(true);
    const [erreur, setErreur] = useState('');
    const [ouvertes, setOuvertes] = useState<Set<number>>(new Set());
    const [replies, setReplies] = useState<Set<string>>(new Set());
    const [survol, setSurvol] = useState<Survol>(null);
    const derniereDemande = useRef(0);
    const defilement = useRef<HTMLDivElement>(null);

    // La recherche part après une courte pause de frappe.
    const [rechercheEnvoyee, setRechercheEnvoyee] = useState('');
    useEffect(() => {
        const minuterie = window.setTimeout(() => setRechercheEnvoyee(recherche.trim()), 350);
        return () => window.clearTimeout(minuterie);
    }, [recherche]);

    useEffect(() => {
        const numero = ++derniereDemande.current;
        setChargement(true);
        api.get('/suivi/gantt/portefeuille', {
            params: { annee, periode, unite_id: unite || undefined, pilier: pilier || undefined, etat: etat || undefined, recherche: rechercheEnvoyee || undefined },
        })
            .then((response) => {
                if (numero !== derniereDemande.current) return;
                setDonnees(response.data.data);
                setErreur('');
            })
            .catch((error) => { if (numero === derniereDemande.current) setErreur(errorMessage(error, 'Gantt inaccessible.')); })
            .finally(() => { if (numero === derniereDemande.current) setChargement(false); });
    }, [annee, periode, unite, pilier, etat, rechercheEnvoyee]);

    useEffect(() => ecrire('gantt-global.regroupement', regroupement), [regroupement]);

    // À chaque période, la frise se recentre sur aujourd’hui, sinon repart du début.
    useEffect(() => {
        const element = defilement.current;
        if (!element || !donnees) return;
        if (donnees.aujourdhui === null) {
            element.scrollLeft = 0;
            return;
        }
        const frise = element.scrollWidth - GAUCHE;
        element.scrollLeft = Math.max(0, (donnees.aujourdhui / 100) * frise - (element.clientWidth - GAUCHE) / 2);
    }, [donnees?.periode.code, donnees?.periode.annee]);

    const groupes = useMemo(() => {
        if (!donnees) return [];
        if (regroupement === 'aucun') return [{ cle: 'tout', libelle: 'Toutes les activités', activites: donnees.activites }];
        const parCle = new Map<string, Activite[]>();
        for (const activite of donnees.activites) {
            const cle = (regroupement === 'pilier' ? activite.pilier : activite.unite?.libelle) || (regroupement === 'pilier' ? 'Pilier non renseigné' : 'Structure non renseignée');
            parCle.set(cle, [...(parCle.get(cle) ?? []), activite]);
        }

        return [...parCle.entries()]
            .sort(([a], [b]) => a.localeCompare(b, 'fr'))
            .map(([cle, activites]) => ({ cle, libelle: cle, activites }));
    }, [donnees, regroupement]);

    function basculer<T>(ensemble: Set<T>, valeur: T, modifier: (suivant: Set<T>) => void) {
        const suivant = new Set(ensemble);
        if (suivant.has(valeur)) suivant.delete(valeur); else suivant.add(valeur);
        modifier(suivant);
    }

    function montrer(event: MouseEvent, contenu: ReactNode) {
        const x = Math.min(event.clientX + 14, window.innerWidth - 360);
        const y = event.clientY + 16 > window.innerHeight - 180 ? event.clientY - 150 : event.clientY + 16;
        setSurvol({ x, y, contenu });
    }

    function clavier(event: KeyboardEvent, action: () => void) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            action();
        }
    }

    const reinitialiser = unite || pilier || etat || recherche
        ? () => { setUnite(''); setPilier(''); setEtat(''); setRecherche(''); }
        : null;

    const selecteurPeriode = (
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <select className="inp" aria-label="Exercice" value={annee} onChange={(event) => setAnnee(Number(event.target.value))} style={{ width: 'auto' }}>
                {(donnees?.filtres.annees ?? [annee]).map((valeur) => <option key={valeur} value={valeur}>Exercice {valeur}</option>)}
            </select>
            <select className="inp" aria-label="Période" value={periode} onChange={(event) => setPeriode(event.target.value)} style={{ width: 'auto' }}>
                <option value="annee">Année entière</option>
                <optgroup label="Semestres">
                    <option value="S1">1er semestre</option>
                    <option value="S2">2e semestre</option>
                </optgroup>
                <optgroup label="Trimestres">
                    {[1, 2, 3, 4].map((numero) => <option key={numero} value={`T${numero}`}>Trimestre {numero}</option>)}
                </optgroup>
                <optgroup label="Mois">
                    {MOIS.map((mois, index) => <option key={mois} value={`M${String(index + 1).padStart(2, '0')}`}>{mois}</option>)}
                </optgroup>
            </select>
            <Button icon={ICON.print} onClick={() => window.print()}>Imprimer</Button>
        </div>
    );

    if (!donnees) {
        return (
            <main className="app-content">
                <PageHeader back={{ to: '/suivi', label: 'Tableau de bord S&E' }} eyebrow="Suivi & évaluation" title="Gantt global du PAP" subtitle="Exécution de toutes les activités, par période." actions={selecteurPeriode} />
                {erreur ? <ErrorMessage error={erreur} title="Gantt inaccessible" /> : <PageSkeleton variant="detail" />}
            </main>
        );
    }

    const synthese = donnees.synthese;
    const largeurFrise = Math.max(900, Math.round(donnees.jours * PX_PAR_JOUR[donnees.periode.echelle]));

    function ligneActivite(activite: Activite) {
        const etatActivite = ETATS[activite.etat];
        const ouverte = ouvertes.has(activite.id);
        const indicative = activite.source === 'pap';
        const ouvrir = () => navigate(`/suivi/activites/${activite.id}/gantt`);
        const infobulle = (
            <>
                <strong>{activite.code ? `${activite.code} · ` : ''}{activite.libelle}</strong><br />
                {indicative
                    ? <>Période indicative du PAP : « {activite.periode_pap} »<br />Aucune date ni tâche datée : à planifier.</>
                    : <>Prévu du {fullDate(activite.debut)} au {fullDate(activite.fin)}{activite.source === 'taches' ? ' (d’après les tâches)' : ''}</>}
                {activite.debut_reel && <><br />Démarrée le {fullDate(activite.debut_reel)}{activite.fin_reelle ? ` · achevée le ${fullDate(activite.fin_reelle)}` : ''}</>}
                {activite.fin_projetee && activite.retard_jours > 0 && <><br /><span className="text-warning">Fin projetée le {fullDate(activite.fin_projetee)} (+{activite.retard_jours} j)</span></>}
                <br />Avancement {pct(activite.avancement, 0)}{activite.attendu !== null ? ` · attendu à date ${pct(activite.attendu, 0)}` : ''}
                <br />{etatActivite.libelle}{activite.unite ? ` · ${activite.unite.libelle}` : ''}
            </>
        );

        return (
            <div key={activite.id} className="ggl-bloc">
                <div className="ggl-ligne">
                    <div className="ggl-gauche" role="button" tabIndex={0} onClick={ouvrir} onKeyDown={(event) => clavier(event, ouvrir)} title="Ouvrir le Gantt détaillé de l’activité">
                        {activite.taches.length > 0
                            ? (
                                <button type="button" className="ggl-depli" aria-expanded={ouverte} aria-label={ouverte ? 'Masquer les tâches' : 'Afficher les tâches'} onClick={(event) => { event.stopPropagation(); basculer(ouvertes, activite.id, setOuvertes); }}>
                                    {ouverte ? '▾' : '▸'}
                                </button>
                            )
                            : <span className="ggl-depli" aria-hidden="true" />}
                        <span className="gantt-libelle">
                            <strong>{activite.libelle}</strong>
                            <small>{[activite.code, activite.responsable, activite.taches.length > 0 ? `${activite.taches.length} tâche(s) datée(s)` : null].filter(Boolean).join(' · ')}</small>
                        </span>
                        <span className="gantt-etat">
                            <span className="ggl-avancement">{pct(activite.avancement, 0)}</span>
                            {activite.etat === 'indicative' ? <Badge size="sm" tone="neutral" outline>Indicative</Badge> : <Badge size="sm" tone={etatActivite.ton}>{etatActivite.libelle}{activite.retard_jours > 0 ? ` +${activite.retard_jours} j` : ''}</Badge>}
                        </span>
                    </div>
                    <div className="ggl-frise" onMouseMove={(event) => montrer(event, infobulle)} onMouseLeave={() => setSurvol(null)} onClick={ouvrir}>
                        {activite.barre && (
                            <span
                                className={['ggl-barre', indicative ? 'is-indicative' : '', activite.barre.coupe_debut ? 'coupe-debut' : '', activite.barre.coupe_fin ? 'coupe-fin' : ''].filter(Boolean).join(' ')}
                                style={{ left: `${activite.barre.gauche}%`, width: `${activite.barre.largeur}%`, ['--barre' as string]: indicative ? 'var(--slate-400)' : etatActivite.couleur }}
                            >
                                {!indicative && <span className="gantt-progression" style={{ width: `${Math.min(100, activite.avancement)}%` }} />}
                            </span>
                        )}
                        {activite.barre_projetee && <span className="ggl-barre is-projetee" style={{ left: `${activite.barre_projetee.gauche}%`, width: `${activite.barre_projetee.largeur}%`, ['--barre' as string]: 'var(--orange-solid)' }} />}
                        {activite.barre_reelle && <span className="ggl-reel" style={{ left: `${activite.barre_reelle.gauche}%`, width: `${activite.barre_reelle.largeur}%` }} />}
                        {activite.jalons.map((jalon) => (
                            <span key={`${jalon.libelle}-${jalon.prevu_le}`} className={`gantt-losange ggl-jalon${jalon.franchi ? ' is-franchi' : ''}`} style={{ left: `${jalon.position}%` }} title={`${jalon.libelle} · ${fullDate(jalon.prevu_le)}${jalon.franchi ? ' · franchi' : ''}`} />
                        ))}
                    </div>
                </div>
                {ouverte && activite.taches.map((tache) => (
                    <div key={tache.id} className="ggl-ligne is-tache">
                        <div className="ggl-gauche">
                            <span className="ggl-depli" aria-hidden="true" />
                            <span className="gantt-libelle">
                                <strong>{tache.libelle}</strong>
                                <small>{fullDate(tache.debut)} → {fullDate(tache.fin)}{tache.code ? ` · ${tache.code}` : ''}</small>
                            </span>
                            <span className="gantt-etat">
                                <span className="ggl-avancement">{pct(tache.avancement, 0)}</span>
                                {tache.retard && <span className="gantt-retard">Retard</span>}
                            </span>
                        </div>
                        <div className="ggl-frise" onMouseMove={(event) => montrer(event, <><strong>{tache.libelle}</strong><br />Prévu du {fullDate(tache.debut)} au {fullDate(tache.fin)}<br />Avancement {pct(tache.avancement, 0)}{tache.retard ? <><br /><span className="text-warning">En retard</span></> : null}</>)} onMouseLeave={() => setSurvol(null)}>
                            {tache.barre && (
                                <span className="ggl-barre is-tache" style={{ left: `${tache.barre.gauche}%`, width: `${tache.barre.largeur}%`, ['--barre' as string]: tache.retard ? 'var(--orange-solid)' : tache.avancement >= 100 ? 'var(--green-600)' : 'var(--navy-600)' }}>
                                    <span className="gantt-progression" style={{ width: `${Math.min(100, tache.avancement)}%` }} />
                                </span>
                            )}
                            {tache.barre_reelle && <span className="ggl-reel" style={{ left: `${tache.barre_reelle.gauche}%`, width: `${tache.barre_reelle.largeur}%` }} />}
                        </div>
                    </div>
                ))}
            </div>
        );
    }

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/suivi', label: 'Tableau de bord S&E' }}
                eyebrow="Suivi & évaluation"
                title="Gantt global du PAP"
                subtitle={`${donnees.periode.libelle} · du ${fullDate(donnees.periode.debut)} au ${fullDate(donnees.periode.fin)}`}
                actions={selecteurPeriode}
            />

            <StatStrip label="Synthèse de la période">
                <StripCell label="Activités sur la période" value={`${synthese.dans_la_periode} / ${synthese.activites}`} hint="Activités suivies visibles" mono />
                <StripCell label="Planifiées" value={synthese.planifiees} hint={`${synthese.indicatives} sur période indicative`} mono />
                <StripCell label="En retard" value={synthese.en_retard} valueColor={synthese.en_retard > 0 ? 'var(--orange-fg)' : undefined} hint="Échéance dépassée ou fin projetée" mono active={etat === 'en_retard'} onClick={() => setEtat(etat === 'en_retard' ? '' : 'en_retard')} />
                <StripCell label="Terminées" value={synthese.terminees} mono active={etat === 'termine'} onClick={() => setEtat(etat === 'termine' ? '' : 'termine')} />
                <StripCell label="Avancement moyen" value={synthese.avancement_moyen === null ? '—' : pct(synthese.avancement_moyen, 1)} hint="Réalisations validées" mono />
                <StripCell label="Non planifiées" value={synthese.non_planifiees} hint="Ni dates ni période" mono valueColor={synthese.non_planifiees > 0 ? 'var(--warning-fg)' : undefined} />
            </StatStrip>

            {synthese.indicatives > 0 && (
                <Alert tone="info" title={`${synthese.indicatives} activité(s) sans planning détaillé`} actions={<Button size="sm" to="/planification" icon={ICON.planning}>Ouvrir la planification</Button>}>
                    Elles n’ont ni dates ni tâches datées : leur barre hachurée reprend la période saisie dans le PAP (souvent l’exercice entier).
                    Leur retard ne peut donc pas être mesuré. Planifiez leurs tâches dans le cadre GAR pour un suivi précis.
                </Alert>
            )}

            {erreur && <ErrorMessage error={erreur} title="Gantt inaccessible" />}

            <SectionCard
                title="Frise d’exécution"
                icon={ICON.gantt}
                subtitle="Cliquez sur une activité pour ouvrir son Gantt détaillé ; ▸ déplie ses tâches."
                flush
                actions={<Segmented label="Regroupement" value={regroupement} onChange={setRegroupement} items={REGROUPEMENTS} />}
            >
                <FilterBar onReset={reinitialiser}>
                    <input className="inp" type="search" placeholder="Rechercher une activité ou un code…" value={recherche} onChange={(event) => setRecherche(event.target.value)} aria-label="Rechercher" style={{ maxWidth: 280 }} />
                    <FilterSelect label="Structure" value={unite} onChange={setUnite} allLabel="Toutes" options={donnees.filtres.unites.map((row) => ({ value: String(row.id), label: row.libelle }))} />
                    <FilterSelect label="Pilier" value={pilier} onChange={setPilier} options={donnees.filtres.piliers.map((row) => ({ value: row, label: row }))} />
                    <FilterSelect label="État" value={etat} onChange={setEtat} options={Object.entries(ETATS).map(([value, row]) => ({ value, label: row.libelle }))} />
                    <span className="text-muted" style={{ fontSize: 'var(--text-xs)' }}>{synthese.affichees} affichée(s)</span>
                    {chargement && <span className="text-muted" style={{ fontSize: 'var(--text-xs)' }}>Mise à jour…</span>}
                </FilterBar>

                {donnees.activites.length === 0
                    ? <EmptyState icon={ICON.calendar} title="Aucune activité sur cette période">Changez de période ou de filtres.</EmptyState>
                    : (
                        <div className="gantt ggl" ref={defilement} style={{ ['--frise' as string]: `${largeurFrise}px` }}>
                            <div className="ggl-grille">
                                <div className="ggl-entete">
                                    <div className="gantt-coin ggl-coin"><span>Activité</span><span>Avanc. · état</span></div>
                                    <div className="ggl-echelle">
                                        {donnees.periodes.map((periodeHaute) => (
                                            <span key={periodeHaute.libelle} className="gantt-periode" style={{ left: `${periodeHaute.gauche}%`, width: `${periodeHaute.largeur}%` }}><em style={{ left: GAUCHE + 8 }}>{periodeHaute.libelle}</em></span>
                                        ))}
                                        {donnees.colonnes.map((colonne) => (
                                            <span key={colonne.debut} className="gantt-colonne" style={{ left: `${colonne.gauche}%`, width: `${colonne.largeur}%` }}>{colonne.libelle}</span>
                                        ))}
                                    </div>
                                </div>
                                <div className="ggl-corps">
                                    <div className="ggl-reperes" aria-hidden="true">
                                        {donnees.colonnes.map((colonne) => <span key={colonne.debut} className="gantt-repere" style={{ left: `${colonne.gauche}%` }} />)}
                                        {donnees.aujourdhui !== null && <span className="gantt-aujourdhui" style={{ left: `${donnees.aujourdhui}%` }}><span>Aujourd’hui</span></span>}
                                    </div>
                                    {groupes.map((groupe) => {
                                        const replie = replies.has(groupe.cle);
                                        const moyenne = groupe.activites.reduce((total, row) => total + row.avancement, 0) / groupe.activites.length;
                                        const retards = groupe.activites.filter((row) => row.etat === 'en_retard').length;

                                        return (
                                            <section key={groupe.cle} aria-label={groupe.libelle}>
                                                {regroupement !== 'aucun' && (
                                                    <div className="ggl-ligne is-groupe">
                                                        <button type="button" className="ggl-gauche" aria-expanded={!replie} onClick={() => basculer(replies, groupe.cle, setReplies)}>
                                                            <span className="ggl-depli" aria-hidden="true">{replie ? '▸' : '▾'}</span>
                                                            <span className="gantt-libelle"><strong>{groupe.libelle}</strong><small>{groupe.activites.length} activité(s){retards > 0 ? ` · ${retards} en retard` : ''}</small></span>
                                                            <span className="ggl-avancement">{pct(moyenne, 0)}</span>
                                                        </button>
                                                        <div className="ggl-frise" />
                                                    </div>
                                                )}
                                                {!replie && groupe.activites.map(ligneActivite)}
                                            </section>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>
                    )}

                <div className="gantt-legende" aria-label="Légende">
                    <span><i style={{ border: '1.5px solid var(--navy-600)', background: 'linear-gradient(90deg, var(--navy-600) 50%, color-mix(in srgb, var(--navy-600) 22%, white) 50%)' }} />Prévu, rempli à hauteur de l’avancement</span>
                    <span><i style={{ height: 4, background: 'var(--navy-950)' }} />Réel (démarrage → fin ou aujourd’hui)</span>
                    <span><i style={{ border: '1.5px solid var(--orange-solid)', background: 'repeating-linear-gradient(45deg, var(--orange-solid) 0 3px, white 3px 7px)' }} />Glissement projeté</span>
                    <span><i style={{ border: '1.5px dashed var(--slate-400)', background: 'repeating-linear-gradient(45deg, var(--slate-200) 0 3px, white 3px 7px)' }} />Période indicative du PAP</span>
                    <span><span className="gantt-losange is-franchi" style={{ width: 10, height: 10 }} />Jalon franchi</span>
                    {Object.values(ETATS).filter((row) => row.libelle !== 'Période indicative').map((row) => <span key={row.libelle}><i style={{ width: 12, background: row.couleur }} />{row.libelle}</span>)}
                </div>
            </SectionCard>

            {donnees.non_planifiees.length > 0 && (
                <SectionCard title="Activités non planifiées" icon={ICON.warning} subtitle="Ni dates, ni tâches datées, ni période lisible dans le PAP : elles ne peuvent pas figurer sur la frise.">
                    <ul className="ggl-liste">
                        {donnees.non_planifiees.map((row) => (
                            <li key={row.id}>
                                <Link to={`/suivi/activites/${row.id}/gantt`}>{row.code ? `${row.code} · ` : ''}{row.libelle}</Link>
                                <small>{[row.unite?.libelle, row.taches_sans_date > 0 ? `${row.taches_sans_date} tâche(s) sans date` : null].filter(Boolean).join(' · ')}</small>
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            )}

            {survol && <div className="gantt-infobulle" style={{ left: survol.x, top: survol.y }} role="tooltip">{survol.contenu}</div>}
        </main>
    );
}

const GAUCHE = 380;

function lire(cle: string, defaut: string): string {
    try {
        return window.localStorage.getItem(cle) ?? defaut;
    } catch {
        return defaut;
    }
}

function ecrire(cle: string, valeur: string) {
    try {
        window.localStorage.setItem(cle, valeur);
    } catch {
        // Préférence d’affichage seulement : rien à faire si le stockage est indisponible.
    }
}
