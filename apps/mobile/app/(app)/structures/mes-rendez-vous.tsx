import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, Image, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../../src/components/Screen';
import { Card } from '../../../src/components/Card';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { VerrouGate } from '../../../src/components/VerrouGate';
import { listerRendezVous, annulerRendezVous } from '../../../src/api/rendezvous';
import { API_URL } from '../../../src/config/api';
import { messageErreur } from '../../../src/utils/erreurs';
import { formatDateFr } from '../../../src/utils/dates';
import { LIBELLE_RDV, type RendezVous, type StatutRdv } from '../../../src/types/structure';
import { colors, radius, spacing, typography } from '../../../src/theme/theme';

/** Couleurs sémantiques du statut de RDV (réutilise les familles success/warning/danger). */
const COULEUR_RDV: Record<StatutRdv, { bg: string; text: string }> = {
  en_attente: { bg: colors.warning.bg, text: colors.warning.text },
  prevalide: { bg: colors.warning.bg, text: colors.warning.text },
  confirme: { bg: colors.success.bg, text: colors.success.text },
  honore: { bg: colors.blue[50], text: colors.blue[700] },
  refuse: { bg: colors.danger.bg, text: colors.danger.text },
  annule: { bg: colors.surfaceMuted, text: colors.ink[500] },
};

/**
 * Mes rendez-vous (Module 3 / 3B.4, F3.6).
 *
 * Liste les RDV de tous les membres du compte (les plus récents d'abord) avec leur statut.
 * Annulation possible tant que le RDV est « en attente » ou « confirmé ». Rafraîchi à chaque
 * focus pour refléter une demande tout juste créée.
 */
