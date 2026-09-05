import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { QrMasante } from '../../../../src/components/QrMasante';
import { Screen } from '../../../../src/components/Screen';
import { Card } from '../../../../src/components/Card';
import { Chip } from '../../../../src/components/Chip';
import { ScreenHeader } from '../../../../src/components/ScreenHeader';
import { PrimaryButton } from '../../../../src/components/PrimaryButton';
import {
  disponibiliteEnLignePaiement,
  obtenirRecu,
  payerRendezVous,
  payerRendezVousEnLigne,
} from '../../../../src/api/rendezvous';
import { messageErreur } from '../../../../src/utils/erreurs';
import { formatDateFr } from '../../../../src/utils/dates';
import {
  LIBELLE_MODE_PAIEMENT,
  type ModePaiement,
  type RecuRdv,
} from '../../../../src/types/structure';
import { colors, radius, spacing, typography } from '../../../../src/theme/theme';

const MODES: ModePaiement[] = ['mobile_money', 'especes', 'carte'];

/** Formate un montant en FCFA avec séparateur de milliers (Hermes n'a pas toujours toLocaleString). */
function formatFcfa(montant: number): string {
  return `${montant.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ')} FCFA`;
}

/** Statut lisible + couleur du reçu. */
const STATUT_RECU: Record<string, { label: string; bg: string; text: string }> = {
  reserve: { label: 'Réservé', bg: colors.warning.bg, text: colors.warning.text },
  paye: { label: 'Payé', bg: colors.success.bg, text: colors.success.text },
  confirme: { label: 'Confirmé', bg: colors.success.bg, text: colors.success.text },
  utilise: { label: 'Utilisé', bg: colors.blue[50], text: colors.blue[700] },
  annule: { label: 'Annulé', bg: colors.surfaceMuted, text: colors.ink[500] },
  expire: { label: 'Expiré', bg: colors.surfaceMuted, text: colors.ink[500] },
};

/**
 * Reçu de RDV + QR de check-in (Module 3 / N2-N3). ⚠️ Paiement SIMULÉ (pas de passerelle réelle).
 *
 * À l'ouverture : on tente de récupérer le reçu. Si le RDV n'est pas encore payé (404), on propose
 * le paiement (choix du mode). Le QR affiché est un token signé autonome qui NE donne PAS accès au
 * dossier médical — il ne sert qu'au check-in à l'accueil (scan/validation = Module 4).
 */
