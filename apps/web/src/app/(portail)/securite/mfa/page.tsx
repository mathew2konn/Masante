'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { QRCodeSVG } from 'qrcode.react';
import { otpSchema } from '@masante/shared';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Button } from '@/components/ui/Button';
import type { MfaEnroll, MfaStatus } from '@/lib/types';

/**
 * Double authentification du portail (P1, CDC_10 §3.5). Obligatoire pour les pros une fois
 * `MFA_ENFORCE=true` côté backend. Cet écran AFFICHE l'état fourni par le backend et présente
 * l'enrôlement (QR de l'otpauth://) ; il ne décide jamais si la MFA est requise (frontière CDC_02).
 */
export default function MfaPortailPage() {
  const [statut, setStatut] = useState<MfaStatus | null>(null);
  const [chargement, setChargement] = useState(true);
  const [enrolement, setEnrolement] = useState<MfaEnroll | null>(null);
  const [code, setCode] = useState('');
  const [erreur, setErreur] = useState<string | null>(null);
  const [enCours, setEnCours] = useState(false);

  const chargerStatut = async () => {
    const res = await fetch('/api/auth/mfa/status');
    if (res.ok) setStatut(await res.json());
    setChargement(false);
  };

  useEffect(() => {
    chargerStatut();
  }, []);

  const activer = async () => {
    setErreur(null);
    setEnCours(true);
    try {
      const res = await fetch('/api/auth/mfa/enroll', { method: 'POST' });
      if (!res.ok) throw new Error();
      setEnrolement(await res.json());
      setCode('');
    } catch {
      setErreur('Impossible de démarrer la configuration.');
    } finally {
      setEnCours(false);
    }
  };

  const confirmer = async (e: React.FormEvent) => {
    e.preventDefault();
    setErreur(null);
    const parsed = otpSchema.safeParse(code);
    if (!parsed.success) {
      setErreur(parsed.error.issues[0]?.message ?? 'Code invalide.');
      return;
    }
    setEnCours(true);
    try {
      const res = await fetch('/api/auth/mfa/confirm', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code: parsed.data }),
      });
      if (!res.ok) {
        setErreur('Code incorrect. Réessayez.');
        return;
      }
      setEnrolement(null);
      setCode('');
      await chargerStatut();
    } finally {
      setEnCours(false);
    }
  };

  const desactiver = async () => {
    setEnCours(true);
    try {
      await fetch('/api/auth/mfa', { method: 'DELETE' });
      await chargerStatut();
    } finally {
      setEnCours(false);
    }
  };

  if (chargement) {
    return <p className="text-ink-700">Chargement…</p>;
  }

  const active = statut?.facteur_confirme === true;

  return (
    <div className="space-y-6">
      <div>
        <Link href="/" className="text-sm text-primary underline">
          ← Retour
        </Link>
        <h1 className="mt-2 text-2xl font-bold text-blue-900">Double authentification</h1>
        <p className="text-ink-700">Un second code à la connexion (2FA).</p>
      </div>

      {erreur ? (
        <p role="alert" className="rounded-md bg-danger/10 px-3 py-2 text-sm text-danger">
          {erreur}
        </p>
      ) : null}

      {enrolement ? (
        <Card>
          <h2 className="mb-2 text-lg font-semibold text-blue-900">Configurer</h2>
          <p className="mb-4 text-sm text-ink-700">
            Scannez ce code avec votre application d’authentification (Google Authenticator, Authy…),
            puis saisissez le code à 6 chiffres affiché.
          </p>
          <div className="mb-4 flex justify-center rounded-md bg-surface p-4">
            <QRCodeSVG value={enrolement.otpauth_uri} size={200} />
          </div>
          <p className="mb-1 text-xs text-ink-500">Ou saisissez cette clé manuellement :</p>
          <p className="mb-4 break-all font-mono text-sm font-semibold tracking-wider text-blue-900">
            {enrolement.secret}
          </p>
          <form onSubmit={confirmer}>
            <Input
              label="Code de vérification"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              placeholder="123456"
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
            />
            <Button type="submit" loading={enCours} disabled={code.length !== 6}>
              Activer
            </Button>
          </form>
        </Card>
      ) : (
        <Card>
          <p className="mb-1 font-semibold text-blue-900">{active ? '✓ Activée' : 'Inactive'}</p>
          <p className="mb-4 text-sm text-ink-700">
            {active
              ? 'À la connexion, un code de votre application d’authentification est demandé en plus du mot de passe.'
              : 'Ajoutez une seconde barrière : un code temporaire généré par une application, en plus de votre mot de passe.'}
          </p>
          {active ? (
            <Button variant="secondary" onClick={desactiver} loading={enCours}>
              Désactiver
            </Button>
          ) : (
            <Button onClick={activer} loading={enCours}>
              Activer la double authentification
            </Button>
          )}
        </Card>
      )}
    </div>
  );
}
