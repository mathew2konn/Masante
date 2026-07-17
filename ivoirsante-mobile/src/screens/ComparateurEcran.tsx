import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../components/Screen';
import { ScreenHeader } from '../components/ScreenHeader';
import { Card } from '../components/Card';
import { TextField } from '../components/TextField';
import { PrimaryButton } from '../components/PrimaryButton';
import { SecondaryButton } from '../components/SecondaryButton';
import { comparerPrix, lireRecu, releverPrix, signalerRupture } from '../api/medicaments';
import { rechercherStructures } from '../api/structures';
import { prendrePhoto, choisirDansGalerie, PermissionRefusee } from '../documents/selection';
import { obtenirPosition } from '../utils/geoloc';
import { messageErreur } from '../utils/erreurs';
import { formatDateFr } from '../utils/dates';
import type { ComparateurVue, OffrePharmacie, SourcePrix } from '../types/medicament';
import type { Structure } from '../types/structure';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * ComparateurEcran (CdC FN7/FN8, Module 5.8) — prix d'un médicament, pharmacie par pharmacie.
 *
 * CE QUE L'ÉCRAN PROMET, IL LE TIENT : chaque prix affiché porte sa SOURCE (« déclaré par la
 * pharmacie » vs « rapporté par N patients ») et sa DATE. Un prix sans provenance ni fraîcheur serait
 * une affirmation ; ici, c'est un constat daté, que le patient peut pondérer lui-même.
 *
 * Le patient contribue à son tour : il rapporte le prix payé (le « scan de reçu » du CdC ne fait que
 * PRÉ-REMPLIR le champ — l'OCR ne décide rien, et la photo est détruite côté serveur), ou signale une
 * rupture. Le serveur refuse les montants invraisemblables : l'app n'a aucune règle de prix en dur.
 */
export function ComparateurEcran({ medicamentId, nom }: { medicamentId: number; nom?: string }) {
  const [vue, setVue] = useState<ComparateurVue | null>(null);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  // Contribution : pharmacie choisie, prix saisi (éventuellement pré-rempli par l'OCR).
  const [pharmacies, setPharmacies] = useState<Structure[]>([]);
  const [pharmacieId, setPharmacieId] = useState<number | null>(null);
  const [prix, setPrix] = useState('');
  const [envoi, setEnvoi] = useState(false);
  const [ocrEnCours, setOcrEnCours] = useState(false);

  const charger = useCallback(async () => {
    setErreur(null);
    try {
      setVue(await comparerPrix(medicamentId));
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, [medicamentId]);

  useFocusEffect(
    useCallback(() => {
      void charger();

      // Les pharmacies proches : c'est parmi elles que le patient désigne celle où il a acheté.
      void (async () => {
        const position = await obtenirPosition();
        try {
          setPharmacies(
            await rechercherStructures({
              type: 'pharmacie',
              ...(position.ok ? { lat: position.coords.lat, lng: position.coords.lng } : {}),
            }),
          );
        } catch {
          setPharmacies([]);
        }
      })();
    }, [charger]),
  );

  /** « Scanner le reçu » — l'OCR PRÉ-REMPLIT le prix ; le patient confirme ou corrige. */
  const scannerRecu = async (source: 'photo' | 'galerie') => {
    setOcrEnCours(true);
    try {
      const fichier = source === 'photo' ? await prendrePhoto() : await choisirDansGalerie();
      if (!fichier) return;

      const lecture = await lireRecu(fichier.uri);

      if (lecture.montants.length === 0) {
        Alert.alert(
          'Aucun montant lu',
          'Le reçu n\'a pas pu être déchiffré (photo floue, ticket froissé…). Saisissez le prix à la main.',
        );
        return;
      }

      // On PROPOSE les montants lus : c'est le patient qui sait ce qu'il a payé pour CE médicament.
      // Un ticket porte plusieurs lignes et un total ; aucune machine ne peut deviner laquelle compte.
      Alert.alert(
        'Montants lus sur le reçu',
        'Choisissez le prix payé pour ce médicament. Vous pourrez le corriger avant de l\'envoyer.',
        [
          ...lecture.montants.slice(0, 4).map((montant) => ({
            text: `${montant} F`,
            onPress: () => setPrix(String(montant)),
          })),
          { text: 'Saisir à la main', style: 'cancel' as const },
        ],
      );
    } catch (e) {
      Alert.alert(
        'Lecture impossible',
        e instanceof PermissionRefusee ? e.message : messageErreur(e),
      );
    } finally {
      setOcrEnCours(false);
    }
  };

  const envoyerPrix = async () => {
    if (pharmacieId === null || prix.trim() === '') return;
    setEnvoi(true);
    try {
      await releverPrix(medicamentId, pharmacieId, Number(prix.replace(/\D/g, '')));
      setPrix('');
      await charger();
      Alert.alert('Merci', 'Votre relevé aidera les autres patients à savoir où acheter.');
    } catch (e) {
      Alert.alert('Envoi impossible', messageErreur(e));
    } finally {
      setEnvoi(false);
    }
  };

  const envoyerRupture = () => {
    if (pharmacieId === null) {
      Alert.alert('Pharmacie', 'Choisissez d\'abord la pharmacie concernée.');
      return;
    }

    Alert.alert(
      'Signaler une rupture ?',
      'Vous indiquez que ce médicament n\'était pas disponible dans cette pharmacie. '
        + 'Les autres patients éviteront un déplacement inutile.',
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: 'Signaler',
          onPress: async () => {
            try {
              await signalerRupture(medicamentId, pharmacieId);
              await charger();
            } catch (e) {
              Alert.alert('Signalement impossible', messageErreur(e));
            }
          },
        },
      ],
    );
  };

  if (chargement) {
    return (
      <Screen>
        <ScreenHeader title="Prix" subtitle={nom} onBack={() => router.back()} />
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      </Screen>
    );
  }

  if (erreur || !vue) {
    return (
      <Screen>
        <ScreenHeader title="Prix" subtitle={nom} onBack={() => router.back()} />
        <Text style={styles.erreur}>{erreur ?? 'Comparateur indisponible.'}</Text>
      </Screen>
    );
  }

  const { medicament, offres, generiques } = vue;

  return (
    <Screen>
      <ScreenHeader title={medicament.libelle} subtitle={medicament.categorie} onBack={() => router.back()} />

      {/* Le prix officiel : le repère qui permet de juger tous les autres. */}
      {medicament.prix_reference_cfa !== null ? (
        <Card style={[styles.bloc, styles.reference]}>
          <Text style={styles.referenceLbl}>Prix de référence (CENAME)</Text>
          <Text style={styles.referenceVal}>{medicament.prix_reference_cfa} FCFA</Text>
          {medicament.ordonnance_requise ? (
            <Text style={styles.referenceNote}>Délivré sur ordonnance.</Text>
          ) : null}
        </Card>
      ) : null}

      {/* FN7 — « Suggère génériques moins chers » : même molécule, prix officiel inférieur. */}
      {generiques.length > 0 ? (
        <Card style={[styles.bloc, styles.economie]}>
          <View style={styles.economieEntete}>
            <Ionicons name="pricetag" size={18} color={colors.success.text} />
            <Text style={styles.economieTitre}>Même molécule, moins cher</Text>
          </View>
          {generiques.map((g) => (
            <Pressable
              key={g.id}
              onPress={() =>
                router.push({
                  pathname: '/(app)/medicaments/[id]',
                  params: { id: String(g.id), nom: g.libelle },
                })
              }
              accessibilityRole="button"
              accessibilityLabel={`${g.libelle}, ${g.prix_reference_cfa} francs`}
              style={styles.generique}
            >
              <View style={styles.generiqueInfos}>
                <Text style={styles.generiqueNom}>{g.libelle}</Text>
                <Text style={styles.generiqueMeta}>Même principe actif : {g.nom_generique}</Text>
              </View>
              <Text style={styles.generiquePrix}>{g.prix_reference_cfa} F</Text>
              <Ionicons name="chevron-forward" size={18} color={colors.ink[500]} />
            </Pressable>
          ))}
        </Card>
      ) : null}

      {/* Les prix réellement pratiqués, du moins cher au plus cher. */}
      <Card style={styles.bloc}>
        <Text style={styles.blocTitre}>Prix par pharmacie</Text>
        {offres.length === 0 ? (
          <Text style={styles.videTxt}>
            Aucun prix relevé récemment. Soyez le premier à rapporter le prix payé : c'est ce qui rend ce
            comparateur utile.
          </Text>
        ) : (
          offres.map((offre, i) => <Offre key={offre.structure.id} offre={offre} premier={i === 0} />)
        )}
      </Card>

      {/* La contribution du patient : rapporter un prix, ou signaler une rupture. */}
      <Card style={styles.bloc}>
        <Text style={styles.blocTitre}>Vous venez d'acheter ?</Text>
        <Text style={styles.blocAide}>
          Rapportez le prix payé : les autres patients sauront où acheter au juste prix. Vous pouvez
          photographier le reçu pour remplir le montant automatiquement — la photo est lue puis
          <Text style={styles.gras}> immédiatement détruite</Text>, elle n'est jamais conservée.
        </Text>

        <Text style={styles.champLbl}>Pharmacie</Text>
        <View style={styles.pharmacies}>
          {pharmacies.slice(0, 8).map((p) => {
            const actif = pharmacieId === p.id;
            return (
              <Pressable
                key={p.id}
                onPress={() => setPharmacieId(p.id)}
                accessibilityRole="radio"
                accessibilityState={{ selected: actif }}
                accessibilityLabel={p.nom}
                style={[styles.pharmacieChip, actif && styles.pharmacieChipActif]}
              >
                <Text style={[styles.pharmacieTxt, actif && styles.pharmacieTxtActif]} numberOfLines={1}>
                  {p.nom}
                  {p.distance_km != null ? ` · ${p.distance_km} km` : ''}
                </Text>
              </Pressable>
            );
          })}
          {pharmacies.length === 0 ? (
            <Text style={styles.videTxt}>Aucune pharmacie trouvée autour de vous.</Text>
          ) : null}
        </View>

        <TextField
          label="Prix payé (FCFA)"
          value={prix}
          onChangeText={setPrix}
          keyboardType="numeric"
          maxLength={7}
        />

        <View style={styles.scanRow}>
          <SecondaryButton
            label={ocrEnCours ? 'Lecture…' : 'Photographier le reçu'}
            onPress={() => void scannerRecu('photo')}
            disabled={ocrEnCours}
          />
          <View style={styles.sep} />
          <SecondaryButton
            label="Choisir une photo du reçu"
            onPress={() => void scannerRecu('galerie')}
            disabled={ocrEnCours}
          />
        </View>

        <View style={styles.sep} />
        <PrimaryButton
          label="Envoyer le prix"
          onPress={envoyerPrix}
          loading={envoi}
          disabled={pharmacieId === null || prix.trim() === ''}
        />
        <View style={styles.sep} />
        <SecondaryButton label="Signaler une rupture ici" onPress={envoyerRupture} />
      </Card>
    </Screen>
  );
}

/** Libellé de la provenance d'un prix : le patient doit savoir QUI le dit. */
const LIBELLE_SOURCE: Record<SourcePrix, string> = {
  pharmacie_portail: 'déclaré par la pharmacie',
  cename: 'prix officiel',
  crowdsource_patient: 'rapporté par des patients',
};

/** Une pharmacie et son prix : montant, provenance, fraîcheur, disponibilité. */
function Offre({ offre, premier }: { offre: OffrePharmacie; premier: boolean }) {
  const fiable = offre.source === 'pharmacie_portail';

  return (
    <View style={[styles.offre, !premier && styles.bordure]}>
      <View style={styles.offreInfos}>
        <Text style={styles.offreNom}>{offre.structure.nom}</Text>
        <Text style={styles.offreMeta}>
          {offre.structure.commune}
          {offre.date_mise_a_jour ? ` · relevé le ${formatDateFr(offre.date_mise_a_jour)}` : ''}
        </Text>
        <View style={styles.offreBadges}>
          {offre.source ? (
            <View style={[styles.badge, { backgroundColor: fiable ? colors.success.bg : colors.blue[100] }]}>
              <Text style={[styles.badgeTxt, { color: fiable ? colors.success.text : colors.blue[700] }]}>
                {LIBELLE_SOURCE[offre.source]}
                {offre.source === 'crowdsource_patient' && offre.releves > 0 ? ` (${offre.releves})` : ''}
              </Text>
            </View>
          ) : null}
          {!offre.disponible ? (
            <View style={[styles.badge, { backgroundColor: colors.warning.bg }]}>
              <Text style={[styles.badgeTxt, { color: colors.warning.text }]}>En rupture</Text>
            </View>
          ) : null}
        </View>
      </View>
      <Text style={styles.offrePrix}>{offre.prix_cfa !== null ? `${offre.prix_cfa} F` : '—'}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },

  bloc: { marginBottom: spacing[5] },
  blocTitre: { ...typography.h2, color: colors.blue[900], marginBottom: spacing[2] },
  blocAide: { ...typography.body, color: colors.ink[700], marginBottom: spacing[4] },
  videTxt: { ...typography.body, color: colors.ink[500] },
  bordure: { borderTopWidth: 1, borderTopColor: colors.line },
  sep: { height: spacing[3] },
  gras: { fontWeight: '700' },

  // Référence CENAME
  reference: { backgroundColor: colors.blue[100] },
  referenceLbl: { ...typography.caption, color: colors.blue[700] },
  referenceVal: { ...typography.h1, color: colors.blue[900], marginTop: spacing[1] },
  referenceNote: { ...typography.caption, color: colors.ink[700], marginTop: spacing[1] },

  // Génériques
  economie: { borderWidth: 1, borderColor: colors.success.solid, backgroundColor: colors.success.bg },
  economieEntete: { flexDirection: 'row', alignItems: 'center', gap: spacing[2], marginBottom: spacing[2] },
  economieTitre: { ...typography.bodyStrong, color: colors.success.text, flex: 1 },
  generique: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing[2] },
  generiqueInfos: { flex: 1 },
  generiqueNom: { ...typography.bodyStrong, color: colors.ink[900] },
  generiqueMeta: { ...typography.caption, color: colors.ink[700], marginTop: 2 },
  generiquePrix: { ...typography.bodyStrong, color: colors.success.text, marginRight: spacing[2] },

  // Offres
  offre: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing[3] },
  offreInfos: { flex: 1 },
  offreNom: { ...typography.bodyStrong, color: colors.blue[900] },
  offreMeta: { ...typography.caption, color: colors.ink[500], marginTop: 2 },
  offreBadges: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing[1], marginTop: spacing[2] },
  offrePrix: { ...typography.h2, color: colors.blue[900], marginLeft: spacing[3] },
  badge: { paddingVertical: 2, paddingHorizontal: spacing[2], borderRadius: radius.pill },
  badgeTxt: { ...typography.caption, fontWeight: '700' },

  // Contribution
  champLbl: { ...typography.caption, color: colors.ink[700], marginBottom: spacing[2] },
  pharmacies: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing[2], marginBottom: spacing[4] },
  pharmacieChip: {
    paddingVertical: spacing[2],
    paddingHorizontal: spacing[3],
    borderRadius: radius.pill,
    backgroundColor: colors.blue[100],
    maxWidth: '100%',
  },
  pharmacieChipActif: { backgroundColor: colors.blue[600] },
  pharmacieTxt: { ...typography.caption, color: colors.blue[700] },
  pharmacieTxtActif: { color: colors.surface },
  scanRow: { marginTop: spacing[3] },
});
