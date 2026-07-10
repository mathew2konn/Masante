import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../components/Screen';
import { Card } from '../components/Card';
import { ScreenHeader } from '../components/ScreenHeader';
import { lireCache } from '../urgence/carteVitale';
import { SAMU_NUMERO } from '../config/constants';
import { formatDateFr } from '../utils/dates';
import type { FicheVitale } from '../types/urgence';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * Carte vitale d'urgence (CdC FN2) — LECTURE SEULE, HORS CONNEXION, SANS AUTHENTIFICATION.
 *
 * Destinée au secouriste qui trouve une personne inconsciente et prend son téléphone. Elle ne lit
 * QUE le cache local (`carteVitale.ts`), jamais l'API : ni réseau, ni token, ni PIN. Le dossier
 * médical complet reste, lui, derrière le verrou applicatif.
 *
 * Hiérarchie visuelle voulue : le groupe sanguin et les allergies d'abord — ce sont les deux
 * informations qui changent un geste de secours dans les premières secondes.
 */
export function CarteVitaleEcran({ onFermer }: { onFermer: () => void }) {
  const [fiches, setFiches] = useState<FicheVitale[]>([]);
  const [index, setIndex] = useState(0);
  const [chargement, setChargement] = useState(true);

  useEffect(() => {
    void lireCache().then((f) => {
      setFiches(f);
      setChargement(false);
    });
  }, []);

  const appeler = useCallback((numero: string) => {
    void Linking.openURL(`tel:${numero}`);
  }, []);

  if (chargement) {
    return (
      <Screen scroll={false}>
        <ScreenHeader title="Carte vitale" onBack={onFermer} />
        <ActivityIndicator color={colors.blue[600]} style={styles.centre} />
      </Screen>
    );
  }

  if (fiches.length === 0) {
    return (
      <Screen>
        <ScreenHeader title="Carte vitale d'urgence" onBack={onFermer} />
        <Card style={styles.bloc}>
          <Text style={styles.corps}>
            Aucune carte vitale n'est enregistrée sur cet appareil.
          </Text>
          <Text style={styles.muted}>
            Connectez-vous, puis activez la carte vitale d'un membre depuis Carnet › Carte vitale
            d'urgence. Elle sera alors consultable ici, même sans connexion.
          </Text>
        </Card>
      </Screen>
    );
  }

  const fiche = fiches[index];

  return (
    <Screen>
      <ScreenHeader title="Carte vitale d'urgence" onBack={onFermer} />

      {/* Sélecteur de membre, seulement si plusieurs cartes sont enregistrées. */}
      {fiches.length > 1 && (
        <View style={styles.onglets}>
          {fiches.map((f, i) => (
            <Pressable
              key={f.membre_id}
              onPress={() => setIndex(i)}
              accessibilityRole="button"
              accessibilityState={{ selected: i === index }}
              style={[styles.onglet, i === index && styles.ongletActif]}
            >
              <Text style={[styles.ongletTxt, i === index && styles.ongletTxtActif]}>{f.prenom}</Text>
            </Pressable>
          ))}
        </View>
      )}

      {/* Identité + groupe sanguin : ce que le secouriste lit en premier. */}
      <Card style={styles.identite}>
        <Text style={styles.nom}>
          {fiche.prenom} {fiche.nom}
        </Text>
        <Text style={styles.muted}>
          {[fiche.age !== null ? `${fiche.age} ans` : null, fiche.sexe].filter(Boolean).join(' · ') || '—'}
        </Text>

        <View style={styles.groupe}>
          <Text style={styles.groupeLabel}>Groupe sanguin</Text>
          <Text style={styles.groupeValeur}>{fiche.groupe_sanguin ?? 'Inconnu'}</Text>
        </View>
      </Card>

      <Bloc titre="Allergies" icone="warning-outline" alerte>
        {fiche.allergies.length === 0 ? (
          <Text style={styles.muted}>Aucune allergie connue.</Text>
        ) : (
          fiche.allergies.map((a) => (
            <Text key={a} style={styles.itemAlerte}>
              • {a}
            </Text>
          ))
        )}
      </Bloc>

      <Bloc titre="Maladies chroniques" icone="medkit-outline">
        {fiche.maladies_chroniques.length === 0 ? (
          <Text style={styles.muted}>Aucune maladie chronique connue.</Text>
        ) : (
          fiche.maladies_chroniques.map((m) => (
            <View key={m.libelle} style={styles.item}>
              <Text style={styles.corps}>• {m.libelle}</Text>
              {m.traitement ? <Text style={styles.muted}>   Traitement : {m.traitement}</Text> : null}
            </View>
          ))
        )}
      </Bloc>

      <Bloc titre="Contacts d'urgence" icone="call-outline">
        {fiche.contacts_urgence.length === 0 ? (
          <Text style={styles.muted}>Aucun contact enregistré.</Text>
        ) : (
          fiche.contacts_urgence.map((c) => (
            <Pressable
              key={c.telephone}
              onPress={() => appeler(c.telephone)}
              accessibilityRole="button"
              accessibilityLabel={`Appeler ${c.nom}, ${c.lien}`}
              style={styles.contact}
            >
              <View>
                <Text style={styles.corps}>
                  {c.nom} <Text style={styles.muted}>· {c.lien}</Text>
                </Text>
                <Text style={styles.tel}>{c.telephone}</Text>
              </View>
              <Ionicons name="call" size={22} color={colors.success.solid} />
            </Pressable>
          ))
        )}
      </Bloc>

      {fiche.vaccinations_essentielles.length > 0 && (
        <Bloc titre="Vaccinations essentielles" icone="shield-checkmark-outline">
          {fiche.vaccinations_essentielles.map((v) => (
            <Text key={v.vaccin} style={styles.corps}>
              • {v.vaccin}
              {v.date ? <Text style={styles.muted}> — {formatDateFr(v.date)}</Text> : null}
            </Text>
          ))}
        </Bloc>
      )}

      <Pressable
        onPress={() => appeler(SAMU_NUMERO)}
        accessibilityRole="button"
        accessibilityLabel={`Appeler le SAMU au ${SAMU_NUMERO}`}
        style={({ pressed }) => [styles.samu, pressed && styles.samuPresse]}
      >
        <Text style={styles.samuTxt}>Appeler le SAMU — {SAMU_NUMERO}</Text>
      </Pressable>

      <Text style={styles.pied}>
        Fiche enregistrée le {formatDateFr(fiche.genere_le)}. Données d'urgence uniquement : le dossier
        médical complet reste protégé.
      </Text>
    </Screen>
  );
}

