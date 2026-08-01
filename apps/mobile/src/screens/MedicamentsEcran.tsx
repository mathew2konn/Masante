import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../components/Screen';
import { ScreenHeader } from '../components/ScreenHeader';
import { Card } from '../components/Card';
import { TextField } from '../components/TextField';
import { obtenirRuptures, rechercherMedicaments } from '../api/medicaments';
import { messageErreur } from '../utils/erreurs';
import type { Medicament, RuptureAgregee } from '../types/medicament';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * MedicamentsEcran (CdC FN7/FN8, Module 5.8) — porte d'entrée « Médicaments ».
 *
 * Deux questions, deux blocs : « combien ça coûte, et où ? » (le catalogue, qui mène au comparateur)
 * et « qu'est-ce qui manque en ce moment ? » (les ruptures agrégées — l'information qui évite un
 * déplacement inutile, raison d'être de FN8).
 *
 * Les prix affichés ici sont les prix de RÉFÉRENCE officiels (CENAME) : les prix réellement
 * pratiqués, avec leur source et leur fraîcheur, sont dans le comparateur d'un médicament.
 */
export function MedicamentsEcran() {
  const [recherche, setRecherche] = useState('');
  const [medicaments, setMedicaments] = useState<Medicament[]>([]);
  const [ruptures, setRuptures] = useState<RuptureAgregee[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  const charger = useCallback(async (q?: string) => {
    setErreur(null);
    try {
      const [liste, manquants] = await Promise.all([rechercherMedicaments(q), obtenirRuptures()]);
      setMedicaments(liste);
      setRuptures(manquants);
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      void charger(recherche.trim() || undefined);
      // La recherche courante est volontairement hors dépendances : on recharge au retour sur
      // l'écran (les ruptures peuvent avoir changé), pas à chaque frappe.
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [charger]),
  );

  const ouvrir = (medicament: Medicament) =>
    router.push({
      pathname: '/(app)/medicaments/[id]',
      params: { id: String(medicament.id), nom: medicament.libelle },
    });

  return (
    <Screen>
      <ScreenHeader title="Médicaments" onBack={() => router.back()} />

      {/* FN8 — Ce qui manque en ce moment. En tête : c'est l'information qui fait faire demi-tour. */}
      {ruptures.length > 0 ? (
        <Card style={[styles.bloc, styles.ruptures]}>
          <View style={styles.rupturesEntete}>
            <Ionicons name="alert-circle" size={20} color={colors.warning.text} />
            <Text style={styles.rupturesTitre}>Ruptures signalées en ce moment</Text>
          </View>
          {ruptures.slice(0, 5).map((r) => (
            <Pressable
              key={r.medicament.id}
              onPress={() => ouvrir(r.medicament)}
              accessibilityRole="button"
              accessibilityLabel={`${r.medicament.libelle}, manquant dans ${r.nb_pharmacies} pharmacie(s)`}
              style={styles.ruptureLigne}
            >
              <View style={styles.ruptureInfos}>
                <Text style={styles.ruptureNom}>{r.medicament.libelle}</Text>
                <Text style={styles.ruptureMeta}>
                  Manquant dans {r.nb_pharmacies} pharmacie{r.nb_pharmacies > 1 ? 's' : ''}
                </Text>
              </View>
              <Ionicons name="chevron-forward" size={18} color={colors.ink[500]} />
            </Pressable>
          ))}
          <Text style={styles.aide}>
            Signalé par des patients et des pharmaciens. Vérifiez avant de vous déplacer.
          </Text>
        </Card>
      ) : null}

      {/* FN7 — Le catalogue : point d'entrée du comparateur. */}
      <Card style={styles.bloc}>
        <Text style={styles.blocTitre}>Comparer les prix</Text>
        <TextField
          label="Rechercher un médicament"
          value={recherche}
          onChangeText={(v) => {
            setRecherche(v);
            void charger(v.trim() || undefined);
          }}
          autoCapitalize="none"
          maxLength={120}
        />

        {chargement ? (
          <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
        ) : erreur ? (
          <Text style={styles.erreur}>{erreur}</Text>
        ) : medicaments.length === 0 ? (
          <Text style={styles.videTxt}>Aucun médicament ne correspond à cette recherche.</Text>
        ) : (
          medicaments.map((m, i) => (
            <Pressable
              key={m.id}
              onPress={() => ouvrir(m)}
              accessibilityRole="button"
              accessibilityLabel={m.libelle}
              style={[styles.ligne, i > 0 && styles.bordure]}
            >
              <View style={styles.ligneInfos}>
                <Text style={styles.ligneNom}>{m.libelle}</Text>
                <Text style={styles.ligneMeta}>
                  {m.categorie}
                  {m.ordonnance_requise ? ' · sur ordonnance' : ''}
                </Text>
              </View>
              {m.prix_reference_cfa !== null ? (
                <View style={styles.prixRef}>
                  <Text style={styles.prixRefTxt}>{m.prix_reference_cfa} F</Text>
                  <Text style={styles.prixRefLbl}>référence</Text>
                </View>
              ) : null}
              <Ionicons name="chevron-forward" size={20} color={colors.ink[500]} />
            </Pressable>
          ))
        )}
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[5] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text, marginTop: spacing[4] },

  bloc: { marginBottom: spacing[5] },
  blocTitre: { ...typography.h2, color: colors.blue[900], marginBottom: spacing[3] },
  videTxt: { ...typography.body, color: colors.ink[500], marginTop: spacing[4] },
  aide: { ...typography.caption, color: colors.ink[500], marginTop: spacing[3] },
  bordure: { borderTopWidth: 1, borderTopColor: colors.line },

  // Ruptures
  ruptures: { borderWidth: 1, borderColor: colors.warning.solid, backgroundColor: colors.warning.bg },
  rupturesEntete: { flexDirection: 'row', alignItems: 'center', gap: spacing[2], marginBottom: spacing[2] },
  rupturesTitre: { ...typography.bodyStrong, color: colors.warning.text, flex: 1 },
  ruptureLigne: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing[2] },
  ruptureInfos: { flex: 1 },
  ruptureNom: { ...typography.bodyStrong, color: colors.ink[900] },
  ruptureMeta: { ...typography.caption, color: colors.ink[700], marginTop: 2 },

  // Catalogue
  ligne: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing[3] },
  ligneInfos: { flex: 1 },
  ligneNom: { ...typography.bodyStrong, color: colors.blue[900] },
  ligneMeta: { ...typography.caption, color: colors.ink[500], marginTop: 2 },
  prixRef: {
    alignItems: 'flex-end',
    marginRight: spacing[2],
    backgroundColor: colors.blue[100],
    borderRadius: radius.sm,
    paddingVertical: spacing[1],
    paddingHorizontal: spacing[2],
  },
  prixRefTxt: { ...typography.bodyStrong, color: colors.blue[700] },
  prixRefLbl: { ...typography.caption, color: colors.blue[700] },
});
