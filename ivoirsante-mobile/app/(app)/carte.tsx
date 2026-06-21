import React, { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Platform,
  Pressable,
  SafeAreaView,
  ScrollView,
  StatusBar,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { StatusBar as ExpoStatusBar } from 'expo-status-bar';
import { Ionicons } from '@expo/vector-icons';
import { GradientBackground } from '../../src/components/GradientBackground';
import { ScreenHeader } from '../../src/components/ScreenHeader';
import { StructureCard } from '../../src/components/StructureCard';
import { rechercherStructures } from '../../src/api/structures';
import { obtenirPosition } from '../../src/utils/geoloc';
import { messageErreur } from '../../src/utils/erreurs';
import {
  COMMUNES,
  LIBELLE_TYPE,
  type Coordonnees,
  type FiltresStructure,
  type Structure,
  type TypeStructure,
} from '../../src/types/structure';
import { colors, radius, spacing, typography } from '../../src/theme/theme';

const TYPES = Object.keys(LIBELLE_TYPE) as TypeStructure[];

/**
 * Onglet « Carte » — Module 3 / 3B.1 : annuaire géolocalisé des structures, vue LISTE.
 *
 * Recherche texte + filtres (type, commune) + « près de moi » (géoloc au premier plan → tri par
 * proximité, F3.2/F3.3). La carte OSM/Leaflet en WebView arrive en 3B.2 ; la fiche en 3B.3.
 * Données publiques non sensibles (accès léger sans identité, doc Identification).
 */
export default function CarteTab() {
  const [q, setQ] = useState('');
  const [type, setType] = useState<TypeStructure | null>(null);
  const [commune, setCommune] = useState<string | null>(null);
  const [position, setPosition] = useState<Coordonnees | null>(null);
  const [geoEnCours, setGeoEnCours] = useState(false);
  const [structures, setStructures] = useState<Structure[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  const charger = useCallback(async () => {
    setChargement(true);
    setErreur(null);
    try {
      const filtres: FiltresStructure = {};
      if (q.trim()) filtres.q = q.trim();
      if (type) filtres.type = type;
      if (commune) filtres.commune = commune;
      if (position) {
        filtres.lat = position.lat;
        filtres.lng = position.lng;
      }
      setStructures(await rechercherStructures(filtres));
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, [q, type, commune, position]);

  // Recherche debouncée : on évite une requête à chaque frappe.
  useEffect(() => {
    const t = setTimeout(() => {
      void charger();
    }, 350);
    return () => clearTimeout(t);
  }, [charger]);

  async function basculerPosition() {
    if (position) {
      setPosition(null);
      return;
    }
    setGeoEnCours(true);
    const r = await obtenirPosition();
    setGeoEnCours(false);
    if (r.ok) {
      setPosition(r.coords);
    } else {
      Alert.alert(
        'Position indisponible',
        r.raison === 'permission_refusee'
          ? "Autorisez l'accès à votre position pour trier les structures par proximité."
          : "Impossible d'obtenir votre position pour le moment.",
      );
    }
  }

  const topPad = Platform.OS === 'android' ? (StatusBar.currentHeight ?? 0) : 0;

  return (
    <GradientBackground>
      <ExpoStatusBar style="dark" />
      <SafeAreaView style={[styles.safe, { paddingTop: topPad }]}>
        <FlatList
          data={structures}
          keyExtractor={(s) => String(s.id)}
          renderItem={({ item }) => <StructureCard structure={item} />}
          contentContainerStyle={styles.liste}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
          ListHeaderComponent={
            <View>
              <ScreenHeader title="Structures de santé" subtitle="Trouvez un établissement près de vous" />

              {/* Recherche (§5.3 : loupe + champ) */}
              <View style={styles.recherche}>
                <Ionicons name="search" size={18} color={colors.ink[500]} />
                <TextInput
                  value={q}
                  onChangeText={setQ}
                  placeholder="Rechercher un nom d'établissement"
                  placeholderTextColor={colors.ink[500]}
                  style={styles.rechercheInput}
                  accessibilityLabel="Rechercher une structure par nom"
                  returnKeyType="search"
                />
                {q.length > 0 && (
                  <Pressable onPress={() => setQ('')} hitSlop={8} accessibilityLabel="Effacer la recherche">
                    <Ionicons name="close-circle" size={18} color={colors.ink[500]} />
                  </Pressable>
                )}
              </View>

              {/* Près de moi (géoloc) */}
              <Pressable
                onPress={basculerPosition}
                accessibilityRole="button"
                accessibilityState={{ selected: !!position }}
                accessibilityLabel="Trier par proximité avec ma position"
                style={({ pressed }) => [
                  styles.geo,
                  { backgroundColor: position ? colors.blue[600] : colors.surface },
                  pressed && styles.presse,
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

              {/* Filtre type */}
              <ScrollView
                horizontal
                showsHorizontalScrollIndicator={false}
                contentContainerStyle={styles.filtres}
                keyboardShouldPersistTaps="handled"
              >
                <FiltreChip label="Tous" actif={type === null} onPress={() => setType(null)} />
                {TYPES.map((t) => (
                  <FiltreChip key={t} label={LIBELLE_TYPE[t]} actif={type === t} onPress={() => setType(t)} />
                ))}
              </ScrollView>

              {/* Filtre commune */}
              <ScrollView
                horizontal
                showsHorizontalScrollIndicator={false}
                contentContainerStyle={styles.filtres}
                keyboardShouldPersistTaps="handled"
              >
                <FiltreChip label="Toutes communes" actif={commune === null} onPress={() => setCommune(null)} />
                {COMMUNES.map((c) => (
                  <FiltreChip key={c} label={c} actif={commune === c} onPress={() => setCommune(c)} />
                ))}
              </ScrollView>

              {/* État de la requête */}
              {chargement && (
                <View style={styles.etat}>
                  <ActivityIndicator color={colors.blue[600]} />
                </View>
              )}
              {!chargement && erreur && (
                <View style={styles.etat}>
                  <Ionicons name="cloud-offline-outline" size={28} color={colors.danger.solid} />
                  <Text style={styles.etatTxt}>{erreur}</Text>
                  <Pressable onPress={() => void charger()} accessibilityRole="button" style={styles.reessayer}>
                    <Text style={styles.reessayerTxt}>Réessayer</Text>
                  </Pressable>
                </View>
              )}
            </View>
          }
          ListEmptyComponent={
            !chargement && !erreur ? (
              <View style={styles.etat}>
                <Ionicons name="search-outline" size={28} color={colors.ink[500]} />
                <Text style={styles.etatTxt}>Aucune structure ne correspond à votre recherche.</Text>
              </View>
            ) : null
          }
        />
      </SafeAreaView>
    </GradientBackground>
  );
}

/** Puce de filtre compacte (variante horizontale de la Chip §5.7). */
function FiltreChip({ label, actif, onPress }: { label: string; actif: boolean; onPress: () => void }) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityState={{ selected: actif }}
      accessibilityLabel={label}
      style={({ pressed }) => [
        styles.chip,
        {
          borderColor: actif ? colors.blue[600] : colors.line,
          backgroundColor: actif ? colors.blue[50] : pressed ? colors.surfaceMuted : colors.surface,
        },
      ]}
    >
      <Text style={[styles.chipTxt, { color: actif ? colors.blue[700] : colors.ink[700] }, actif && styles.chipTxtActif]}>
        {label}
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  liste: { paddingHorizontal: spacing[6], paddingTop: spacing[5], paddingBottom: spacing[8] },
  recherche: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[2],
    backgroundColor: colors.surfaceMuted,
    borderRadius: radius.sm,
    borderWidth: 1,
    borderColor: colors.line,
    paddingHorizontal: spacing[3],
    minHeight: 48,
    marginBottom: spacing[3],
  },
  rechercheInput: { flex: 1, ...typography.body, color: colors.ink[900] },
  geo: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: spacing[2],
    height: 40,
    paddingHorizontal: spacing[4],
    borderRadius: radius.pill,
    borderWidth: 1.5,
    borderColor: colors.blue[600],
    marginBottom: spacing[3],
  },
  geoTxt: { ...typography.caption, fontWeight: '700' },
  presse: { opacity: 0.7 },
  filtres: { gap: spacing[2], paddingVertical: spacing[1], paddingRight: spacing[4] },
  chip: {
    minHeight: 40,
    justifyContent: 'center',
    borderWidth: 1.5,
    borderRadius: radius.lg,
    paddingHorizontal: spacing[3],
  },
  chipTxt: { ...typography.caption, fontWeight: '600' },
  chipTxtActif: { fontWeight: '700' },
  etat: { alignItems: 'center', gap: spacing[2], paddingVertical: spacing[8] },
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