export default function MesRendezVous() {
  const [rdv, setRdv] = useState<RendezVous[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  const charger = useCallback(async () => {
    setChargement(true);
    setErreur(null);
    try {
      setRdv(await listerRendezVous());
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      void charger();
    }, [charger]),
  );

  function confirmerAnnulation(item: RendezVous) {
    Alert.alert('Annuler le rendez-vous ?', 'Cette action est définitive.', [
      { text: 'Non', style: 'cancel' },
      { text: 'Oui, annuler', style: 'destructive', onPress: () => void annuler(item.id) },
    ]);
  }

  async function annuler(id: number) {
    try {
      const maj = await annulerRendezVous(id);
      setRdv((liste) => liste.map((r) => (r.id === id ? { ...r, statut: maj.statut } : r)));
    } catch (e) {
      Alert.alert('Erreur', messageErreur(e));
    }
  }

  return (
    <VerrouGate>
    <Screen>
      <ScreenHeader title="Mes rendez-vous" onBack={() => router.back()} />

      {chargement ? (
        <View style={styles.centre}>
          <ActivityIndicator color={colors.blue[600]} />
        </View>
      ) : erreur ? (
        <View style={styles.centre}>
          <Ionicons name="cloud-offline-outline" size={28} color={colors.danger.solid} />
          <Text style={styles.etatTxt}>{erreur}</Text>
          <Pressable onPress={() => void charger()} accessibilityRole="button" style={styles.reessayer}>
            <Text style={styles.reessayerTxt}>Réessayer</Text>
          </Pressable>
        </View>
      ) : rdv.length === 0 ? (
        <View style={styles.centre}>
          <Ionicons name="calendar-outline" size={28} color={colors.ink[500]} />
          <Text style={styles.etatTxt}>Vous n'avez pas encore de rendez-vous.</Text>
        </View>
      ) : (
        rdv.map((item) => <LigneRdv key={item.id} item={item} onAnnuler={() => confirmerAnnulation(item)} />)
      )}
    </Screen>
    </VerrouGate>
  );
}

/** Formate un montant en FCFA avec séparateur de milliers (Hermes n'a pas toujours toLocaleString). */
function formatFcfa(montant: number): string {
  return `${montant.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ')} FCFA`;
}

function LigneRdv({ item, onAnnuler }: { item: RendezVous; onAnnuler: () => void }) {
  const couleur = COULEUR_RDV[item.statut];
  const annulable = item.statut === 'en_attente' || item.statut === 'prevalide' || item.statut === 'confirme';
  // Reçu/paiement disponible tant que le RDV n'est pas annulé/refusé.
  const reglable = item.statut !== 'annule' && item.statut !== 'refuse';
  // B1-b (D6) — proposer d'associer un triage tant que rien n'est encore attaché et que le RDV
  // n'est pas clos (l'associer à un RDV déjà honoré/refusé/annulé n'aurait plus de sens).
  const triageAssociable = item.triage_id == null && item.statut !== 'annule' && item.statut !== 'refuse' && item.statut !== 'honore';
  // B1-c (D9) — le suivi en direct n'a de sens que pendant la fenêtre où le médecin peut ouvrir
  // un accès partagé (§ PartageRdvService::ouvrir : RDV confirmé, patient enregistré à l'accueil).
  const suiviPossible = item.statut === 'confirme';
  return (
    <Card style={styles.card}>
      <View style={styles.haut}>
        <Text style={styles.nom} numberOfLines={2}>
          {item.structure?.nom ?? 'Structure'}
        </Text>
        <View style={[styles.badge, { backgroundColor: couleur.bg }]}>
          <Text style={[styles.badgeTxt, { color: couleur.text }]}>{LIBELLE_RDV[item.statut]}</Text>
        </View>
      </View>

      <Ligne icone="medkit-outline" texte={item.service?.nom_service ?? '—'} />
      {item.medecin ? (
        <View style={styles.ligne}>
          <PhotoMedecinMini url={item.medecin.photo_url ?? null} />
          <Text style={styles.ligneTxt}>
            {item.medecin.titre} {item.medecin.prenom} {item.medecin.nom}
            {item.medecin.numero_professionnel ? ` · n° ${item.medecin.numero_professionnel}` : ''}
          </Text>
        </View>
      ) : (
        <Ligne icone="person-circle-outline" texte="Médecin attribué par l'établissement" />
      )}
      <Ligne icone="person-outline" texte={item.membre ? `${item.membre.prenom} ${item.membre.nom}` : '—'} />
      <Ligne icone="calendar-outline" texte={`Souhaité le ${formatDateFr(item.date_souhaitee)}`} />
      {item.date_confirmee && <Ligne icone="checkmark-circle-outline" texte={`Confirmé le ${formatDateFr(item.date_confirmee)}`} />}
      {/* B1-b (D7) — le tarif est visible directement sur la fiche, plus besoin de naviguer vers
          le reçu pour le connaître. */}
      {item.tarif != null && <Ligne icone="cash-outline" texte={`Tarif : ${formatFcfa(item.tarif)}`} />}
      {item.motif ? <Text style={styles.motif}>« {item.motif} »</Text> : null}
      {item.motif_orientation ? <Text style={styles.message}>Orientation : {item.motif_orientation}</Text> : null}
      {item.message_agent ? <Text style={styles.message}>Réponse : {item.message_agent}</Text> : null}

      <View style={styles.actions}>
        {reglable && (
          <Pressable
            onPress={() =>
              router.push({
                pathname: '/(app)/structures/recu/[id]',
                params: {
                  id: String(item.id),
                  nom: item.structure?.nom ?? 'Structure',
                  ...(item.tarif != null ? { tarif: String(item.tarif) } : {}),
                },
              })
            }
            accessibilityRole="button"
            style={styles.action}
          >
            <Ionicons name="receipt-outline" size={16} color={colors.blue[600]} />
            <Text style={styles.actionTxt}>{item.regle ? 'Voir le reçu' : 'Payer'}</Text>
          </Pressable>
        )}
        {triageAssociable && (
          <Pressable
            onPress={() =>
              router.push({
                pathname: '/(app)/structures/associer-triage/[id]',
                params: { id: String(item.id), membreId: String(item.membre?.id ?? '') },
              })
            }
            accessibilityRole="button"
            style={styles.action}
          >
            <Ionicons name="clipboard-outline" size={16} color={colors.blue[600]} />
            <Text style={styles.actionTxt}>Associer un triage</Text>
          </Pressable>
        )}
        {suiviPossible && (
          <Pressable
            onPress={() => router.push({ pathname: '/(app)/structures/suivi-rdv/[id]', params: { id: String(item.id) } })}
            accessibilityRole="button"
            style={styles.action}
          >
            <Ionicons name="radio-outline" size={16} color={colors.blue[600]} />
            <Text style={styles.actionTxt}>Suivre en direct</Text>
          </Pressable>
        )}
        {annulable && (
          <Pressable onPress={onAnnuler} accessibilityRole="button" style={styles.annuler}>
            <Ionicons name="close-circle-outline" size={16} color={colors.danger.solid} />
            <Text style={styles.annulerTxt}>Annuler</Text>
          </Pressable>
        )}
      </View>
    </Card>
  );
}

/**
 * Miniature de la photo du médecin (B1-b / D5). URL relative composée avec `API_URL`, comme
 * `ImageEtablissementView` (P6.4c) — un seul endroit sait le faire, et l'échec (hors ligne,
 * fichier introuvable) retombe sur l'icône plutôt qu'un rectangle vide.
 */
function PhotoMedecinMini({ url }: { url: string | null }) {
  const [echec, setEchec] = useState(false);

  if (!url || echec) {
    return <Ionicons name="person-circle-outline" size={15} color={colors.ink[500]} />;
  }

  return (
    <Image
      source={{ uri: `${API_URL}${url}` }}
      style={styles.photoMedecin}
      onError={() => setEchec(true)}
      accessibilityLabel="Photo du médecin"
    />
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
  centre: { alignItems: 'center', gap: spacing[2], paddingVertical: spacing[10] },
  etatTxt: { ...typography.body, color: colors.ink[700], textAlign: 'center' },
  card: { marginBottom: spacing[3], gap: 6 },
  haut: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', gap: spacing[2], marginBottom: 2 },
  nom: { ...typography.bodyStrong, color: colors.blue[900], flex: 1 },
  badge: { paddingVertical: 4, paddingHorizontal: 10, borderRadius: radius.pill },
  badgeTxt: { ...typography.caption, fontWeight: '700' },
  ligne: { flexDirection: 'row', alignItems: 'center', gap: spacing[2] },
  photoMedecin: { width: 15, height: 15, borderRadius: 8 },
  ligneTxt: { ...typography.body, color: colors.ink[700], flex: 1 },
  motif: { ...typography.body, color: colors.ink[900], fontStyle: 'italic', marginTop: 2 },
  message: { ...typography.caption, color: colors.ink[700], marginTop: 2 },
  actions: { flexDirection: 'row', alignItems: 'center', gap: spacing[4], marginTop: spacing[2] },
  action: { flexDirection: 'row', alignItems: 'center', gap: spacing[1], minHeight: 44 },
  actionTxt: { ...typography.bodyStrong, color: colors.blue[600] },
  annuler: { flexDirection: 'row', alignItems: 'center', gap: spacing[1], minHeight: 44 },
  annulerTxt: { ...typography.bodyStrong, color: colors.danger.solid },
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
});