export default function RecuRdvEcran() {
  const { id, nom, tarif } = useLocalSearchParams<{ id: string; nom: string; tarif?: string }>();
  const rdvId = Number(id);
  // B1-b (D7) — aperçu transmis par la fiche (déjà calculé côté serveur, jamais recalculé ici) :
  // affiché AVANT paiement, ce que l'écran ne faisait pas.
  const montantAPayer = tarif ? Number(tarif) : null;

  const [recu, setRecu] = useState<RecuRdv | null>(null);
  const [aPayer, setAPayer] = useState(false);
  const [mode, setMode] = useState<ModePaiement>('mobile_money');
  const [chargement, setChargement] = useState(true);
  const [envoi, setEnvoi] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);
  // B4-b — chemin réel (GeniusPay), à côté du chemin simulé ci-dessus (aucun des deux retiré).
  const [enLigneDisponible, setEnLigneDisponible] = useState(false);
  const [ouvertureEnCours, setOuvertureEnCours] = useState(false);

  const charger = useCallback(async () => {
    setChargement(true);
    setErreur(null);
    try {
      setRecu(await obtenirRecu(rdvId));
      setAPayer(false);
    } catch (e) {
      const statut = (e as { response?: { status?: number } })?.response?.status;
      if (statut === 404) {
        setAPayer(true); // pas encore payé → on propose le paiement
      } else {
        setErreur(messageErreur(e));
      }
    } finally {
      setChargement(false);
    }
  }, [rdvId]);

  useEffect(() => {
    void charger();
  }, [charger]);

  // B4-b (S7) — la disponibilité se vérifie une fois qu'on sait qu'il y a quelque chose à payer ;
  // un établissement non configuré n'affiche simplement pas le bouton (rien n'est proposé qui ne
  // puisse aboutir), sans que ce soit traité comme une erreur.
  useEffect(() => {
    if (!aPayer) return;
    disponibiliteEnLignePaiement(rdvId)
      .then(setEnLigneDisponible)
      .catch(() => setEnLigneDisponible(false));
  }, [aPayer, rdvId]);

  async function payer() {
    setEnvoi(true);
    setErreur(null);
    try {
      setRecu(await payerRendezVous(rdvId, mode));
      setAPayer(false);
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setEnvoi(false);
    }
  }

  /**
   * B4-b (S6/S8) — ouvre le checkout dans le NAVIGATEUR (jamais une WebView, pour que l'utilisateur
   * voie l'URL et le cadenas). Ne règle rien : le retour dans l'app ne suppose aucun résultat —
   * c'est « Actualiser » (ci-dessous) qui relit le reçu, seul juge de ce qui s'est passé.
   */
  async function payerEnLigne() {
    setOuvertureEnCours(true);
    setErreur(null);
    try {
      const { checkout_url: url } = await payerRendezVousEnLigne(rdvId);
      if (url) {
        await Linking.openURL(url);
      } else {
        setErreur('Le prestataire n\'a pas renvoyé de lien de paiement.');
      }
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setOuvertureEnCours(false);
    }
  }

  if (chargement) {
    return (
      <Screen scroll={false}>
        <ScreenHeader title="Reçu de rendez-vous" subtitle={nom} onBack={() => router.back()} />
        <View style={styles.centre}>
          <ActivityIndicator color={colors.blue[600]} />
        </View>
      </Screen>
    );
  }

  // Écran de paiement (RDV pas encore réglé).
  if (aPayer && !recu) {
    return (
      <Screen footer={<PrimaryButton label="Payer" onPress={payer} loading={envoi} />}>
        <ScreenHeader title="Paiement du rendez-vous" subtitle={nom} onBack={() => router.back()} />

        <Card style={styles.bloc}>
          <View style={styles.avert}>
            <Ionicons name="information-circle-outline" size={18} color={colors.blue[600]} />
            <Text style={styles.avertTxt}>
              Paiement de démonstration : aucun débit réel n'est effectué.
            </Text>
          </View>
          {montantAPayer != null ? (
            <Text style={styles.montant}>Montant à payer : {formatFcfa(montantAPayer)}</Text>
          ) : null}
          <Text style={styles.label}>Mode de paiement</Text>
          <View style={styles.chips}>
            {MODES.map((m) => (
              <Chip
                key={m}
                label={LIBELLE_MODE_PAIEMENT[m]}
                selected={mode === m}
                onPress={() => setMode(m)}
              />
            ))}
          </View>
        </Card>

        {/* B4-b — chemin réel, à côté du chemin simulé ci-dessus. N'apparaît que si le serveur dit
            que cet établissement peut encaisser en ligne aujourd'hui (S7). */}
        {enLigneDisponible ? (
          <Card style={styles.bloc}>
            <Text style={styles.label}>Ou payez en ligne</Text>
            <Pressable
              onPress={payerEnLigne}
              disabled={ouvertureEnCours}
              accessibilityRole="button"
              style={styles.boutonEnLigne}
            >
              {ouvertureEnCours ? (
                <ActivityIndicator color="#FFFFFF" />
              ) : (
                <Text style={styles.boutonEnLigneTxt}>Payer en ligne (GeniusPay)</Text>
              )}
            </Pressable>
            <Text style={styles.avertTxt}>
              Vous serez redirigé vers votre navigateur pour finaliser le paiement, puis reviendrez
              ici. Une fois le paiement effectué, appuyez sur « Actualiser ».
            </Text>
            <Pressable onPress={() => void charger()} accessibilityRole="button" style={styles.reessayer}>
              <Text style={styles.reessayerTxt}>Actualiser</Text>
            </Pressable>
          </Card>
        ) : null}

        {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}
      </Screen>
    );
  }

  // Erreur (hors 404).
  if (erreur || !recu) {
    return (
      <Screen scroll={false}>
        <ScreenHeader title="Reçu de rendez-vous" subtitle={nom} onBack={() => router.back()} />
        <View style={styles.centre}>
          <Ionicons name="cloud-offline-outline" size={28} color={colors.danger.solid} />
          <Text style={styles.etatTxt}>{erreur ?? 'Reçu indisponible.'}</Text>
          <Pressable onPress={() => void charger()} accessibilityRole="button" style={styles.reessayer}>
            <Text style={styles.reessayerTxt}>Réessayer</Text>
          </Pressable>
        </View>
      </Screen>
    );
  }

  const st = STATUT_RECU[recu.statut] ?? STATUT_RECU.paye;

  return (
    <Screen>
      <ScreenHeader title="Reçu de rendez-vous" subtitle={nom} onBack={() => router.back()} />

      {/* QR de check-in */}
      <Card style={styles.qrCard}>
        <View style={styles.qrWrap}>
          <QrMasante valeur={recu.code} size={200} backgroundColor="#FFFFFF" />
        </View>
        <Text style={styles.qrNote}>
          À présenter à l'accueil. Ce code ne donne pas accès à votre dossier médical.
        </Text>
      </Card>

      {/* Détails du reçu */}
      <Card style={styles.bloc}>
        <View style={styles.haut}>
          <Text style={styles.reference}>{recu.reference}</Text>
          <View style={[styles.badge, { backgroundColor: st.bg }]}>
            <Text style={[styles.badgeTxt, { color: st.text }]}>{st.label}</Text>
          </View>
        </View>

        {recu.patient ? <Ligne icone="person-outline" texte={recu.patient} /> : null}
        {recu.structure ? <Ligne icone="business-outline" texte={`${recu.structure.nom} · ${recu.structure.commune}`} /> : null}
        {recu.service ? <Ligne icone="medkit-outline" texte={recu.service} /> : null}
        {recu.medecin ? <Ligne icone="person-circle-outline" texte={recu.medecin} /> : null}
        {recu.date ? <Ligne icone="calendar-outline" texte={formatDateFr(recu.date)} /> : null}
        {recu.montant != null ? (
          <Ligne
            icone="cash-outline"
            texte={`${formatFcfa(recu.montant)}${recu.mode ? ` · ${LIBELLE_MODE_PAIEMENT[recu.mode]}` : ''}`}
          />
        ) : null}
        {recu.transaction_ref ? (
          <Text style={styles.transaction}>Transaction : {recu.transaction_ref} (simulée)</Text>
        ) : null}
      </Card>
    </Screen>
  );
}

