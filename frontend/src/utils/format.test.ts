import { describe, expect, it } from 'vitest';
import { dateFr, dateHeure, errorsOf, fcfa, libelleCode, percent, resumeDetails } from './format';

// Espaces insécables produites par Intl en français (séparateur de milliers et avant %).
const normaliser = (texte: string) => texte.replace(/[  ]/g, ' ');

describe('formats financiers', () => {
    it('affiche les montants XAF entiers avec séparateur de milliers', () => {
        expect(normaliser(fcfa(10_000_000))).toBe('10 000 000');
        expect(normaliser(fcfa(null))).toBe('0');
    });

    it('affiche un pourcentage en français', () => {
        expect(normaliser(percent(12.345))).toBe('12,3 %');
        expect(normaliser(percent(undefined))).toBe('0 %');
    });
});

describe('formats de date', () => {
    it('convertit une date ISO en jj/mm/aaaa', () => {
        expect(dateFr('2026-10-05')).toBe('05/10/2026');
        expect(dateFr('2026-10-05 22:01:06')).toBe('05/10/2026');
        expect(dateFr(null)).toBe('—');
    });

    it('ajoute l’heure lorsqu’elle est connue', () => {
        expect(dateHeure('2026-10-05 22:01:06')).toBe('05/10/2026 à 22:01');
        expect(dateHeure('2026-10-05')).toBe('05/10/2026');
    });
});

describe('libellés lisibles', () => {
    it('transforme un code technique en libellé accentué', () => {
        expect(libelleCode('titre_cree')).toBe('Titre créé');
        expect(libelleCode('marche.rattache')).toBe('Marche · rattaché');
        expect(libelleCode(undefined)).toBe('—');
    });

    it('résume des détails sans exposer d’objet brut', () => {
        expect(normaliser(resumeDetails({ montant: 1500000, statut: 'solde' }) ?? '')).toBe('Montant : 1 500 000 FCFA · Statut : soldé');
        expect(resumeDetails({ imbrique: { a: 1 } })).toBeUndefined();
        expect(resumeDetails(null)).toBeUndefined();
    });
});

describe('messages d’erreur de l’API', () => {
    const erreur = (status: number, data: Record<string, unknown>) => ({ response: { status, data } });

    it('remplace un refus technique par un message compréhensible', () => {
        expect(errorsOf(erreur(403, { message: 'This action is unauthorized.' }))).toBe('Vous n’avez pas accès à cet écran.');
    });

    it('conserve un refus métier explicite', () => {
        expect(errorsOf(erreur(403, { message: 'Le registre des marchés est tenu par le Budget.' }))).toBe('Le registre des marchés est tenu par le Budget.');
    });

    it('rassemble les erreurs de validation', () => {
        expect(errorsOf(erreur(422, { message: 'Invalide', errors: { montant: ['Montant requis.'], date: ['Date requise.'] } }))).toBe('Montant requis. Date requise.');
    });

    it('a un message par défaut sans réponse du serveur', () => {
        expect(errorsOf({})).toBe('Une erreur est survenue.');
    });
});
