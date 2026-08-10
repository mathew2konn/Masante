import { NextRequest, NextResponse } from 'next/server';
import { lancerScan } from '@/lib/fraude';

/**
 * Proxy — lance un scan de routage de fraude (extrait → score → alerte → notif ADMIN_FINANCE).
 * La garde de rôle + le mint du principal signé sont dans `lancerScan` (frontière : aucune décision
 * ici). `journee` optionnelle (défaut = aujourd'hui côté paiement).
 */
export async function POST(req: NextRequest) {
  const journee = req.nextUrl.searchParams.get('journee') ?? undefined;
  const r = await lancerScan(journee);
  if (!r.ok) {
    return NextResponse.json({ message: 'Scan refusé ou indisponible.' }, { status: r.statut });
  }
  return NextResponse.json(r.data);
}
