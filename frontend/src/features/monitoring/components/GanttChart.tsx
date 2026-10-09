import { useEffect, useLayoutEffect, useMemo, useRef, useState, type KeyboardEvent, type MouseEvent } from 'react';
import { PerformancePill, dayMonth, fullDate, pct } from './se';

export type Segment = { gauche: number; largeur: number } | null;

export type GanttTache = {
    id: number;
    code: string;
    libelle: string;
    responsable: string | null;
    depend_de: string | null;
    depend_de_id: number | null;
    critique: boolean;
    reference_validee: boolean;
    planifiee: boolean;
    poids: number | null;
    statut: string;
    avancement: number | null;
    initial: Segment;
    prevu: Segment;
    reel: Segment;
    projection: Segment;
    retard_fin: number;
    retard_demarrage: number;
    non_demarree: boolean;
    debut_initial: string | null;
    fin_initiale: string | null;
    debut_courant: string | null;
    fin_courante: string | null;
    debut_reel: string | null;
    fin_reelle: string | null;
    fin_projetee: string | null;
};

export type GanttJalon = {
    id: number;
    libelle: string;
    date: string | null;
    prevu_le: string | null;
    franchi_le: string | null;
    tache: string | null;
    position: number | null;
    statut: string;
    retard: number;
};

export type GanttPlan = {
    jours: number;
    colonnes: { libelle: string; debut: string; gauche: number; largeur: number }[];
    periodes: { libelle: string; gauche: number; largeur: number }[];
    aujourdhui: number | null;
    barre_activite?: { prevue: Segment; projetee: Segment; debut: string | null; fin: string | null };
    taches: GanttTache[];
    jalons: GanttJalon[];
};

/** Largeur d’une journée selon l’échelle : la frise garde des proportions réelles. */
const PX_PAR_JOUR: Record<string, number> = { semaines: 20, mois: 5.5, trimestres: 1.9 };
const LIGNE = 46;
const ENTETE = 52;
const GAUCHE = 360;

/** Couleur du réel selon la performance : jamais seule, le statut est aussi écrit. */
const COULEUR: Record<string, string> = {
    atteint: 'var(--green-600)',
    en_bonne_voie: 'var(--green-600)',
    a_surveiller: 'var(--warning-solid)',
    en_retard: 'var(--orange-solid)',
    critique: 'var(--danger-solid)',
};

type Survol = { x: number; y: number; contenu: React.ReactNode } | null;

