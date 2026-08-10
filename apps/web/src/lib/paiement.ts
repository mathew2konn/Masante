import 'server-only';
import { createHmac, randomUUID } from 'node:crypto';

/**
 * Accès au microservice PAIEMENT (Java, port 8080) — CÔTÉ SERVEUR uniquement (Route Handlers,
 * Server Components). Canal DISTINCT du proxy Laravel/Sanctum (`api.ts`) : les alertes de fraude
 * IA vivent dans le paiement, dont les endpoints sensibles sont gardés par un <b>principal signé</b>
 * (P5.5b-1) et non par un Bearer. Next agit ici comme la <b>passerelle authentifiée</b> : il vérifie
 * la session/rôle Laravel (voir `fraude.ts`) PUIS mint un principal signé pour le paiement.
 *
 * Signature reproduite à l'identique du vérifieur Java (`ServicePrincipal`) et du `signer.py` de dev :
 *   X-Principal      = base64(JSON {sub,roles,iat,exp,method,path,nonce})
 *   X-Principal-Sig  = base64(HMAC-SHA256(octets UTF-8 de X-Principal, secret décodé du base64))
 * Contrôles côté paiement : signature (temps constant), fraîcheur ±5 min, liaison method+path,
 * nonce anti-rejeu (Redis). Aucune règle métier ici (CDC_02 §0.1) : transport + signature seulement.
 *
 * Le `path` signé est le chemin SANS query (le paiement lie sur `getRequestURI()`). Toute query
 * s'ajoute à l'URL de fetch mais jamais au principal signé.
 */
const PAYMENT_BASE = process.env.PAYMENT_URL ?? 'http://localhost:8080';
const SECRET_B64 = process.env.MASANTE_PAYMENT_PRINCIPAL_SECRET ?? '';

/** Identité + rôles portés par le principal minté (le rôle habilité est décidé dans `fraude.ts`). */
export type PrincipalPaiement = { sub: string; roles: string[] };

/** Options d'un appel signé : query (hors signature) et corps JSON éventuel. */
export type OptionsPaiement = { query?: string; body?: unknown };

function entetesPrincipal(methode: string, chemin: string, principal: PrincipalPaiement) {
  const maintenant = Math.floor(Date.now() / 1000);
  const claims = {
    sub: principal.sub,
    roles: principal.roles,
    iat: maintenant,
    exp: maintenant + 120,
    method: methode,
    path: chemin,
    nonce: randomUUID(),
  };
  const principalB64 = Buffer.from(JSON.stringify(claims), 'utf8').toString('base64');
  const secret = Buffer.from(SECRET_B64, 'base64');
  const sig = createHmac('sha256', secret).update(principalB64, 'utf8').digest('base64');
  return { 'X-Principal': principalB64, 'X-Principal-Sig': sig };
}

/** Appel signé au paiement. `chemin` = pathname exact (sans query) ; c'est lui qui est signé. */
export async function paiementFetch(
  methode: string,
  chemin: string,
  principal: PrincipalPaiement,
  opts: OptionsPaiement = {},
): Promise<Response> {
  const url = `${PAYMENT_BASE}${chemin}${opts.query ? `?${opts.query}` : ''}`;
  return fetch(url, {
    method: methode,
    headers: {
      Accept: 'application/json',
      ...(opts.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...entetesPrincipal(methode, chemin, principal),
    },
    body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
    cache: 'no-store',
  });
}
