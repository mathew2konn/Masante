import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Card } from './Card';
import { PastilleDispo } from './PastilleDispo';
import { colors, radius, spacing, typography } from '../theme/theme';
import ImageEtablissementView from './ImageEtablissementView';
import { CATEGORIE_LOGO, libelleType, type Structure } from '../types/structure';
import { useLocalisation } from '../store/localisation';

/**
 * StructureCard — tuile d'une structure dans la liste de l'annuaire (Module 3, §5.2/§5.5).
 *
 * Affiche le nom, le type + la commune, la pastille de disponibilité du jour, la distance (si
 * une position a été fournie) et la note moyenne. Le badge « Partenaire » distingue les
 * établissements partenaires d'IVOIRSANTÉ. `onPress` est optionnel : la fiche détaillée
 * (navigation) arrive en 3B.3 — on n'anticipe pas.
 */
export function StructureCard({ structure, onPress }: { structure: Structure; onPress?: () => void }) {
  // Libellés servis par le serveur (P6.4b) : la table locale n'en connaissait que 7 sur 13 et
  // affichait « undefined » devant une catégorie récente.
  const types = useLocalisation((e) => e.typesEtablissement);
  const categorie = libelleType(structure.type, types);

  const contenu = (
    <Card style={styles.card}>
      <View style={styles.entete}>
        {/*
          P6.4c — le logo remplace l'icône générique quand l'établissement en publie un. En liste,
          le serveur n'envoie QUE le logo : charger les photos d'accueil et de bloc opératoire de
          douze structures pour des images que cette tuile n'affiche pas serait payer un transfert
          pour rien. Sans logo — ou hors ligne — on retombe sur l'icône, jamais sur un vide.
        */}
        <ImageEtablissementView
          image={structure.images?.find((i) => i.categorie_code === CATEGORIE_LOGO)}
          repli={iconePour(structure)}
          taille={44}
          style={styles.logo}
          description={`Logo de ${structure.nom}`}
        />
        <View style={styles.titreBloc}>
          <Text style={styles.nom} numberOfLines={2}>{structure.nom}</Text>
          <Text style={styles.meta} numberOfLines={1}>
            {categorie} · {structure.commune}
          </Text>
        </View>
        {structure.distance_km != null && (
          <View style={styles.distanceBloc}>
            <Ionicons name="navigate" size={13} color={colors.ink[500]} />
            <Text style={styles.distance}>{formatDistance(structure.distance_km)}</Text>
          </View>
        )}
      </View>

      <View style={styles.bas}>
        <PastilleDispo statut={structure.statut_jour} />
        <View style={styles.basDroite}>
          {structure.partenaire_ivoirsante && (
            <View style={styles.partenaire}>
              <Ionicons name="shield-checkmark" size={12} color={colors.blue[700]} />
              <Text style={styles.partenaireTxt}>Partenaire</Text>
            </View>
          )}
          {structure.note_moyenne != null && (
            <View style={styles.note}>
              <Ionicons name="star" size={13} color={colors.warning.solid} />
              <Text style={styles.noteTxt}>
                {structure.note_moyenne.toFixed(1)} ({structure.nb_avis})
              </Text>
            </View>
          )}
        </View>
      </View>
    </Card>
  );

  if (!onPress) return <View style={styles.wrap}>{contenu}</View>;

  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={`${structure.nom}, ${categorie} à ${structure.commune}`}
      style={({ pressed }) => [styles.wrap, pressed && styles.presse]}
    >
      {contenu}
    </Pressable>
  );
}

/** Icône évocatrice selon le type de structure. */
function iconePour(s: Structure): keyof typeof Ionicons.glyphMap {
  switch (s.type) {
    case 'pharmacie': return 'medkit-outline';
    case 'laboratoire': return 'flask-outline';
    case 'cabinet': return 'person-outline';
    case 'centre_sante': return 'fitness-outline';
    default: return 'business-outline';
  }
}

/** Distance lisible : mètres en dessous de 1 km, sinon kilomètres. */
function formatDistance(km: number): string {
  return km < 1 ? `${Math.round(km * 1000)} m` : `${km.toFixed(1)} km`;
}

const styles = StyleSheet.create({
  wrap: { marginBottom: spacing[3] },
  presse: { opacity: 0.7 },
  card: { gap: spacing[3] },
  entete: { flexDirection: 'row', alignItems: 'center' },
  logo: {
    borderRadius: radius.pill, backgroundColor: colors.blue[100], marginRight: spacing[3],
  },
  titreBloc: { flex: 1 },
  nom: { ...typography.bodyStrong, color: colors.blue[900] },
  meta: { ...typography.caption, color: colors.ink[500], marginTop: 2 },
  distanceBloc: { alignItems: 'center', marginLeft: spacing[2] },
  distance: { ...typography.caption, color: colors.ink[500], fontWeight: '600' },
  bas: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  basDroite: { flexDirection: 'row', alignItems: 'center', gap: spacing[3] },
  partenaire: { flexDirection: 'row', alignItems: 'center', gap: 3 },
  partenaireTxt: { ...typography.caption, color: colors.blue[700], fontWeight: '600' },
  note: { flexDirection: 'row', alignItems: 'center', gap: 3 },
  noteTxt: { ...typography.caption, color: colors.ink[700], fontWeight: '600' },
});
