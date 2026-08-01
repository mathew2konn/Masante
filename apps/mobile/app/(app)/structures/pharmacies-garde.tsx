import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../../src/components/Screen';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { StructureCard } from '../../../src/components/StructureCard';
import { getPharmaciesGarde } from '../../../src/api/structures';
import { obtenirPosition } from '../../../src/utils/geoloc';
import { messageErreur } from '../../../src/utils/erreurs';
import type { Coordonnees, Structure } from '../../../src/types/structure';
import { colors, radius, spacing, typography } from '../../../src/theme/theme';

/**
 * Pharmacies de garde du jour (Module 3 / 3B.4, F3.8).
 *
 * Liste publique (accès léger). « Près de moi » trie par proximité (géoloc foreground). Chaque
 * carte ouvre la fiche détaillée (téléphone, itinéraire…).
 */
export default function PharmaciesGarde() {
  const [position, setPosition] = useState<Coordonnees | null>(null);
  const [geoEnCours, setGeoEnCours] = useState(false);
  const [pharmacies, setPharmacies] = useState<Structure[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  const charger = useCallback(async () => {
    setChargement(true);
    setErreur(null);
    try {
      setPharmacies(await getPharmaciesGarde(position ?? undefined));
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, [position]);

  useEffect(() => {
    void charger();
  }, [charger]);

  async function basculerPosition() {
    if (position) {
      setPosition(null);
      return;
    }
    setGeoEnCours(true);
    const r = await obtenirPosition();
    setGeoEnCours(false);
    if (r.ok) setPosition(r.coords);
    else
      Alert.alert(
        'Position indisponible',
        r.raison === 'permission_refusee'
          ? "Autorisez l'accès à votre position pour trier par proximité."
          : "Impossible d'obtenir votre position.",
      );
  }

  return (
    <Screen>
      <ScreenHeader title="Pharmacies de garde" subtitle="Ouvertes aujourd'hui" onBack={() => router.back()} />

      <Pressable
        onPress={basculerPosition}
        accessibilityRole="button"
        accessibilityState={{ selected: !!position }}
        style={({ pressed }) => [
          styles.geo,
          { backgroundColor: position ? colors.blue[600] : colors.surface },
          pressed && { opacity: 0.7 },
        ]}
      >
        {geoEnCours ? (
          <ActivityIndicator size="small" color={position ? '#FFFFFF' : colors.blue[600]} />
        ) : (
          <Ionicons name="navigate" size={16} color={position ? '#FFFFFF' : colors.blue[600]} />
        )}
        <Text style={[styles.geoTxt, { color: position ? '#FFFFFF' : colors.blue[600] }]}>
          {position ? 'Proximité activée' : 'Près de moi'}
        </Text>
      </Pressable>

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
      ) : pharmacies.length === 0 ? (
        <View style={styles.centre}>
          <Ionicons name="medkit-outline" size={28} color={colors.ink[500]} />
          <Text style={styles.etatTxt}>Aucune pharmacie de garde renseignée aujourd'hui.</Text>
        </View>
      ) : (
        pharmacies.map((p) => (
          <StructureCard
            key={p.id}
            structure={p}
            onPress={() => router.push({ pathname: '/(app)/structures/[id]', params: { id: String(p.id) } })}
          />
        ))
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  geo: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: spacing[2],
    height: 44,
    paddingHorizontal: spacing[4],
    borderRadius: radius.pill,
    borderWidth: 1.5,
    borderColor: colors.blue[600],
    marginBottom: spacing[4],
  },
  geoTxt: { ...typography.caption, fontWeight: '700' },
  centre: { alignItems: 'center', gap: spacing[2], paddingVertical: spacing[10] },
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
