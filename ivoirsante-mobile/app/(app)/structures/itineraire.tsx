import React, { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Linking,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { StatusBar as ExpoStatusBar } from 'expo-status-bar';
import { router, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { GradientBackground } from '../../../src/components/GradientBackground';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { MapWebView } from '../../../src/components/MapWebView';
import { calculerItineraire, type Itineraire } from '../../../src/api/itineraire';
import { obtenirPosition } from '../../../src/utils/geoloc';
import { messageErreur } from '../../../src/utils/erreurs';
import type { Coordonnees, Structure } from '../../../src/types/structure';
import { colors, radius, spacing, typography } from '../../../src/theme/theme';

/**
 * Itinéraire vers une structure (Module 3 / 3B.3, F3.7).
 *
 * Trajet calculé par OSRM (gratuit, sans clé — voir api/itineraire.ts) et tracé sur la carte
 * Leaflet/OSM (MapWebView). Nécessite la position de l'utilisateur (foreground). En cas d'échec
 * (permission refusée, OSRM indisponible), repli vers une appli de cartes externe.
 *
 * Route sœur de [id].tsx (pas imbriquée) : la destination arrive via les params (id, lat, lng, nom).
 */
export default function ItineraireStructure() {
  const params = useLocalSearchParams<{ id: string; lat: string; lng: string; nom: string }>();
  const destination: Coordonnees = { lat: Number(params.lat), lng: Number(params.lng) };
  const nom = params.nom ?? 'Structure';

  const [position, setPosition] = useState<Coordonnees | null>(null);
  const [itineraire, setItineraire] = useState<Itineraire | null>(null);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  const charger = useCallback(async () => {
    setChargement(true);
    setErreur(null);
    const r = await obtenirPosition();
    if (!r.ok) {
      setChargement(false);
      setErreur(
        r.raison === 'permission_refusee'
          ? "Autorisez l'accès à votre position pour calculer l'itinéraire."
          : "Votre position n'est pas disponible pour le moment.",
      );
      return;
    }
    setPosition(r.coords);
    try {
      setItineraire(await calculerItineraire(r.coords, destination));
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
    // destination provient des params (stable pour un écran donné).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.lat, params.lng]);

  useEffect(() => {
    void charger();
  }, [charger]);

  /** Repli : ouvre l'itinéraire dans une appli de cartes externe (OpenStreetMap). */
  function ouvrirCartesExternes() {
    const url = `https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route=${
      position ? `${position.lat},${position.lng}` : ''
    };${destination.lat},${destination.lng}`;
    void Linking.openURL(url);
  }

  // Marqueur de destination (structure minimale pour MapWebView).
  const marqueurDestination: Structure = {
    id: Number(params.id),
    nom,
    type: 'centre_sante',
    adresse: null,
    commune: '',
    latitude: destination.lat,
    longitude: destination.lng,
    telephone: null,
    whatsapp: null,
    horaires_json: null,
    specialites_json: null,
    tarif_min_cfa: null,
    tarif_max_cfa: null,
    note_moyenne: null,
    nb_avis: 0,
    partenaire_ivoirsante: false,
    statut_jour: 'inconnu',
  };

  return (
    <GradientBackground>
      <ExpoStatusBar style="dark" />
      <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
        <View style={styles.header}>
          <ScreenHeader title="Itinéraire" subtitle={nom} onBack={() => router.back()} />
          {itineraire && (
            <View style={styles.resume}>
              <View style={styles.resumeItem}>
                <Ionicons name="navigate" size={16} color={colors.blue[600]} />
                <Text style={styles.resumeTxt}>{itineraire.distance_km} km</Text>
              </View>
              <View style={styles.resumeItem}>
                <Ionicons name="time-outline" size={16} color={colors.blue[600]} />
                <Text style={styles.resumeTxt}>{itineraire.duree_min} min en voiture</Text>
              </View>
            </View>
          )}
        </View>

        <View style={styles.zone}>
          {chargement ? (
            <View style={styles.centre}>
              <ActivityIndicator color={colors.blue[600]} />
              <Text style={styles.etatTxt}>Calcul de l'itinéraire…</Text>
            </View>
          ) : erreur ? (
            <View style={styles.centre}>
              <Ionicons name="navigate-circle-outline" size={28} color={colors.danger.solid} />
              <Text style={styles.etatTxt}>{erreur}</Text>
              <Pressable onPress={() => void charger()} accessibilityRole="button" style={styles.btnContour}>
                <Text style={styles.btnContourTxt}>Réessayer</Text>
              </Pressable>
              <Pressable onPress={ouvrirCartesExternes} accessibilityRole="button" style={styles.btnTexte}>
                <Text style={styles.btnTexteTxt}>Ouvrir dans une appli de cartes</Text>
              </Pressable>
            </View>
          ) : (
            <MapWebView
              structures={[marqueurDestination]}
              position={position}
              route={itineraire?.coords ?? null}
            />
          )}
        </View>
      </SafeAreaView>
    </GradientBackground>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  header: { paddingHorizontal: spacing[6], paddingTop: spacing[5] },
  resume: { flexDirection: 'row', gap: spacing[5], marginBottom: spacing[3] },
  resumeItem: { flexDirection: 'row', alignItems: 'center', gap: spacing[1] },
  resumeTxt: { ...typography.bodyStrong, color: colors.ink[900] },
  zone: { flex: 1, paddingHorizontal: spacing[6], paddingTop: spacing[2], paddingBottom: spacing[5] },
  centre: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: spacing[3] },
  etatTxt: { ...typography.body, color: colors.ink[700], textAlign: 'center' },
  btnContour: {
    paddingHorizontal: spacing[5],
    height: 44,
    justifyContent: 'center',
    borderRadius: radius.pill,
    borderWidth: 1.5,
    borderColor: colors.blue[600],
  },
  btnContourTxt: { ...typography.button, color: colors.blue[600] },
  btnTexte: { paddingVertical: spacing[2] },
  btnTexteTxt: { ...typography.body, color: colors.blue[600], fontWeight: '600', textDecorationLine: 'underline' },
});