export default function GanttChart({ plan, echelle, cheminCritique, onTache, onJalon }: {
    plan: GanttPlan;
    echelle: string;
    cheminCritique: boolean;
    onTache: (tache: GanttTache) => void;
    onJalon: (jalon: GanttJalon) => void;
}) {
    const defilement = useRef<HTMLDivElement>(null);
    const [visible, setVisible] = useState(900);
    const [survol, setSurvol] = useState<Survol>(null);

    useLayoutEffect(() => {
        const element = defilement.current;
        if (!element) return undefined;
        const mesurer = () => setVisible(element.clientWidth - GAUCHE);
        mesurer();
        const observateur = new ResizeObserver(mesurer);
        observateur.observe(element);

        return () => observateur.disconnect();
    }, []);

    const largeur = Math.max(visible, Math.round(plan.jours * (PX_PAR_JOUR[echelle] ?? 5.5)));
    const x = (pourcentage: number) => (pourcentage / 100) * largeur;
    const lignes = plan.taches.length;
    const premiereTache = 1; // la ligne 0 est la synthèse de l’activité
    const hauteur = (premiereTache + lignes + plan.jalons.length) * LIGNE;

    // À l’ouverture, la frise se place sur aujourd’hui.
    useEffect(() => {
        const element = defilement.current;
        if (!element || plan.aujourdhui === null || plan.aujourdhui === undefined) return;
        element.scrollLeft = Math.max(0, x(plan.aujourdhui) - visible / 2);
    }, [plan, echelle, visible]);

    const indexParId = useMemo(() => new Map(plan.taches.map((tache, index) => [tache.id, index])), [plan.taches]);

    /** Fin affichée d’une tâche (réel, projection ou prévu), pour accrocher les flèches. */
    function bornes(tache: GanttTache): { debut: number; fin: number } | null {
        const affichees = [tache.reel, tache.projection, tache.prevu].filter((segment): segment is NonNullable<Segment> => segment !== null);
        const segments = affichees.length > 0 ? affichees : (tache.initial ? [tache.initial] : []);
        if (segments.length === 0) return null;

        return {
            debut: Math.min(...segments.map((segment) => segment.gauche)),
            fin: Math.max(...segments.map((segment) => segment.gauche + segment.largeur)),
        };
    }

    const fleches = plan.taches.flatMap((tache) => {
        if (tache.depend_de_id === null) return [];
        const indexAvant = indexParId.get(tache.depend_de_id);
        const avant = indexAvant !== undefined ? plan.taches[indexAvant] : undefined;
        const a = avant ? bornes(avant) : null;
        const b = bornes(tache);
        if (!avant || !a || !b || indexAvant === undefined) return [];
        const indexApres = indexParId.get(tache.id) ?? 0;
        // Départ sous la fin de la barre préalable, passage par l’interligne, arrivée au début de la tâche :
        // le tracé ne croise ni les barres ni les étiquettes d’avancement.
        const x1 = Math.max(0, x(a.fin) - 6);
        const y1 = (premiereTache + indexAvant) * LIGNE + 35;
        const interligne = (premiereTache + Math.max(indexAvant, indexApres)) * LIGNE - (indexApres > indexAvant ? 0 : LIGNE);
        const y2 = (premiereTache + indexApres) * LIGNE + 27;
        const x2 = x(b.debut);
        const critique = cheminCritique && tache.critique && avant.critique;

        return [{
            cle: `${avant.id}-${tache.id}`,
            critique,
            d: `M ${x1} ${y1} V ${interligne} H ${x2 - 10} V ${y2} H ${x2 - 2}`,
        }];
    });

    /** Infobulle en position fixe : jamais coupée par le cadre de défilement, retournée près des bords. */
    function montrer(event: MouseEvent, contenu: React.ReactNode) {
        const largeurBulle = 340;
        const gauche = event.clientX + 16 + largeurBulle > window.innerWidth ? event.clientX - largeurBulle - 12 : event.clientX + 16;
        const haut = event.clientY + 180 > window.innerHeight ? event.clientY - 170 : event.clientY + 16;
        setSurvol({ x: gauche, y: haut, contenu });
    }

    function clavier(event: KeyboardEvent, action: () => void) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            action();
        }
    }

    const activite = plan.barre_activite;

    return (
        <div className="gantt" ref={defilement} onMouseLeave={() => setSurvol(null)}>
            {/* Largeur explicite : la colonne figée reste visible jusqu’au bout de la frise. */}
            <div className="gantt-grille" style={{ gridTemplateColumns: `${GAUCHE}px ${largeur}px`, width: GAUCHE + largeur }}>
                {/* En-tête : colonne des tâches et échelle à deux niveaux */}
                <div className="gantt-coin" style={{ height: ENTETE }}>
                    <span>Tâche · responsable</span>
                    <span>Avancement</span>
                </div>
                <div className="gantt-echelle" style={{ height: ENTETE }}>
                    {plan.periodes.map((periode) => (
                        <span key={`p-${periode.libelle}-${periode.gauche}`} className="gantt-periode" style={{ left: x(periode.gauche), width: x(periode.largeur) }}>
                            {/* Libellé qui reste lisible tant que sa période est à l’écran */}
                            <em style={{ left: GAUCHE + 8 }}>{periode.libelle}</em>
                        </span>
                    ))}
                    {plan.colonnes.map((colonne) => (
                        <span key={colonne.debut} className="gantt-colonne" style={{ left: x(colonne.gauche), width: x(colonne.largeur) }}>{colonne.libelle}</span>
                    ))}
                </div>

                {/* Colonne gauche : synthèse, tâches, jalons */}
                <div className="gantt-gauche" style={{ height: hauteur }}>
                    <div className="gantt-ligne gantt-ligne-synthese" style={{ height: LIGNE }}>
                        <span className="gantt-libelle"><strong>Activité</strong><small>{activite?.debut ? `${fullDate(activite.debut)} → ${fullDate(activite.fin)}` : 'Dates de l’activité non renseignées'}</small></span>
                    </div>
                    {plan.taches.map((tache) => (
                        <div
                            key={tache.id}
                            role="button"
                            tabIndex={0}
                            className={['gantt-ligne', 'is-interactive', cheminCritique && tache.critique && 'is-critique'].filter(Boolean).join(' ')}
                            style={{ height: LIGNE }}
                            onClick={() => onTache(tache)}
                            onKeyDown={(event) => clavier(event, () => onTache(tache))}
                            aria-label={`Ouvrir la tâche ${tache.code} · ${tache.libelle}`}
                        >
                            <span className="gantt-code mono">{tache.code}</span>
                            <span className="gantt-libelle">
                                <strong title={tache.libelle}>{tache.libelle}</strong>
                                <small>
                                    {tache.responsable ?? 'Responsable non désigné'}
                                    {tache.depend_de ? ` · après ${tache.depend_de}` : ''}
                                </small>
                            </span>
                            <span className="gantt-etat">
                                {tache.planifiee ? <PerformancePill status={tache.statut} /> : <span className="badge badge-sm tone-neutral">Non planifiée</span>}
                                <span className="mono">{tache.avancement === null ? '—' : pct(tache.avancement, 0)}</span>
                            </span>
                        </div>
                    ))}
                    {plan.jalons.map((jalon) => (
                        <div
                            key={`j-${jalon.id}`}
                            role="button"
                            tabIndex={0}
                            className="gantt-ligne gantt-ligne-jalon is-interactive"
                            style={{ height: LIGNE }}
                            onClick={() => onJalon(jalon)}
                            onKeyDown={(event) => clavier(event, () => onJalon(jalon))}
                            aria-label={`Ouvrir le jalon ${jalon.libelle}`}
                        >
                            <span className={`gantt-losange is-${jalon.statut}`} aria-hidden="true" />
                            <span className="gantt-libelle">
                                <strong>{jalon.libelle}</strong>
                                <small>{jalon.franchi_le ? `Franchi le ${fullDate(jalon.franchi_le)}` : `Prévu le ${fullDate(jalon.prevu_le)}`}{jalon.tache ? ` · ${jalon.tache}` : ''}</small>
                            </span>
                            <span className="gantt-etat">{jalon.retard > 0 && <span className="gantt-retard">+{jalon.retard} j</span>}</span>
                        </div>
                    ))}
                </div>

                {/* Frise */}
                <div className="gantt-frise" style={{ height: hauteur, width: largeur }}>
                    {plan.colonnes.map((colonne) => <span key={`g-${colonne.debut}`} className="gantt-repere" style={{ left: x(colonne.gauche) }} />)}
                    {[...Array(premiereTache + lignes + plan.jalons.length)].map((_, index) => (
                        <span key={`r-${index}`} className={['gantt-rangee', index === 0 && 'is-synthese', index >= premiereTache + lignes && 'is-jalon'].filter(Boolean).join(' ')} style={{ top: index * LIGNE, height: LIGNE }} />
                    ))}

                    {activite?.prevue && (
                        <span className="gantt-barre-activite" style={{ left: x(activite.prevue.gauche), width: x(activite.prevue.largeur), top: 14 }} title={`Activité prévue ${fullDate(activite.debut)} → ${fullDate(activite.fin)}`} />
                    )}
                    {activite?.projetee && (
                        <span className="gantt-barre-activite is-projetee" style={{ left: x(activite.projetee.gauche), width: x(activite.projetee.largeur), top: 28 }} />
                    )}

                    <svg className="gantt-fleches" width={largeur} height={hauteur} aria-hidden="true">
                        <defs>
                            <marker id="gantt-pointe" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto"><path d="M0,0 L8,4 L0,8 z" fill="var(--slate-400)" /></marker>
                            <marker id="gantt-pointe-critique" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto"><path d="M0,0 L8,4 L0,8 z" fill="var(--danger-solid)" /></marker>
                        </defs>
                        {fleches.map((fleche) => (
                            <path key={fleche.cle} d={fleche.d} fill="none" stroke={fleche.critique ? 'var(--danger-solid)' : 'var(--slate-400)'} strokeWidth={fleche.critique ? 1.8 : 1.2} markerEnd={`url(#${fleche.critique ? 'gantt-pointe-critique' : 'gantt-pointe'})`} />
                        ))}
                    </svg>

                    {plan.taches.map((tache, index) => {
                        const haut = (premiereTache + index) * LIGNE;
                        const couleur = COULEUR[tache.statut] ?? 'var(--slate-500)';
                        const critique = cheminCritique && tache.critique;
                        const infobulle = (
                            <div className="stack-sm" style={{ gap: 4 }}>
                                <strong>{tache.code} · {tache.libelle}</strong>
                                <span>Planning initial : {tache.debut_initial ? `${fullDate(tache.debut_initial)} → ${fullDate(tache.fin_initiale)}` : 'non planifié'}</span>
                                {tache.debut_courant && tache.fin_courante !== tache.fin_initiale && <span>Planning courant : {fullDate(tache.debut_courant)} → {fullDate(tache.fin_courante)}</span>}
                                <span>Réel : {tache.debut_reel ? `${fullDate(tache.debut_reel)} → ${tache.fin_reelle ? fullDate(tache.fin_reelle) : 'en cours'}` : 'non démarrée'}</span>
                                {tache.fin_projetee && !tache.fin_reelle && <span>Fin projetée : {fullDate(tache.fin_projetee)}</span>}
                                <span>Avancement : {tache.avancement === null ? 'non renseigné' : pct(tache.avancement, 0)}{tache.poids !== null ? ` · poids ${tache.poids} %` : ''}</span>
                                {tache.retard_fin > 0 && <span className="text-warning">Retard projeté : +{tache.retard_fin} j</span>}
                                {tache.non_demarree && tache.retard_demarrage > 0 && <span className="text-warning">Démarrage en retard de {tache.retard_demarrage} j</span>}
                                {critique && <span className="text-warning">Sur le chemin critique</span>}
                            </div>
                        );

                        return (
                            <div key={tache.id} className="gantt-barres" style={{ top: haut, height: LIGNE }} onMouseMove={(event) => montrer(event, infobulle)} onMouseLeave={() => setSurvol(null)} onClick={() => onTache(tache)}>
                                {!tache.planifiee && !tache.reel && (
                                    <span className="gantt-a-planifier"><em style={{ left: GAUCHE + 12 }}>Non planifiée · cliquez pour saisir ses dates prévues</em></span>
                                )}
                                {tache.initial && <span className="gantt-barre-initiale" style={{ left: x(tache.initial.gauche), width: x(tache.initial.largeur) }} />}
                                {!tache.reel && !tache.projection && tache.prevu && (
                                    <span className="gantt-barre-prevue" style={{ left: x(tache.prevu.gauche), width: x(tache.prevu.largeur) }} />
                                )}
                                {tache.reel && (
                                    <span className={['gantt-barre-reelle', critique && 'is-critique'].filter(Boolean).join(' ')} style={{ left: x(tache.reel.gauche), width: x(tache.reel.largeur), ['--barre' as string]: couleur }}>
                                        <span className="gantt-progression" style={{ width: `${Math.max(0, Math.min(100, tache.avancement ?? 0))}%` }} />
                                    </span>
                                )}
                                {tache.projection && (
                                    <span className={['gantt-barre-projetee', critique && 'is-critique'].filter(Boolean).join(' ')} style={{ left: x(tache.projection.gauche), width: x(tache.projection.largeur), ['--barre' as string]: couleur }} />
                                )}
                                {(() => {
                                    const limites = bornes(tache);
                                    if (!limites || (!tache.reel && !tache.projection && !tache.prevu)) return null;

                                    return (
                                        <span className="gantt-etiquette" style={{ left: x(limites.fin) + 8 }}>
                                            {tache.retard_fin > 0 ? <b className="gantt-retard">+{tache.retard_fin} j</b> : tache.avancement !== null ? pct(tache.avancement, 0) : ''}
                                        </span>
                                    );
                                })()}
                            </div>
                        );
                    })}

                    {plan.jalons.map((jalon, index) => jalon.position !== null && (
                        <span
                            key={`jj-${jalon.id}`}
                            className={`gantt-losange is-${jalon.statut}`}
                            style={{ left: x(jalon.position) - 7, top: (premiereTache + lignes + index) * LIGNE + LIGNE / 2 - 7 }}
                            onMouseMove={(event) => montrer(event, <div className="stack-sm" style={{ gap: 4 }}><strong>{jalon.libelle}</strong><span>Prévu le {fullDate(jalon.prevu_le)}</span>{jalon.franchi_le && <span>Franchi le {fullDate(jalon.franchi_le)}</span>}{jalon.retard > 0 && <span className="text-warning">Retard : {jalon.retard} j</span>}</div>)}
                            onMouseLeave={() => setSurvol(null)}
                            onClick={() => onJalon(jalon)}
                        />
                    ))}

                    {plan.aujourdhui !== null && plan.aujourdhui !== undefined && (
                        <span className="gantt-aujourdhui" style={{ left: x(plan.aujourdhui) }}>
                            <span>{dayMonth(new Date().toISOString().slice(0, 10))}</span>
                        </span>
                    )}
                </div>
            </div>
            {survol && <div className="gantt-infobulle" style={{ left: survol.x, top: survol.y }} role="tooltip">{survol.contenu}</div>}
        </div>
    );
}
