'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { connexionProSchema, otpSchema } from '@masante/shared';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Button } from '@/components/ui/Button';

/**
 * Connexion professionnelle (portail web). Deux étapes possibles :
 *  1. téléphone + mot de passe ;
 *  2. si le backend l'exige, code du second facteur (MFA obligatoire pour les pros, CDC_10 §3.5).
 *
 * Validation de SAISIE via les schémas Zod partagés ; toute décision (identifiants, MFA)
 * est prise par le backend — le front ne fait que présenter (frontière CDC_02 §0.1).
 */
export default function LoginPage() {
  const router = useRouter();
  const [etape, setEtape] = useState<'identifiants' | 'mfa'>('identifiants');

  const [telephone, setTelephone] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [mfaToken, setMfaToken] = useState('');

  const [erreurs, setErreurs] = useState<{ telephone?: string; password?: string; code?: string; global?: string }>({});
  const [chargement, setChargement] = useState(false);

  const entrerPortail = () => {
    router.replace('/');
    router.refresh();
  };

  const soumettreIdentifiants = async (e: React.FormEvent) => {
    e.preventDefault();
    setErreurs({});

    const parsed = connexionProSchema.safeParse({ telephone, password });
    if (!parsed.success) {
      const champ: typeof erreurs = {};
      for (const issue of parsed.error.issues) {
        const k = issue.path[0];
        if (k === 'telephone' || k === 'password') champ[k] = issue.message;
      }
      setErreurs(champ);
      return;
    }

    setChargement(true);
    try {
      const res = await fetch('/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        // Le backend attend le format international ; le pays vient du référentiel (ADR-001).
        body: JSON.stringify({ telephone: `+225${parsed.data.telephone}`, password: parsed.data.password }),
      });
      const data = await res.json();

      if (!res.ok) {
        setErreurs({ global: 'Identifiants invalides.' });
        return;
      }
      if (data.mfa_required) {
        setMfaToken(data.mfa_token);
        setEtape('mfa');
        return;
      }
      entrerPortail();
    } catch {
      setErreurs({ global: 'Connexion impossible. Vérifiez votre réseau.' });
    } finally {
      setChargement(false);
    }
  };

  const soumettreMfa = async (e: React.FormEvent) => {
    e.preventDefault();
    setErreurs({});

    const parsed = otpSchema.safeParse(code);
    if (!parsed.success) {
      setErreurs({ code: parsed.error.issues[0]?.message });
      return;
    }

    setChargement(true);
    try {
      const res = await fetch('/api/auth/mfa/verify', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ mfa_token: mfaToken, code: parsed.data }),
      });
      if (!res.ok) {
        setErreurs({ code: 'Code incorrect ou expiré.' });
        return;
      }
      entrerPortail();
    } catch {
      setErreurs({ global: 'Connexion impossible. Vérifiez votre réseau.' });
    } finally {
      setChargement(false);
    }
  };

  return (
    <Card>
      <h1 className="mb-1 text-2xl font-bold text-blue-900">Portail professionnel</h1>
      <p className="mb-6 text-sm text-ink-700">
        {etape === 'identifiants'
          ? 'Connectez-vous avec votre numéro et votre mot de passe.'
          : 'Saisissez le code de votre application d’authentification.'}
      </p>

      {erreurs.global ? (
        <p role="alert" className="mb-4 rounded-md bg-danger/10 px-3 py-2 text-sm text-danger">
          {erreurs.global}
        </p>
      ) : null}

      {etape === 'identifiants' ? (
        <form onSubmit={soumettreIdentifiants} noValidate>
          <Input
            label="Numéro de téléphone"
            type="tel"
            inputMode="numeric"
            autoComplete="username"
            placeholder="0700000000"
            value={telephone}
            onChange={(e) => setTelephone(e.target.value)}
            erreur={erreurs.telephone}
          />
          <Input
            label="Mot de passe"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            erreur={erreurs.password}
          />
          <Button type="submit" loading={chargement}>
            Se connecter
          </Button>
        </form>
      ) : (
        <form onSubmit={soumettreMfa} noValidate>
          <Input
            label="Code de vérification"
            type="text"
            inputMode="numeric"
            autoComplete="one-time-code"
            maxLength={6}
            placeholder="123456"
            value={code}
            onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
            erreur={erreurs.code}
            autoFocus
          />
          <Button type="submit" loading={chargement}>
            Vérifier
          </Button>
        </form>
      )}
    </Card>
  );
}
