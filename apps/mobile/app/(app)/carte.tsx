import React, { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { StatusBar as ExpoStatusBar } from 'expo-status-bar';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { GradientBackground } from '../../src/components/GradientBackground';
import { BanniereHorsLigne } from '../../src/components/BanniereHorsLigne';
import { ScreenHeader } from '../../src/components/ScreenHeader';
import { StructureCard } from '../../src/components/StructureCard';
import { Segmented } from '../../src/components/Segmented';
import { MapWebView } from '../../src/components/MapWebView';
import { useReseau } from '../../src/store/reseau';
import { rechercherStructures } from '../../src/api/structures';
import { obtenirPosition } from '../../src/utils/geoloc';
import { messageErreur } from '../../src/utils/erreurs';
import {
  BUDGETS,
  libelleType,
  type Coordonnees,
  type FiltresStructure,
  type Structure,
  type TypeStructure,
} from '../../src/types/structure';
import { colors, radius, spacing, typography } from '../../src/theme/theme';
import { useLocalisation } from '../../src/store/localisation';



type Vue = 'liste' | 'carte';

/**
 * Onglet « Carte » — Module 3 / 3B : annuaire géolocalisé des structures.
 *
 * 3B.1 : vue LISTE (recherche + filtres type/commune + « près de moi »).
 * 3B.2 : bascule LISTE / CARTE — carte OSM/Leaflet en WebView (marqueurs colorés par
 *        disponibilité, recentrage sur la position). Tap sur un marqueur → aperçu de la structure.
 * La fiche détaillée (navigation) arrive en 3B.3 : ici l'aperçu n'est pas encore cliquable.
 */
export default function CarteTab() {
  const [q, setQ] = useState('');
  const [type, setType] = useState<TypeStructure | null>(null);
  const [commune, setCommune] = useState<string | null>(null);
  // Budget max (F3.2). null = « Tous tarifs ».
  const [tarifMax, setTarifMax] = useState<number | null>(null);
  const [position, setPosition] = useState<Coordonnees | null>(null);
  const [geoEnCours, setGeoEnCours] = useState(false);
  const [vue, setVue] = useState<Vue>('liste');
  const [selection, setSelection] = useState<Structure | null>(null);
  const [structures, setStructures] = useState<Structure[]>([]);
  // Bandeau « vous êtes à X » : replié par défaut, déplié au tap sur l'icône (demande du
  // propriétaire du 2026-08-13 — « lorsqu'il clique dessus on affiche juste en bas »).
  const [villeDepliee, setVilleDepliee] = useState(false);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);
  const horsLigne = useReseau((e) => e.horsLigne);

  // P6.4b — TOUT vient du serveur : la ville, si elle affiche des communes, lesquelles, et les
  // libellés de catégorie. L'écran n'en déduit aucun : ouvrir une quatrième ville ne doit pas
  // demander de republier l'application.
  const villeCourante = useLocalisation((e) => e.ville);
  const sourceVille = useLocalisation((e) => e.source);
  const horsZone = useLocalisation((e) => e.horsZone);
  const communesDisponibles = useLocalisation((e) => e.communes);
  const villesParProximite = useLocalisation((e) => e.villesParProximite);
  const villesCouvertes = useLocalisation((e) => e.villes);
  const typesEtablissement = useLocalisation((e) => e.typesEtablissement);
  const choixRequis = useLocalisation((e) => e.choixRequis);
  const choisirVille = useLocalisation((e) => e.choisirVille);

  // L'initialisation appartient à `(app)/_layout.tsx` : elle explique AVANT de demander la
  // permission. La déclencher aussi ici ferait surgir l'invite du système par-dessus
  // l'explication — c'est-à-dire exactement le refus réflexe qu'on cherche à éviter.

  // Le filtre de commune n'a de sens que là où le serveur en annonce. Changer de ville en laisse
  // un obsolète : on le retire, sinon la liste resterait vide sans explication.
  useEffect(() => {
    if (!communesDisponibles.includes(commune ?? '')) setCommune(null);
  }, [communesDisponibles, commune]);

  const charger = useCallback(async () => {
    setChargement(true);
    setErreur(null);
    try {
      const filtres: FiltresStructure = {};
      if (q.trim()) filtres.q = q.trim();
      if (type) filtres.type = type;
      if (commune) filtres.commune = commune;
      // Hors zone, on ne restreint à aucune ville : l'utilisateur voit TOUTES les structures,
      // ordonnées depuis la ville la plus proche (décision V6).
      if (villeCourante && !horsZone) filtres.ville = villeCourante.code;
      if (tarifMax !== null) filtres.tarif_max = tarifMax;
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
  }, [q, type, commune, tarifMax, position, villeCourante, horsZone]);

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

  // L'aperçu sélectionné sur la carte doit rester cohérent avec la liste filtrée courante.
  const choisirSurCarte = useCallback(
    (id: number) => setSelection(structures.find((s) => s.id === id) ?? null),
    [structures],
  );

  // Ouvre la fiche détaillée (3B.3).
  const ouvrirFiche = useCallback(
    (id: number) => router.push({ pathname: '/(app)/structures/[id]', params: { id: String(id) } }),
    [],
  );

  // Si la structure en aperçu disparaît du résultat filtré, on retire l'aperçu.
  useEffect(() => {
    setSelection((s) => (s && structures.some((x) => x.id === s.id) ? s : null));
  }, [structures]);

  return (
    <GradientBackground>
      <ExpoStatusBar style="dark" />
      <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
        <BanniereHorsLigne />
        {/* En-tête fixe : recherche + filtres + bascule de vue */}
        <View style={styles.header}>
          <ScreenHeader title="Structures de santé" subtitle="Trouvez un établissement près de vous" />

          {/*
            P6.4b — Ville courante. L'icône de géolocalisation porte le nom de la ville ; au tap,
            la phrase complète s'affiche JUSTE EN DESSOUS (demande du propriétaire).

            La phrase change selon la SOURCE, et ce n'est pas cosmétique : « Vous êtes à X » est
            une affirmation. On ne la prononce que lorsque la position a réellement répondu — une
            ville choisie à la main ou ressortie de la mémoire hors ligne se dit autrement.
          */}
          {villeCourante && (
            <View>
              <Pressable
                onPress={() => setVilleDepliee((v) => !v)}
                accessibilityRole="button"
                accessibilityState={{ expanded: villeDepliee }}
                accessibilityLabel={`Ville : ${villeCourante.nom}. Toucher pour en savoir plus.`}
                style={({ pressed }) => [styles.villeChip, pressed && styles.presse]}
              >
                <Ionicons name="location" size={15} color={colors.blue[600]} />
                <Text style={styles.villeNom}>{villeCourante.nom}</Text>
                <Ionicons
                  name={villeDepliee ? 'chevron-up' : 'chevron-down'}
                  size={14}
                  color={colors.ink[500]}
                />
              </Pressable>

              {villeDepliee && (
                <Text style={styles.villePhrase}>
                  {sourceVille === 'position'
                    ? `Vous êtes à ${villeCourante.nom}`
                    : sourceVille === 'choix'
                      ? `Ville choisie : ${villeCourante.nom}`
                      : `Dernière position connue : ${villeCourante.nom}`}
                </Text>
              )}
            </View>
          )}

          {/*
            Hors des villes couvertes. On le DIT, et on montre tout de même toutes les structures
            en commençant par la ville la plus proche — plutôt que de rattacher l'utilisateur à
            une ville où il n'est pas.
          */}
          {horsZone && (
            <View style={styles.horsZone}>
              <Ionicons name="information-circle-outline" size={16} color={colors.ink[500]} />
              <Text style={styles.horsZoneTxt}>
                Vous êtes hors des zones couvertes.
                {villesParProximite[0]
                  ? ` La ville la plus proche est ${villesParProximite[0].nom}, à ${Math.round(villesParProximite[0].distance_km)} km.`
                  : ''}
              </Text>
            </View>
          )}

          {/*
            Localisation refusée. Il n'existe AUCUN repli automatique : Android et iOS fusionnent
            GPS, Wi-Fi et réseau derrière une seule autorisation. On demande donc à l'utilisateur,
            ce qui est exact par construction — là où une déduction par adresse IP aurait rattaché
            la plupart des abonnés ivoiriens à Abidjan quelle que soit leur position réelle.
          */}
          {choixRequis && villesCouvertes.length > 0 && (
            <View style={styles.choixVille}>
              <Text style={styles.choixTitre}>Dans quelle ville êtes-vous ?</Text>
              <Text style={styles.choixAide}>
                Sans votre position, nous ne pouvons pas le déterminer.
              </Text>
              <View style={styles.choixListe}>
                {villesCouvertes.map((v) => (
                  <FiltreChip
                    key={v.code}
                    label={v.nom}
                    actif={false}
                    onPress={() => void choisirVille(v.code)}
                  />
                ))}
              </View>
            </View>
          )}

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

          {/* Près de moi (géoloc) + bascule Liste / Carte */}
          <View style={styles.barre}>
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

            <View style={styles.bascule}>
              <Segmented<Vue>
                options={[
                  { value: 'liste', label: 'Liste' },
                  { value: 'carte', label: 'Carte' },
                ]}
                value={vue}
                onChange={setVue}
                accessibilityLabel="Basculer entre liste et carte"
              />
            </View>
          </View>

          {/* Filtre type */}
          <ScrollView
            horizontal
            showsHorizontalScrollIndicator={false}
            contentContainerStyle={styles.filtres}
            keyboardShouldPersistTaps="handled"
          >
            <FiltreChip label="Tous" actif={type === null} onPress={() => setType(null)} />
            {typesEtablissement.map((t) => (
              <FiltreChip
                key={t.code}
                label={t.libelle}
                actif={type === t.code}
                onPress={() => setType(t.code)}
              />
            ))}
          </ScrollView>

          {/*
            Filtre commune — affiché SEULEMENT si le serveur en annonce pour cette ville.
            Abidjan se subdivise en communes, Yamoussoukro et Bouaké non : la décision vit dans
            `villes.affiche_communes`, pas dans un `if ville === 'Abidjan'` écrit ici. Une
            quatrième ville subdivisée demain n'exigera aucun déploiement.
          */}
          {communesDisponibles.length > 0 && (
            <ScrollView
              horizontal
              showsHorizontalScrollIndicator={false}
              contentContainerStyle={styles.filtres}
              keyboardShouldPersistTaps="handled"
            >
              <FiltreChip label="Toutes communes" actif={commune === null} onPress={() => setCommune(null)} />
              {communesDisponibles.map((c) => (
                <FiltreChip key={c} label={c} actif={commune === c} onPress={() => setCommune(c)} />
              ))}
            </ScrollView>
          )}

          {/* Filtre budget (F3.2) */}
          <ScrollView
            horizontal
            showsHorizontalScrollIndicator={false}
            contentContainerStyle={styles.filtres}
            keyboardShouldPersistTaps="handled"
          >
            {BUDGETS.map((b) => (
              <FiltreChip
                key={b.label}
                label={b.valeur === null ? b.label : `${b.label} FCFA`}
                actif={tarifMax === b.valeur}
                onPress={() => setTarifMax(b.valeur)}
              />
            ))}
          </ScrollView>
        </View>

        {/* Corps : erreur, sinon vue liste ou carte */}
        {erreur ? (
          <View style={styles.etat}>
            <Ionicons name="cloud-offline-outline" size={28} color={colors.danger.solid} />
            <Text style={styles.etatTxt}>{erreur}</Text>
            <Pressable onPress={() => void charger()} accessibilityRole="button" style={styles.reessayer}>
              <Text style={styles.reessayerTxt}>Réessayer</Text>
            </Pressable>
          </View>
        ) : vue === 'liste' ? (
          <FlatList
            data={structures}
            keyExtractor={(s) => String(s.id)}
            renderItem={({ item }) => <StructureCard structure={item} onPress={() => ouvrirFiche(item.id)} />}
            contentContainerStyle={styles.liste}
            keyboardShouldPersistTaps="handled"
            showsVerticalScrollIndicator={false}
            ListHeaderComponent={
              chargement ? (
                <View style={styles.etat}>
                  <ActivityIndicator color={colors.blue[600]} />
                </View>
              ) : null
            }
            ListEmptyComponent={
              !chargement ? (
                <View style={styles.etat}>
                  <Ionicons name="search-outline" size={28} color={colors.ink[500]} />
                  <Text style={styles.etatTxt}>Aucune structure ne correspond à votre recherche.</Text>
                </View>
              ) : null
            }
          />
        ) : horsLigne ? (
          // La carte (Leaflet + tuiles OSM) exige le réseau : hors ligne, on renvoie vers la liste
          // (elle, servie depuis le cache). Dégradation gracieuse — la carte offline reste à activer.
          <View style={styles.etat}>
            <Ionicons name="map-outline" size={28} color={colors.ink[500]} />
            <Text style={styles.etatTxt}>Carte indisponible hors ligne.</Text>
            <Pressable onPress={() => setVue('liste')} accessibilityRole="button" style={styles.reessayer}>
              <Text style={styles.reessayerTxt}>Voir la liste</Text>
            </Pressable>
          </View>
        ) : (
          <View style={styles.carteZone}>
            <MapWebView structures={structures} position={position} onSelect={choisirSurCarte} />

            {chargement && (
              <View style={styles.carteLoader}>
                <ActivityIndicator color={colors.blue[600]} />
              </View>
            )}

            {/* Aperçu de la structure sélectionnée sur la carte (fiche cliquable en 3B.3). */}
            {selection && (
              <View style={styles.apercu}>
                <Pressable
                  onPress={() => setSelection(null)}
                  hitSlop={8}
                  accessibilityLabel="Fermer l'aperçu"
                  style={styles.apercuClose}
                >
                  <Ionicons name="close" size={20} color={colors.ink[500]} />
                </Pressable>
                <StructureCard structure={selection} onPress={() => ouvrirFiche(selection.id)} />
              </View>
            )}
          </View>
        )}
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
  header: { paddingHorizontal: spacing[6], paddingTop: spacing[5] },
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
  barre: { flexDirection: 'row', alignItems: 'center', gap: spacing[3], marginBottom: spacing[3] },
  geo: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[2],
    height: 44,
    paddingHorizontal: spacing[4],
    borderRadius: radius.pill,
    borderWidth: 1.5,
    borderColor: colors.blue[600],
  },
  geoTxt: { ...typography.caption, fontWeight: '700' },

  // ── P6.4b — ville courante, hors zone, choix de ville ──────────────────────
  villeChip: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: spacing[1],
    paddingVertical: spacing[1],
    paddingHorizontal: spacing[2],
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
    marginBottom: spacing[1],
  },
  villeNom: { ...typography.caption, fontWeight: '700', color: colors.blue[600] },
  villePhrase: { ...typography.caption, color: colors.ink[500], marginBottom: spacing[1] },
  horsZone: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing[1],
    padding: spacing[2],
    borderRadius: radius.md,
    backgroundColor: colors.surface,
    marginBottom: spacing[1],
  },
  horsZoneTxt: { ...typography.caption, color: colors.ink[500], flex: 1 },
  choixVille: {
    padding: spacing[2],
    borderRadius: radius.md,
    backgroundColor: colors.surface,
    marginBottom: spacing[1],
  },
  choixTitre: { ...typography.caption, fontWeight: '700', color: colors.ink[900] },
  choixAide: { ...typography.caption, color: colors.ink[500], marginTop: 2, marginBottom: spacing[1] },
  choixListe: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing[1] },
  presse: { opacity: 0.7 },
  bascule: { flex: 1, minWidth: 150 },
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
  liste: { paddingHorizontal: spacing[6], paddingTop: spacing[4], paddingBottom: spacing[8] },
  carteZone: { flex: 1, paddingHorizontal: spacing[6], paddingTop: spacing[4], paddingBottom: spacing[5] },
  carteLoader: {
    position: 'absolute',
    top: spacing[6],
    alignSelf: 'center',
    backgroundColor: colors.surface,
    borderRadius: radius.pill,
    padding: spacing[2],
    ...({ elevation: 3 } as object),
  },
  apercu: { position: 'absolute', left: spacing[6], right: spacing[6], bottom: spacing[6] },
  apercuClose: {
    position: 'absolute',
    top: -spacing[2],
    right: -spacing[2],
    zIndex: 1,
    width: 32,
    height: 32,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
    ...({ elevation: 4 } as object),
  },
  etat: { alignItems: 'center', gap: spacing[2], paddingVertical: spacing[8], paddingHorizontal: spacing[6] },
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
