import { NextRequest, NextResponse } from 'next/server';
import { deposerCandidature } from '@/lib/demandes';

/**
 * Proxy PUBLIC de dépôt d'une candidature (P11.1, CDC_11 §3 méthode 2).
 *
 * Sans authentification, comme l'endpoint qu'il appelle : le demandeur n'a ni compte ni contact
 * préalable. Le limiteur strict et la règle « une seule demande en attente par adresse » vivent
 * côté backend, seule autorité.
 */
export async function POST(req: NextRequest) {
  const res = await deposerCandidature(await req.json());

  return NextResponse.json(await res.json().catch(() => ({})), { status: res.status });
}
