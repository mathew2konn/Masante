import React, { useCallback, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../../../src/components/Screen';
import { ScreenHeader } from '../../../../src/components/ScreenHeader';
import { Card } from '../../../../src/components/Card';
import { obtenirCalendrierVaccinal } from '../../../../src/api/vaccins';
import { messageErreur } from '../../../../src/utils/erreurs';
import { formatDateFr } from '../../../../src/utils/dates';
import type { CalendrierVaccinal, EcheanceVaccinale } from '../../../../src/types/vaccin';
import { LIBELLE_STATUT_ECHEANCE, StatutEcheanceVaccinale } from '@masante/shared';
import { colors, radius, spacing, typography } from '../../../../src/theme/theme';

/**
 * Calendrier vaccinal d'un membre (P6.8b, CDC_09 §8).
 *
 * ═══ LA QUESTION À LAQUELLE CET ÉCRAN RÉPOND ═══
 *
 * Pas « ce vaccin est-il fait ? » — le carnet le disait déjà — mais « **qu'est-ce qui est dû, pour
 * cette personne-là, aujourd'hui ?** ». C'est le seul référentiel de P6 qui s'adresse directement
 * au citoyen : un calendrier vaccinal que le parent ne voit pas ne sert qu'aux statistiques.
 *
 * ═══ FRONTIÈRE : RIEN N'EST CALCULÉ ICI ═══
 *
 * Le statut de chaque échéance, la date prévue, l'âge, le caractère obligatoire arrivent déjà
 * décidés par le serveur, qui les établit depuis la **version publiée** du calendrier national.
 * Test de fin de module (CDC_01 §0.1) : « quelles règles métier ce module calcule-t-il ? » →
 * aucune. L'écran groupe et met en forme, rien d'autre.
 *
 * ═══ CE QU'IL DIT DE LUI-MÊME, ET QU'IL NE MASQUE JAMAIS ═══
 *
 * Tant que des échéances viennent du jeu de démonstration, l'avertissement du serveur est affiché
 * **en tête**, pas en bas de page : une donnée de démonstration qui ne se signale pas finit par
 * être prise pour une donnée de référence. Et le calendrier ne remplace pas un professionnel de
 * santé — cette phrase vient du serveur, elle n'est pas réécrite ici.
 */
export default function CalendrierVaccinalScreen() {
  const { id, prenom, nom } = useLocalSearchParams<{ id: string; prenom?: string; nom?: string }>();
  const membreId = Number(id);

  const [calendrier, setCalendrier] = useState<CalendrierVaccinal | null>(null);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  useFocusEffect(
    useCallback(() => {
      let actif = true;
      (async () => {
        setErreur(null);
        try {
          const donnees = await obtenirCalendrierVaccinal(membreId);
          if (actif) setCalendrier(donnees);
        } catch (e) {
          // Le message du serveur est repris TEL QUEL : quand aucune version du calendrier n'est
          // en vigueur, il répond un 503 explicite. Le remplacer par « une erreur est survenue »
          // ferait chercher une panne réseau là où il manque une publication.
          if (actif) setErreur(messageErreur(e));
        } finally {
          if (actif) setChargement(false);
        }
      })();

      return () => {
        actif = false;
      };
    }, [membreId]),
  );

  const titre = [prenom, nom].filter(Boolean).join(' ') || 'Calendrier vaccinal';

  if (chargement) {
    return (
      <Screen>
        <ScreenHeader title="Calendrier vaccinal" subtitle={titre} onBack={() => router.back()} />
        <ActivityIndicator style={styles.loader} />
      </Screen>
    );
  }

  if (erreur || !calendrier) {
    return (
      <Screen>
        <ScreenHeader title="Calendrier vaccinal" subtitle={titre} onBack={() => router.back()} />
        <Card>
          <Text style={styles.erreur}>{erreur ?? 'Calendrier indisponible.'}</Text>
        </Card>
      </Screen>
    );
  }

  // Le regroupement est un choix de PRÉSENTATION : le serveur a déjà trié par date d'exigibilité,
  // et l'ordre des groupes ci-dessous répond à ce qu'un parent cherche d'abord — ce qui manque.
  const groupes: { statut: StatutEcheanceVaccinale; titre: string; aide?: string }[] = [
    { statut: StatutEcheanceVaccinale.EN_RETARD, titre: 'En retard' },
    { statut: StatutEcheanceVaccinale.A_FAIRE, titre: 'À faire maintenant' },
    { statut: StatutEcheanceVaccinale.A_VENIR, titre: 'À venir' },
    { statut: StatutEcheanceVaccinale.FAIT, titre: 'Déjà fait' },
    {
      statut: StatutEcheanceVaccinale.HORS_DELAI,
      titre: 'Fenêtre de rattrapage passée',
      aide: "Le calendrier national ne prévoit plus ces doses à cet âge. Parlez-en à un professionnel de santé.",
    },
  ];

  return (
    <Screen>
      <ScreenHeader title="Calendrier vaccinal" subtitle={titre} onBack={() => router.back()} />

      {/* L'AVERTISSEMENT EST EN TÊTE, ET IL VIENT DU SERVEUR. Le placer en bas de page reviendrait
          à le rendre facultatif à la lecture. */}
      {calendrier.avertissement ? (
        <Card style={styles.avertissement}>
          <View style={styles.avertissementLigne}>
            <Ionicons name="alert-circle-outline" size={20} color={colors.warning.text} />
            <Text style={styles.avertissementTexte}>{calendrier.avertissement}</Text>
          </View>
        </Card>
      ) : null}

      {calendrier.incertitude ? (
        <Card style={styles.bloc}>
          <Text style={styles.aide}>{calendrier.incertitude}</Text>
        </Card>
      ) : null}

      {calendrier.echeances.length === 0 && !calendrier.incertitude ? (
        <Card style={styles.bloc}>
          <Text style={styles.aide}>
            Aucune échéance au calendrier national pour cet âge.
          </Text>
        </Card>
      ) : null}

      {groupes.map((groupe) => {
        const lignes = calendrier.echeances.filter((e) => e.statut === groupe.statut);

        if (lignes.length === 0) {
          return null;
        }

        return (
          <Card key={groupe.statut} style={styles.bloc}>
            <Text style={styles.blocTitre}>
              {groupe.titre} ({lignes.length})
            </Text>
            {groupe.aide ? <Text style={styles.aide}>{groupe.aide}</Text> : null}
            {lignes.map((e) => (
              <LigneEcheance key={`${e.vaccin_code}-${e.numero_dose}`} echeance={e} />
            ))}
          </Card>
        );
      })}

      <Text style={styles.pied}>
        Établi d'après la version {calendrier.version} du calendrier vaccinal national.
      </Text>
    </Screen>
  );
}

/** Une échéance. Aucun jugement : on affiche ce que le serveur a qualifié. */
function LigneEcheance({ echeance }: { echeance: EcheanceVaccinale }) {
  const ton = TONS[echeance.statut];

  return (
    <View style={styles.ligne}>
      <View style={styles.ligneEntete}>
        <Text style={styles.ligneTitre}>
          {echeance.vaccin_libelle}
          {echeance.nb_doses > 1 ? ` · dose ${echeance.numero_dose}/${echeance.nb_doses}` : ''}
        </Text>
        <View style={[styles.badge, { backgroundColor: ton.fond }]}>
          <Text style={[styles.badgeTexte, { color: ton.texte }]}>
            {LIBELLE_STATUT_ECHEANCE[echeance.statut]}
          </Text>
        </View>
      </View>

      <Text style={styles.ligneDetail}>
        {echeance.libelle_echeance ?? `${echeance.age_jours_du} jours`}
        {' · prévue le '}
        {formatDateFr(echeance.date_prevue)}
      </Text>

      {echeance.obligatoire ? (
        <Text style={styles.ligneObligatoire}>Obligatoire au calendrier national</Text>
      ) : null}

      {/* Le retrait du marché se DIT, il ne bloque rien : refuser d'inscrire une dose réellement
          administrée effacerait un fait médical. */}
      {echeance.statut_marche === 'retire' ? (
        <Text style={styles.ligneAlerte}>Ce vaccin est retiré du référentiel national.</Text>
      ) : null}

      {echeance.de_demonstration ? (
        <Text style={styles.ligneDemo}>Échéance issue du jeu de démonstration</Text>
      ) : null}
    </View>
  );
}

const TONS: Record<StatutEcheanceVaccinale, { fond: string; texte: string }> = {
  [StatutEcheanceVaccinale.FAIT]: { fond: colors.success.bg, texte: colors.success.text },
  [StatutEcheanceVaccinale.A_FAIRE]: { fond: colors.warning.bg, texte: colors.warning.text },
  [StatutEcheanceVaccinale.EN_RETARD]: { fond: colors.danger.bg, texte: colors.danger.text },
  [StatutEcheanceVaccinale.A_VENIR]: { fond: colors.blue[100], texte: colors.blue[700] },
  [StatutEcheanceVaccinale.HORS_DELAI]: { fond: colors.line, texte: colors.ink[700] },
};

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.body, color: colors.danger.text },
  bloc: { marginTop: spacing[4] },
  blocTitre: { ...typography.bodyStrong, color: colors.ink[900], marginBottom: spacing[2] },
  aide: { ...typography.caption, color: colors.ink[500], marginBottom: spacing[2] },
  avertissement: { marginTop: spacing[4], backgroundColor: colors.warning.bg },
  avertissementLigne: { flexDirection: 'row', gap: spacing[2], alignItems: 'flex-start' },
  avertissementTexte: { ...typography.caption, color: colors.warning.text, flex: 1 },
  ligne: {
    borderTopWidth: 1,
    borderTopColor: colors.line,
    paddingTop: spacing[3],
    marginTop: spacing[3],
  },
  ligneEntete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing[2] },
  ligneTitre: { ...typography.body, color: colors.ink[900], fontWeight: '600', flex: 1 },
  ligneDetail: { ...typography.caption, color: colors.ink[500], marginTop: spacing[1] },
  ligneObligatoire: { ...typography.caption, color: colors.blue[700], marginTop: spacing[1] },
  ligneAlerte: { ...typography.caption, color: colors.danger.text, marginTop: spacing[1] },
  ligneDemo: { ...typography.caption, color: colors.ink[500], marginTop: spacing[1], fontStyle: 'italic' },
  badge: { paddingHorizontal: spacing[2], paddingVertical: 2, borderRadius: radius.sm },
  badgeTexte: { ...typography.caption, fontWeight: '600' },
  pied: { ...typography.caption, color: colors.ink[500], marginTop: spacing[4], textAlign: 'center' },
});
