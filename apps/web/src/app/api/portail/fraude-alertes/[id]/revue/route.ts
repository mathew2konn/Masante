import { NextResponse } from 'next/server';
import { marquerRevue } from '@/lib/fraude';

/**
 * Proxy — marque une alerte de fraude « revue » (trace humaine, aucune action automatique :
 * détection seule — ADR-017). Garde de rôle + principal signé dans `marquerRevue`.
 */
export async function POST(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const r = await marquerRevue(id);
  if (!r.ok) {
    return NextResponse.json({ message: 'Revue refusée ou indisponible.' }, { status: r.statut });
  }
  return NextResponse.json(r.data);
}
