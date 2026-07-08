import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../../src/components/Screen';
import { Card } from '../../../src/components/Card';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { PastilleDispo } from '../../../src/components/PastilleDispo';
import { PrimaryButton } from '../../../src/components/PrimaryButton';
import { getStructure, getAvisStructure } from '../../../src/api/structures';
import { messageErreur } from '../../../src/utils/erreurs';
import { formatDateFr, heureCourte } from '../../../src/utils/dates';
import { LIBELLE_TYPE, type Avis, type Service, type Structure } from '../../../src/types/structure';
import { colors, radius, spacing, typography } from '../../../src/theme/theme';

/** Libellés lisibles des clés d'horaires (horaires_json). */
const LIBELLE_HORAIRE: Record<string, string> = {
  lun_ven: 'Lun – Ven',
  weekend: 'Week-end',
  samedi: 'Samedi',
  dimanche: 'Dimanche',
};

/**
 * Fiche détaillée d'une structure (Module 3 / 3B.3, F3.5/F3.9).
 *
 * Lecture publique (accès léger sans identité). Affiche coordonnées, horaires, tarifs, services
 * et leur disponibilité du jour, avis patients. Actions : Appeler / WhatsApp / Itinéraire (OSRM).
 * Le dépôt d'avis, signalement et demande de RDV arrivent en 3B.4 — non anticipés ici.
 */
