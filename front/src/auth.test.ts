import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, api, connexion, connexionCode, deconnexion, getSession, putParametres } from './api';
import { EVENEMENT_SESSION, ecranPour, enteteCsrf, estRouteDeConnexion, nettoyerCodeTotp, oublierSession, type EtatSession } from './auth';

const ETAT: EtatSession = { compte: true, totp: true, etape: 'identifiants', csrf: null, identifiant: null };
const CONNECTE: EtatSession = { ...ETAT, etape: 'connecte', csrf: 'jeton-123', identifiant: 'olivier' };

function reponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

describe('écran affiché', () => {
  it('suit l’étape de la session', () => {
    expect(ecranPour(ETAT)).toBe('identifiants');
    expect(ecranPour({ ...ETAT, etape: 'code' })).toBe('code');
    expect(ecranPour(CONNECTE)).toBe('application');
    expect(ecranPour({ ...ETAT, compte: false })).toBe('sans-compte');
  });
});

describe('saisie du code', () => {
  it('ne garde que 6 chiffres', () => {
    expect(nettoyerCodeTotp('123 456')).toBe('123456');
    expect(nettoyerCodeTotp('12a34b5678')).toBe('123456');
    expect(nettoyerCodeTotp('')).toBe('');
  });
});

describe('routes de connexion', () => {
  it('un 401 y est une réponse normale', () => {
    expect(estRouteDeConnexion('/connexion')).toBe(true);
    expect(estRouteDeConnexion('/connexion/code')).toBe(true);
    expect(estRouteDeConnexion('/session')).toBe(true);
    expect(estRouteDeConnexion('/mois')).toBe(false);
  });
});

describe('client HTTP', () => {
  const fetchMock = vi.fn<typeof fetch>();
  let fenetre: EventTarget;

  beforeEach(() => {
    fenetre = new EventTarget();
    vi.stubGlobal('window', fenetre);
    vi.stubGlobal('fetch', fetchMock);
    oublierSession();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    fetchMock.mockReset();
  });

  function entetes(appel: number): Record<string, string> {
    return fetchMock.mock.calls[appel]?.[1]?.headers as Record<string, string>;
  }

  it('connexion en deux temps, puis jeton CSRF sur les écritures seulement', async () => {
    fetchMock
      .mockResolvedValueOnce(reponse(200, { ...ETAT, etape: 'code' }))
      .mockResolvedValueOnce(reponse(200, CONNECTE))
      .mockResolvedValueOnce(reponse(200, { thème: 'nuit' }))
      .mockResolvedValueOnce(reponse(200, []));

    expect((await connexion('olivier', 'un mot de passe long')).etape).toBe('code');
    expect(enteteCsrf('POST')).toEqual({});
    expect((await connexionCode('123456')).etape).toBe('connecte');

    await putParametres({ thème: 'nuit' });
    expect(entetes(2)['X-CSRF-Token']).toBe('jeton-123');
    await api('/mois');
    expect(entetes(3)['X-CSRF-Token']).toBeUndefined();
    expect(fetchMock.mock.calls[0]?.[1]?.credentials).toBe('same-origin');
  });

  it('un 401 sur une route protégée signale la session expirée et oublie le jeton', async () => {
    fetchMock.mockResolvedValueOnce(reponse(200, CONNECTE)).mockResolvedValueOnce(reponse(401, { erreur: 'Connexion requise' }));
    const ecouteur = vi.fn();
    fenetre.addEventListener(EVENEMENT_SESSION, ecouteur);

    await getSession();
    expect(enteteCsrf('PUT')).toEqual({ 'X-CSRF-Token': 'jeton-123' });
    await expect(api('/mois')).rejects.toBeInstanceOf(ApiError);
    expect(ecouteur).toHaveBeenCalledTimes(1);
    expect(enteteCsrf('PUT')).toEqual({});
  });

  it('un mot de passe faux ne déclenche pas l’événement', async () => {
    fetchMock.mockResolvedValueOnce(reponse(401, { erreur: 'Identifiant ou mot de passe incorrect' }));
    const ecouteur = vi.fn();
    fenetre.addEventListener(EVENEMENT_SESSION, ecouteur);

    await expect(connexion('olivier', 'faux')).rejects.toThrow('Identifiant ou mot de passe incorrect');
    expect(ecouteur).not.toHaveBeenCalled();
  });

  it('la déconnexion oublie le jeton même si le serveur ne répond pas', async () => {
    fetchMock.mockResolvedValueOnce(reponse(200, CONNECTE)).mockRejectedValueOnce(new TypeError('réseau'));
    await getSession();
    await expect(deconnexion()).rejects.toThrow('réseau');
    expect(enteteCsrf('POST')).toEqual({});
  });
});
