/** Client HTTP de l'API PHP. Toutes les routes sont sous /api. */

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly details: Record<string, unknown> = {},
  ) {
    super(message);
  }
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const response = await fetch('/api' + path, {
    ...init,
    headers: init.body instanceof FormData ? init.headers : { 'Content-Type': 'application/json', ...init.headers },
  });
  const body: unknown = await response.json().catch(() => null);
  if (!response.ok) {
    const details = (body ?? {}) as Record<string, unknown>;
    const message = typeof details.erreur === 'string' ? details.erreur : `Erreur HTTP ${response.status}`;
    throw new ApiError(response.status, message, details);
  }
  return body as T;
}

export interface Sante {
  statut: string;
  php: string;
  données: boolean;
}
