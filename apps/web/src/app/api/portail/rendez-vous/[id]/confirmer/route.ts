import { NextRequest, NextResponse } from 'next/server';
import { authedFetch } from '@/lib/api';

/** Proxy — confirme un RDV (date définitive + médecin optionnel + message). Auth via cookie. */
export async function PATCH(req: NextRequest, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const body = await req.json();
  const res = await authedFetch(`/v1/portail/rendez-vous/${id}/confirmer`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const data = await res.json();
  return NextResponse.json(data, { status: res.status });
}
