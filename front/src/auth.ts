/** État de connexion et jeton CSRF, partagés par le client HTTP (api.ts) et la page de connexion. */

/** Réponse de GET /api/session et des routes de connexion */
export interface EtatSession {
  compte: boolean;
  totp: boolean;
  etape: 'identifiants' | 'code' | 'connecte';
  csrf: string | null;
  identifiant: string | null;
  codes_secours_restants?: number;
}

/** Ce que l'application affiche selon l'état de la session */
export type Ecran = 'sans-compte' | 'identifiants' | 'code' | 'application';

/** Événement émis quand l'API répond 401 hors connexion : la session a expiré, il faut se reconnecter */
export const EVENEMENT_SESSION = 'mes-comptes:session';

let jetonCsrf: string | null = null;

/** Mémorise le jeton CSRF reçu avec l'état de session (null après déconnexion) */
export function memoriserSession(etat: EtatSession): EtatSession {
  jetonCsrf = etat.etape === 'connecte' ? etat.csrf : null;
  return etat;
}

export function oublierSession(): void {
  jetonCsrf = null;
}

const METHODES_SURES = ['GET', 'HEAD', 'OPTIONS'];

/** En-têtes ajoutés à une requête : le jeton CSRF pour toute écriture, si on est connecté */
export function enteteCsrf(methode: string | undefined): Record<string, string> {
  const ecriture = !METHODES_SURES.includes((methode ?? 'GET').toUpperCase());
  return ecriture && jetonCsrf ? { 'X-CSRF-Token': jetonCsrf } : {};
}

/** Routes dont un 401 est une réponse normale (mauvais mot de passe…), pas une session expirée */
export function estRouteDeConnexion(chemin: string): boolean {
  return chemin === '/session' || chemin === '/deconnexion' || chemin.startsWith('/connexion');
}

export function ecranPour(etat: EtatSession): Ecran {
  if (etat.etape === 'connecte') return 'application';
  if (!etat.compte) return 'sans-compte';
  return etat.etape === 'code' ? 'code' : 'identifiants';
}

/** Code TOTP : seulement les chiffres, au plus 6 ; un code de secours est gardé tel quel */
export function nettoyerCodeTotp(saisie: string): string {
  return saisie.replace(/\D/g, '').slice(0, 6);
}
