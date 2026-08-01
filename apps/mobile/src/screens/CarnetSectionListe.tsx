import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../components/Screen';
import { ScreenHeader } from '../components/ScreenHeader';
import { Card } from '../components/Card';
import { PrimaryButton } from '../components/PrimaryButton';
import { sectionParSlug, PROVENANCE_SOURCE } from '../carnet/registre';
import { listerSection, supprimerItem } from '../api/carnet';
import { messageErreur } from '../utils/erreurs';
import type { CarnetItem, Ton } from '../types/carnet';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * CarnetSectionListe — liste générique des éléments d'une section du carnet, pilotée par le
 * registre. Affiche le résumé de chaque élément, permet l'ajout, l'édition (au tap) et la
 * suppression (avec confirmation). Rechargée à chaque retour sur l'écran.
 */
export function CarnetSectionListe({
  membreId,
  slug,
  nomMembre,
}: {
  membreId: number;
  slug: string;
  nomMembre?: string;
}) {
  const section = sectionParSlug(slug);

  const [items, setItems] = useState<CarnetItem[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  const recharger = useCallback(async () => {
    if (!section) return;
    setErreur(null);
    try {
      const liste = await listerSection(membreId, section.chemin);
      setItems(liste);
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, [section, membreId]);

  useFocusEffect(
    useCallback(() => {
      let actif = true;
      (async () => {
        if (actif) await recharger();
      })();
      return () => {
        actif = false;
      };
    }, [recharger]),
  );

  if (!section) {
    return (
      <Screen>
        <ScreenHeader title="Carnet" onBack={() => router.back()} />
        <Text style={styles.erreur}>Section inconnue.</Text>
      </Screen>
    );
  }

  const confirmerSuppression = (item: CarnetItem) => {
    const { titre } = section.resume(item);
    Alert.alert('Supprimer ?', `« ${titre} » sera définitivement supprimé.`, [
      { text: 'Annuler', style: 'cancel' },
      {
        text: 'Supprimer',
        style: 'destructive',
        onPress: async () => {
          try {
            await supprimerItem(membreId, section.chemin, item.id);
            await recharger();
          } catch (e) {
            Alert.alert('Suppression impossible', messageErreur(e));
          }
        },
      },
    ]);
  };

  const ouvrirEdition = (item: CarnetItem) =>
    router.push({
      pathname: '/(app)/membres/carnet/[id]/[section]/[item]',
      params: { id: membreId, section: slug, item: item.id, nom: nomMembre ?? '' },
    });

  const ouvrirAjout = () =>
    router.push({
      pathname: '/(app)/membres/carnet/[id]/[section]/nouveau',
      params: { id: membreId, section: slug, nom: nomMembre ?? '' },
    });

  return (
    <Screen>
      <ScreenHeader
        title={section.titre}
        subtitle={nomMembre ? `Dossier de ${nomMembre}` : undefined}
        onBack={() => router.back()}
      />

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      ) : erreur ? (
        <Text style={styles.erreur}>{erreur}</Text>
      ) : items.length === 0 ? (
        <Card style={styles.vide}>
          <Ionicons name={section.icone as keyof typeof Ionicons.glyphMap} size={32} color={colors.blue[400]} />
          <Text style={styles.videTxt}>Aucun élément pour l'instant.</Text>
        </Card>
      ) : (
        <View style={styles.liste}>
          {items.map((item) => (
            <ItemVue
              key={item.id}
              icone={section.icone}
              resume={section.resume(item)}
              source={typeof item.source === 'string' ? item.source : undefined}
              onPress={section.appendOnly ? undefined : () => ouvrirEdition(item)}
              onSupprimer={() => confirmerSuppression(item)}
            />
          ))}
        </View>
      )}

      <View style={styles.ajout}>
        <PrimaryButton label={`Ajouter un ${section.titreSingulier}`} onPress={ouvrirAjout} />
      </View>
    </Screen>
  );
}

/** Carte d'un élément de section (résumé générique). */
function ItemVue({
  icone,
  resume,
  source,
  onPress,
  onSupprimer,
}: {
  icone: string;
  resume: ReturnType<NonNullable<ReturnType<typeof sectionParSlug>>['resume']>;
  source?: string; // provenance F2.13 (undefined = section sans colonne `source`)
  onPress?: () => void; // absent = section append-only : pas d'édition au tap
  onSupprimer: () => void;
}) {
  const tons = tonCouleurs(resume.badge?.ton ?? 'neutre');
  // F2.13 — pastille de provenance : les 3 origines affichées, `patient` atténué.
  const prov = source ? PROVENANCE_SOURCE[source] : undefined;
  const provTons = prov?.officiel
    ? { bg: colors.blue[100], text: colors.blue[700], icon: colors.blue[600] }
    : { bg: colors.surfaceMuted, text: colors.ink[500], icon: colors.ink[500] };
  const contenu = (
    <>
      <View style={styles.pastille}>
        <Ionicons name={icone as keyof typeof Ionicons.glyphMap} size={18} color={colors.blue[600]} />
      </View>
      <View style={styles.itemTexte}>
        <Text style={styles.itemTitre}>{resume.titre}</Text>
        {resume.lignes.map((l, i) => (
          <Text key={i} style={styles.itemLigne} numberOfLines={2}>
            {l}
          </Text>
        ))}
        {resume.badge || prov ? (
          <View style={styles.badgesRangee}>
            {resume.badge ? (
              <View style={[styles.badge, { backgroundColor: tons.bg }]}>
                <Text style={[styles.badgeTxt, { color: tons.text }]}>{resume.badge.texte}</Text>
              </View>
            ) : null}
            {prov ? (
              <View style={[styles.badge, styles.provChip, { backgroundColor: provTons.bg }]}>
                <Ionicons name={prov.icone as keyof typeof Ionicons.glyphMap} size={12} color={provTons.icon} />
                <Text style={[styles.badgeTxt, { color: provTons.text }]}>{prov.label}</Text>
              </View>
            ) : null}
          </View>
        ) : null}
      </View>
    </>
  );
  return (
    <Card style={styles.item}>
      {onPress ? (
        <Pressable onPress={onPress} accessibilityRole="button" accessibilityLabel={resume.titre} style={styles.itemPress}>
          {contenu}
        </Pressable>
      ) : (
        <View style={styles.itemPress}>{contenu}</View>
      )}
      <Pressable onPress={onSupprimer} accessibilityRole="button" accessibilityLabel="Supprimer" hitSlop={8} style={styles.trash}>
        <Ionicons name="trash-outline" size={20} color={colors.ink[500]} />
      </Pressable>
    </Card>
  );
}

/** Couleurs de badge selon la tonalité sémantique (tokens DS). */
function tonCouleurs(ton: Ton): { bg: string; text: string } {
  switch (ton) {
    case 'success':
      return { bg: colors.success.bg, text: colors.success.text };
    case 'warning':
      return { bg: colors.warning.bg, text: colors.warning.text };
    case 'danger':
      return { bg: colors.danger.bg, text: colors.danger.text };
    default:
      return { bg: colors.blue[100], text: colors.blue[700] };
  }
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },
  vide: { alignItems: 'center', marginBottom: spacing[5] },
  videTxt: { ...typography.bodyStrong, color: colors.ink[700], marginTop: spacing[3] },
  liste: { gap: spacing[3], marginBottom: spacing[5] },
  item: { flexDirection: 'row', alignItems: 'flex-start' },
  itemPress: { flexDirection: 'row', alignItems: 'flex-start', flex: 1 },
  pastille: {
    width: 36,
    height: 36,
    borderRadius: radius.pill,
    backgroundColor: colors.blue[100],
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing[3],
  },
  itemTexte: { flex: 1 },
  itemTitre: { ...typography.bodyStrong, color: colors.blue[900] },
  itemLigne: { ...typography.caption, color: colors.ink[700], marginTop: 2 },
  badgesRangee: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: spacing[2], marginTop: spacing[2] },
  badge: { alignSelf: 'flex-start', borderRadius: radius.pill, paddingHorizontal: spacing[3], paddingVertical: spacing[1] },
  badgeTxt: { ...typography.caption, fontWeight: '700' },
  provChip: { flexDirection: 'row', alignItems: 'center', gap: spacing[1], paddingHorizontal: spacing[2] },
  trash: { padding: spacing[1], marginLeft: spacing[2] },
  ajout: { marginBottom: spacing[6] },
});
