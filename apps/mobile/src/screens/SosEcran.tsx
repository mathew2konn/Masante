import React, { useCallback, useEffect, useState } from 'react';
import { Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../components/Screen';
import { Card } from '../components/Card';
import { ScreenHeader } from '../components/ScreenHeader';
import { lireCache } from '../urgence/carteVitale';
import { appelerSamu, contactAAlerter, envoyerSmsUrgence, messageUrgence, tracerAlerte } from '../urgence/sos';
import { obtenirPositionUrgence, type PositionUrgence } from '../utils/geoloc';
import { SAMU_NUMERO } from '../config/constants';
import type { FicheVitale } from '../types/urgence';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * Écran SOS (CdC FN1). Deux actions, jamais enchaînées automatiquement : appeler le SAMU, alerter
 * le contact d'urgence. On ne peut pas déclencher un appel ET un SMS d'un seul geste — le premier
 * met l'application en arrière-plan, le second serait perdu.
 *
 * RIEN N'ATTEND LE RÉSEAU. La position est cherchée en tâche de fond dès l'ouverture ; si elle
 * n'est pas encore là quand l'utilisateur appuie, on part sans elle. Les données du message
 * viennent du cache local de la carte vitale, donc l'écran fonctionne hors connexion.
 */
export function SosEcran({ onFermer }: { onFermer: () => void }) {
  const [fiches, setFiches] = useState<FicheVitale[]>([]);
  const [index, setIndex] = useState(0);
  const [position, setPosition] = useState<PositionUrgence | null>(null);
  const [rechercheGps, setRechercheGps] = useState(true);

  useEffect(() => {
    void lireCache().then(setFiches);

    // En parallèle, sans bloquer : l'appel au SAMU ne doit jamais attendre le GPS.
    void obtenirPositionUrgence().then((p) => {
      setPosition(p);
      setRechercheGps(false);
    });
  }, []);

  const fiche = fiches[index] ?? null;
  const contact = contactAAlerter(fiche);

  const appeler = useCallback(async () => {
    void tracerAlerte('appel', fiche, position, contact);
    try {
      await appelerSamu();
    } catch {
      Alert.alert('Appel impossible', `Composez vous-même le ${SAMU_NUMERO}.`);
    }
  }, [fiche, position, contact]);

  const alerterContact = useCallback(async () => {
    if (!contact) return;
    void tracerAlerte('sms', fiche, position, contact);
    try {
      await envoyerSmsUrgence(contact.telephone, messageUrgence(fiche, position));
    } catch {
      Alert.alert('SMS impossible', `Prévenez ${contact.nom} au ${contact.telephone}.`);
    }
  }, [fiche, position, contact]);

  return (
    <Screen>
      <ScreenHeader title="Urgence" onBack={onFermer} />

      {/* Choix du membre : un carnet est FAMILIAL, ce n'est pas toujours le titulaire qui fait
          le malaise. Affiché seulement si plusieurs cartes vitales sont activées. */}
      {fiches.length > 1 && (
        <Card style={styles.bloc}>
          <Text style={styles.label}>Qui est en urgence ?</Text>
          <View style={styles.choix}>
            {fiches.map((f, i) => (
              <Pressable
                key={f.membre_id}
                onPress={() => setIndex(i)}
                accessibilityRole="button"
                accessibilityState={{ selected: i === index }}
                style={[styles.puce, i === index && styles.puceActive]}
              >
                <Text style={[styles.puceTxt, i === index && styles.puceTxtActif]}>{f.prenom}</Text>
              </Pressable>
            ))}
          </View>
        </Card>
      )}

      <Pressable
        onPress={() => void appeler()}
        accessibilityRole="button"
        accessibilityLabel={`Appeler le SAMU au ${SAMU_NUMERO}`}
        style={({ pressed }) => [styles.samu, pressed && styles.samuPresse]}
      >
        <Ionicons name="call" size={24} color="#FFFFFF" />
        <Text style={styles.samuTxt}>Appeler le SAMU — {SAMU_NUMERO}</Text>
      </Pressable>

      <Card style={styles.bloc}>
        <Text style={styles.label}>Prévenir un proche</Text>
        {contact ? (
          <>
            <Text style={styles.corps}>
              {contact.nom} <Text style={styles.muted}>· {contact.lien}</Text>
            </Text>
            <Text style={styles.tel}>{contact.telephone}</Text>
            <Pressable
              onPress={() => void alerterContact()}
              accessibilityRole="button"
              accessibilityLabel={`Envoyer un SMS d'urgence à ${contact.nom}`}
              style={({ pressed }) => [styles.sms, pressed && styles.smsPresse]}
            >
              <Ionicons name="chatbubble-ellipses" size={20} color={colors.blue[700]} />
              <Text style={styles.smsTxt}>Envoyer le SMS d'urgence</Text>
            </Pressable>
            <Text style={styles.muted}>
              Le message contiendra l'identité, le groupe sanguin, les allergies, les traitements et la
              position. Vous garderez la main sur l'envoi.
            </Text>
          </>
        ) : (
          <Text style={styles.muted}>
            Aucun contact d'urgence enregistré. Activez la carte vitale d'un membre et renseignez ses
            contacts d'urgence pour pouvoir alerter un proche d'une seule touche.
          </Text>
        )}
      </Card>

      <View style={styles.gps}>
        <Ionicons
          name={position ? 'location' : 'location-outline'}
          size={16}
          color={position ? colors.success.solid : colors.ink[500]}
        />
        <Text style={styles.muted}>
          {position
            ? `Position trouvée (± ${position.precision != null ? Math.round(position.precision) : '?'} m)`
            : rechercheGps
              ? 'Recherche de votre position…'
              : 'Position indisponible — l\'alerte partira sans elle'}
        </Text>
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  bloc: { marginBottom: spacing[4], gap: spacing[2] },
  label: { ...typography.bodyStrong, color: colors.ink[700] },
  corps: { ...typography.body, color: colors.ink[900] },
  muted: { ...typography.caption, color: colors.ink[500] },
  tel: { ...typography.bodyStrong, color: colors.blue[700] },
  choix: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing[2] },
  puce: {
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[2],
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: colors.line,
    backgroundColor: colors.surface,
  },
  puceActive: { backgroundColor: colors.blue[600], borderColor: colors.blue[600] },
  puceTxt: { ...typography.bodyStrong, color: colors.ink[700] },
  puceTxtActif: { color: '#FFFFFF' },
  samu: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing[3],
    height: 64,
    borderRadius: radius.pill,
    backgroundColor: colors.danger.solid,
    marginBottom: spacing[5],
  },
  samuPresse: { backgroundColor: '#B91C1C' },
  samuTxt: { ...typography.button, color: '#FFFFFF' },
  sms: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing[2],
    minHeight: 48,
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: colors.blue[600],
    marginTop: spacing[2],
  },
  smsPresse: { backgroundColor: colors.surfaceMuted },
  smsTxt: { ...typography.bodyStrong, color: colors.blue[700] },
  gps: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: spacing[2] },
});