/** Bloc de section. `alerte` teinte le bloc en rouge (allergies : information critique). */
function Bloc({
  titre,
  icone,
  alerte,
  children,
}: {
  titre: string;
  icone: keyof typeof Ionicons.glyphMap;
  alerte?: boolean;
  children: React.ReactNode;
}) {
  return (
    <Card style={[styles.bloc, alerte && styles.blocAlerte]}>
      <View style={styles.titre}>
        <Ionicons name={icone} size={18} color={alerte ? colors.danger.solid : colors.blue[700]} />
        <Text style={[styles.titreTxt, alerte && styles.titreAlerte]}>{titre}</Text>
      </View>
      {children}
    </Card>
  );
}

const styles = StyleSheet.create({
  centre: { marginTop: spacing[8] },
  identite: { marginBottom: spacing[4], gap: spacing[1] },
  nom: { ...typography.h1, color: colors.blue[900] },
  groupe: {
    marginTop: spacing[3],
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: colors.danger.bg,
    borderRadius: radius.md,
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[3],
  },
  groupeLabel: { ...typography.bodyStrong, color: colors.danger.text },
  groupeValeur: { ...typography.h1, color: colors.danger.solid },
  bloc: { marginBottom: spacing[4], gap: spacing[2] },
  blocAlerte: { borderWidth: 1, borderColor: colors.danger.solid },
  titre: { flexDirection: 'row', alignItems: 'center', gap: spacing[2] },
  titreTxt: { ...typography.h2, color: colors.blue[900] },
  titreAlerte: { color: colors.danger.text },
  corps: { ...typography.body, color: colors.ink[900] },
  muted: { ...typography.body, color: colors.ink[500] },
  item: { gap: 2 },
  itemAlerte: { ...typography.bodyStrong, color: colors.danger.text },
  contact: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: spacing[2],
    minHeight: 48,
  },
  tel: { ...typography.bodyStrong, color: colors.blue[700] },
  onglets: { flexDirection: 'row', gap: spacing[2], marginBottom: spacing[4], flexWrap: 'wrap' },
  onglet: {
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[2],
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.line,
  },
  ongletActif: { backgroundColor: colors.blue[600], borderColor: colors.blue[600] },
  ongletTxt: { ...typography.bodyStrong, color: colors.ink[700] },
  ongletTxtActif: { color: '#FFFFFF' },
  samu: {
    height: 52,
    borderRadius: radius.pill,
    backgroundColor: colors.danger.solid,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: spacing[2],
  },
  samuPresse: { backgroundColor: '#B91C1C' },
  samuTxt: { ...typography.button, color: '#FFFFFF' },
  pied: { ...typography.caption, color: colors.ink[500], marginTop: spacing[4], textAlign: 'center' },
});