export default function FicheStructure() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const structureId = Number(id);

  const [structure, setStructure] = useState<Structure | null>(null);
  const [avis, setAvis] = useState<Avis[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  const charger = useCallback(async () => {
    setChargement(true);
    setErreur(null);
    try {
      const [s, a] = await Promise.all([getStructure(structureId), getAvisStructure(structureId)]);
      setStructure(s);
      setAvis(a);
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, [structureId]);

  // Rafraîchi à chaque focus : un avis tout juste déposé apparaît au retour sur la fiche.
  useFocusEffect(
    useCallback(() => {
      void charger();
    }, [charger]),
  );

  const allerVers = (
    page: 'avis' | 'signaler' | 'rendez-vous-nouveau',
    s: { id: number; nom: string },
  ) => router.push({ pathname: `/(app)/structures/${page}`, params: { id: String(s.id), nom: s.nom } });

  if (chargement) {
    return (
      <Screen scroll={false}>
        <ScreenHeader title="Structure" onBack={() => router.back()} />
        <View style={styles.centre}>
          <ActivityIndicator color={colors.blue[600]} />
        </View>
      </Screen>
    );
  }

  if (erreur || !structure) {
    return (
      <Screen scroll={false}>
        <ScreenHeader title="Structure" onBack={() => router.back()} />
        <View style={styles.centre}>
          <Ionicons name="cloud-offline-outline" size={28} color={colors.danger.solid} />
          <Text style={styles.etatTxt}>{erreur ?? 'Structure introuvable.'}</Text>
          <Pressable onPress={() => void charger()} accessibilityRole="button" style={styles.reessayer}>
            <Text style={styles.reessayerTxt}>Réessayer</Text>
          </Pressable>
        </View>
      </Screen>
    );
  }

  const horaires = structure.horaires_json ? Object.entries(structure.horaires_json) : [];
  const services = structure.services ?? [];
  // F3.4 (c) : si l'établissement est complet aujourd'hui, on propose automatiquement un RDV.
  const estComplet = structure.statut_jour === 'complet';

  return (
    <Screen>
      <ScreenHeader title={structure.nom} onBack={() => router.back()} />

      {/* Résumé */}
      <Card style={styles.bloc}>
        <Text style={styles.meta}>
          {LIBELLE_TYPE[structure.type]} · {structure.commune}
        </Text>
        <View style={styles.resumeLigne}>
          <PastilleDispo statut={structure.statut_jour} />
          {structure.note_moyenne != null && (
            <View style={styles.note}>
              <Ionicons name="star" size={15} color={colors.warning.solid} />
              <Text style={styles.noteTxt}>
                {structure.note_moyenne.toFixed(1)} · {structure.nb_avis} avis
              </Text>
            </View>
          )}
        </View>
        {structure.partenaire_ivoirsante && (
          <View style={styles.partenaire}>
            <Ionicons name="shield-checkmark" size={14} color={colors.blue[700]} />
            <Text style={styles.partenaireTxt}>Partenaire IVOIRSANTÉ</Text>
          </View>
        )}
      </Card>

      {/* F3.4 (c) — Établissement complet : proposition automatique de rendez-vous */}
      {estComplet && (
        <Card style={styles.complet}>
          <View style={styles.completHaut}>
            <Ionicons name="alert-circle" size={20} color={colors.danger.solid} />
            <Text style={styles.completTitre}>Complet aujourd'hui</Text>
          </View>
          <Text style={styles.completTxt}>
            Cet établissement affiche complet. Prenez un rendez-vous pour être reçu sans attendre.
          </Text>
          <PrimaryButton
            label="Prendre un rendez-vous"
            onPress={() => allerVers('rendez-vous-nouveau', structure)}
          />
        </Card>
      )}

      {/* Actions */}
      <View style={styles.actions}>
        {structure.telephone && (
          <ActionBtn icone="call" label="Appeler" onPress={() => void Linking.openURL(`tel:${structure.telephone}`)} />
        )}
        {structure.whatsapp && (
          <ActionBtn
            icone="logo-whatsapp"
            label="WhatsApp"
            onPress={() => void Linking.openURL(`https://wa.me/${structure.whatsapp!.replace(/\D/g, '')}`)}
          />
        )}
        <ActionBtn
          icone="navigate"
          label="Itinéraire"
          onPress={() =>
            router.push({
              pathname: '/(app)/structures/itineraire',
              params: {
                id: String(structure.id),
                lat: String(structure.latitude),
                lng: String(structure.longitude),
                nom: structure.nom,
              },
            })
          }
        />
      </View>

      {/* Demande de rendez-vous (F3.6) — masquée si complet : la bannière ci-dessus porte déjà le CTA. */}
      {!estComplet && (
        <View style={styles.rdvBtn}>
          <PrimaryButton
            label="Demander un rendez-vous"
            onPress={() => allerVers('rendez-vous-nouveau', structure)}
          />
        </View>
      )}

      {/* Coordonnées */}
      {structure.adresse && (
        <Card style={styles.bloc}>
          <Titre icone="location-outline" texte="Adresse" />
          <Text style={styles.corps}>{structure.adresse}</Text>
          {structure.telephone && <Text style={styles.corpsMuted}>Tél. {structure.telephone}</Text>}
        </Card>
      )}

      {/* Horaires */}
      {horaires.length > 0 && (
        <Card style={styles.bloc}>
          <Titre icone="time-outline" texte="Horaires" />
          {horaires.map(([cle, valeur]) => (
            <View key={cle} style={styles.ligne}>
              <Text style={styles.ligneLabel}>{LIBELLE_HORAIRE[cle] ?? cle}</Text>
              <Text style={styles.ligneVal}>{valeur}</Text>
            </View>
          ))}
        </Card>
      )}

      {/* Tarifs */}
      {(structure.tarif_min_cfa != null || structure.tarif_max_cfa != null) && (
        <Card style={styles.bloc}>
          <Titre icone="cash-outline" texte="Tarifs indicatifs" />
          <Text style={styles.corps}>{formatTarif(structure.tarif_min_cfa, structure.tarif_max_cfa)}</Text>
        </Card>
      )}

      {/* Services */}
      {services.length > 0 && (
        <Card style={styles.bloc}>
          <Titre icone="medkit-outline" texte="Services" />
          {services.map((s) => (
            <ServiceLigne key={s.id} service={s} />
          ))}
        </Card>
      )}

      {/* Avis */}
      <Card style={styles.bloc}>
        <View style={styles.avisEntete}>
          <Titre icone="chatbubble-ellipses-outline" texte={`Avis (${avis.length})`} />
          <Pressable onPress={() => allerVers('avis', structure)} accessibilityRole="button" hitSlop={6}>
            <Text style={styles.lien}>Donner mon avis</Text>
          </Pressable>
        </View>
        {avis.length === 0 ? (
          <Text style={styles.corpsMuted}>Aucun avis pour le moment.</Text>
        ) : (
          avis.map((a) => <AvisLigne key={a.id} avis={a} />)
        )}
      </Card>

      {/* Signalement (F3.10) */}
      <Pressable
        onPress={() => allerVers('signaler', structure)}
        accessibilityRole="button"
        style={styles.signaler}
      >
        <Ionicons name="flag-outline" size={16} color={colors.danger.solid} />
        <Text style={styles.signalerTxt}>Signaler un problème</Text>
      </Pressable>
    </Screen>
  );
}

/** Bouton d'action carré (Appeler / WhatsApp / Itinéraire). */
function ActionBtn({
  icone,
  label,
  onPress,
}: {
  icone: keyof typeof Ionicons.glyphMap;
  label: string;
  onPress: () => void;
}) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={label}
      style={({ pressed }) => [styles.action, pressed && styles.actionPresse]}
    >
      <Ionicons name={icone} size={22} color={colors.blue[600]} />
      <Text style={styles.actionTxt}>{label}</Text>
    </Pressable>
  );
}

/** Titre de section avec icône. */
function Titre({ icone, texte }: { icone: keyof typeof Ionicons.glyphMap; texte: string }) {
  return (
    <View style={styles.titre}>
      <Ionicons name={icone} size={18} color={colors.blue[700]} />
      <Text style={styles.titreTxt}>{texte}</Text>
    </View>
  );
}

/** Ligne d'un service : nom + pastille de dispo du jour + détails éventuels. */
function ServiceLigne({ service }: { service: Service }) {
  const dispo = service.disponibilites?.[0];
  const statut = dispo?.statut ?? 'inconnu';
  return (
    <View style={styles.service}>
      <View style={styles.serviceHaut}>
        <Text style={styles.serviceNom}>{service.nom_service}</Text>
        <PastilleDispo statut={statut} />
      </View>
      {dispo && (dispo.heure_debut_dispo || dispo.nb_places_restantes != null || dispo.note) && (
        <Text style={styles.serviceDetail}>
          {[
            dispo.heure_debut_dispo ? `à partir de ${heureCourte(dispo.heure_debut_dispo)}` : null,
            dispo.nb_places_restantes != null ? `${dispo.nb_places_restantes} place(s)` : null,
            dispo.note,
          ]
            .filter(Boolean)
            .join(' · ')}
        </Text>
      )}
    </View>
  );
}