function Ligne({ icone, texte }: { icone: keyof typeof Ionicons.glyphMap; texte: string }) {
  return (
    <View style={styles.ligne}>
      <Ionicons name={icone} size={15} color={colors.ink[500]} />
      <Text style={styles.ligneTxt}>{texte}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  centre: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: spacing[3], paddingHorizontal: spacing[4] },
  bloc: { marginBottom: spacing[4], gap: spacing[3] },
  label: { ...typography.bodyStrong, color: colors.ink[700] },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing[2] },
  avert: { flexDirection: 'row', alignItems: 'center', gap: spacing[2] },
  avertTxt: { ...typography.caption, color: colors.blue[700], flex: 1 },
  montant: { ...typography.bodyStrong, color: colors.blue[900] },
  erreur: { ...typography.body, color: colors.danger.solid, textAlign: 'center' },
  qrCard: { marginBottom: spacing[4], alignItems: 'center', gap: spacing[3] },
  qrWrap: { padding: spacing[3], backgroundColor: '#FFFFFF', borderRadius: radius.card },
  qrNote: { ...typography.caption, color: colors.ink[500], textAlign: 'center' },
  haut: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing[2] },
  reference: { ...typography.bodyStrong, color: colors.blue[900] },
  badge: { paddingVertical: 4, paddingHorizontal: 10, borderRadius: radius.pill },
  badgeTxt: { ...typography.caption, fontWeight: '700' },
  ligne: { flexDirection: 'row', alignItems: 'center', gap: spacing[2] },
  ligneTxt: { ...typography.body, color: colors.ink[700], flex: 1 },
  transaction: { ...typography.caption, color: colors.ink[500] },
  etatTxt: { ...typography.body, color: colors.ink[700], textAlign: 'center' },
  reessayer: {
    marginTop: spacing[2],
    paddingHorizontal: spacing[5],
    height: 44,
    justifyContent: 'center',
    borderRadius: radius.pill,
    borderWidth: 1.5,
    borderColor: colors.blue[600],
  },
  reessayerTxt: { ...typography.button, color: colors.blue[600] },
  boutonEnLigne: {
    height: 48,
    borderRadius: radius.pill,
    backgroundColor: colors.blue[600],
    alignItems: 'center',
    justifyContent: 'center',
  },
  boutonEnLigneTxt: { ...typography.button, color: '#FFFFFF' },
});