/** Ligne d'un avis : étoiles + commentaire + auteur/date + badge « vérifié ». */
function AvisLigne({ avis }: { avis: Avis }) {
  return (
    <View style={styles.avis}>
      <View style={styles.avisHaut}>
        <Etoiles note={avis.note} />
        {avis.consultation_verifiee && (
          <View style={styles.verifie}>
            <Ionicons name="checkmark-circle" size={13} color={colors.success.solid} />
            <Text style={styles.verifieTxt}>Consultation vérifiée</Text>
          </View>
        )}
      </View>
      {avis.commentaire ? <Text style={styles.corps}>{avis.commentaire}</Text> : null}
      <Text style={styles.avisMeta}>
        {avis.auteur} · {formatDateFr(avis.created_at)}
      </Text>
    </View>
  );
}

/** Affichage des 5 étoiles (note 1-5). */
function Etoiles({ note }: { note: number }) {
  return (
    <View style={styles.etoiles} accessibilityLabel={`Note ${note} sur 5`}>
      {[1, 2, 3, 4, 5].map((n) => (
        <Ionicons
          key={n}
          name={n <= note ? 'star' : 'star-outline'}
          size={15}
          color={colors.warning.solid}
        />
      ))}
    </View>
  );
}

/** Tarif formaté avec séparateurs de milliers (espace) + FCFA. */
function formatTarif(min: number | null, max: number | null): string {
  const f = (n: number) => n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  if (min != null && max != null) return `${f(min)} – ${f(max)} FCFA`;
  if (min != null) return `À partir de ${f(min)} FCFA`;
  return `Jusqu'à ${f(max as number)} FCFA`;
}

const styles = StyleSheet.create({
  centre: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: spacing[2] },
  bloc: { marginBottom: spacing[4], gap: spacing[2] },
  meta: { ...typography.caption, color: colors.ink[500] },
  resumeLigne: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: spacing[2] },
  note: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  noteTxt: { ...typography.caption, color: colors.ink[700], fontWeight: '600' },
  partenaire: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  partenaireTxt: { ...typography.caption, color: colors.blue[700], fontWeight: '600' },
  actions: { flexDirection: 'row', gap: spacing[3], marginBottom: spacing[4] },
  action: {
    flex: 1,
    minHeight: 64,
    borderRadius: radius.lg,
    backgroundColor: colors.surface,
    borderWidth: 1.5,
    borderColor: colors.blue[600],
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4,
  },
  actionPresse: { backgroundColor: colors.blue[50] },
  actionTxt: { ...typography.caption, color: colors.blue[600], fontWeight: '700' },
  complet: { marginBottom: spacing[4], gap: spacing[2], backgroundColor: colors.danger.bg, borderWidth: 1, borderColor: colors.danger.solid },
  completHaut: { flexDirection: 'row', alignItems: 'center', gap: spacing[2] },
  completTitre: { ...typography.bodyStrong, color: colors.danger.text },
  completTxt: { ...typography.body, color: colors.danger.text },
  rdvBtn: { marginBottom: spacing[4] },
  avisEntete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  lien: { ...typography.caption, color: colors.blue[600], fontWeight: '700' },
  signaler: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: spacing[2], minHeight: 48, marginBottom: spacing[2] },
  signalerTxt: { ...typography.bodyStrong, color: colors.danger.solid },
  titre: { flexDirection: 'row', alignItems: 'center', gap: spacing[2], marginBottom: spacing[1] },
  titreTxt: { ...typography.h2, color: colors.blue[900] },
  corps: { ...typography.body, color: colors.ink[900] },
  corpsMuted: { ...typography.body, color: colors.ink[500] },
  ligne: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 2 },
  ligneLabel: { ...typography.body, color: colors.ink[700] },
  ligneVal: { ...typography.bodyStrong, color: colors.ink[900] },
  service: { paddingVertical: spacing[2], borderTopWidth: 1, borderTopColor: colors.line, gap: 4 },
  serviceHaut: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing[2] },
  serviceNom: { ...typography.bodyStrong, color: colors.ink[900], flexShrink: 1 },
  serviceDetail: { ...typography.caption, color: colors.ink[500] },
  avis: { paddingVertical: spacing[3], borderTopWidth: 1, borderTopColor: colors.line, gap: 4 },
  avisHaut: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: spacing[2] },
  etoiles: { flexDirection: 'row', gap: 1 },
  verifie: { flexDirection: 'row', alignItems: 'center', gap: 3 },
  verifieTxt: { ...typography.caption, color: colors.success.text, fontWeight: '600' },
  avisMeta: { ...typography.caption, color: colors.ink[500] },
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
});
