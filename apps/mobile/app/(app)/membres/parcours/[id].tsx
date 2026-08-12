import React, { useCallback, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../../../src/components/Screen';
import { ScreenHeader } from '../../../../src/components/ScreenHeader';
import { Card } from '../../../../src/components/Card';
import { chargerParcours } from '../../../../src/api/parcours';
import { sectionParSlug } from '../../../../src/carnet/registre';
import { messageErreur } from '../../../../src/utils/erreurs';
import { formatDateHeureFr } from '../../../../src/utils/dates';
import type {
  ContributionParcours,
  EntreeParcours,
  FicheParcours,
  VisiteParcours,
} from '../../../../src/types/parcours';
import { colors, radius, spacing, typography } from '../../../../src/theme/theme';

/**
 * Fiche de parcours d'un carnet (carnet familial partagé, incrément D2).
 *
 * LE SCÉNARIO : un proche a emmené l'enfant à l'hôpital et propose un ajout au carnet. Avant de
 * valider, un responsable veut savoir qui a ouvert le dossier, dans quel établissement, et ce qui
 * a été écrit. Cet écran assemble ces faits.
 *
 * QUI PEUT LE VOIR (décision propriétaire) : toute la famille — propriétaire, délégués en lecture,
 * second responsable. Mais VALIDER reste aux seuls responsables : aucune action de décision n'est
 * proposée ici, elle vit sur l'écran « Ajouts à valider ». Voir n'est pas décider.
 *
 * FRONTIÈRE : rien n'est déduit ici. Les visites, leurs libellés, la séparation entre ce qui est
 * lié et ce qui n'est que rapproché viennent du serveur, déjà qualifiés.
 */
export default function ParcoursMembreScreen() {
  const { id, prenom, nom } = useLocalSearchParams<{ id: string; prenom?: string; nom?: string }>();
  const membreId = Number(id);

  const [fiche, setFiche] = useState<FicheParcours | null>(null);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  useFocusEffect(
    useCallback(() => {
      let actif = true;
      (async () => {
        setErreur(null);
        try {
          const data = await chargerParcours(membreId);
          if (actif) setFiche(data);
        } catch (e) {
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

  const nomComplet =
    [prenom, nom].filter(Boolean).join(' ') ||
    (fiche ? `${fiche.membre.prenom} ${fiche.membre.nom}` : 'ce membre');

  const vide =
    fiche !== null &&
    fiche.visites.length === 0 &&
    fiche.autres_entrees.length === 0 &&
    fiche.contributions.length === 0;

  return (
    <Screen>
      <ScreenHeader
        title="Fiche de parcours"
        subtitle={`Carnet de ${nomComplet}`}
        onBack={() => router.back()}
      />

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      ) : erreur ? (
        <Text style={styles.erreur}>{erreur}</Text>
      ) : vide ? (
        <FicheVide />
      ) : fiche ? (
        <View style={styles.contenu}>
          {fiche.visites.length > 0 ? (
            <>
              <Text style={styles.section}>Passages en établissement</Text>
              {fiche.visites.map((v) => (
                <Visite key={`visite-${v.id}`} visite={v} />
              ))}
            </>
          ) : null}

          {fiche.autres_entrees.length > 0 ? (
            <>
              <Text style={styles.section}>Autres entrées médicales de la période</Text>
              {/* Le lien avec une consultation n'est PAS affirmé : ces entrées viennent bien d'un
                  soignant, mais rien dans le journal ne dit à quel passage elles se rattachent. */}
              <Card style={styles.avertissement}>
                <Text style={styles.avertissementTxt}>
                  Ces éléments ont été inscrits par un soignant, mais ne sont rattachés à aucune
                  consultation enregistrée ci-dessus.
                </Text>
              </Card>
              <Card>
                {fiche.autres_entrees.map((e) => (
                  <Entree key={`autre-${e.section}-${e.id}`} entree={e} />
                ))}
              </Card>
            </>
          ) : null}

          {fiche.contributions.length > 0 ? (
            <>
              <Text style={styles.section}>Ajouts proposés par la famille</Text>
              <Card>
                {fiche.contributions.map((c) => (
                  <Contribution key={`contrib-${c.id}`} contribution={c} />
                ))}
              </Card>
            </>
          ) : null}

          <Text style={styles.limite}>
            Cette fiche montre ce qui a été enregistré dans MaSanté. Si l'hôpital n'a pas scanné le
            QR du carnet, la visite n'y figure pas — ce n'est pas la preuve qu'elle n'a pas eu lieu.
          </Text>
        </View>
      ) : null}
    </Screen>
  );
}

/** Une visite : qui, où, par quelle voie, combien de temps, et ce qui a été écrit. */
function Visite({ visite }: { visite: VisiteParcours }) {
  const urgence = visite.type === 'bris_de_glace';

  return (
    <Card style={[styles.visite, urgence ? styles.visiteUrgente : null]}>
      <View style={styles.entete}>
        <View style={[styles.pastille, urgence ? styles.pastilleUrgente : null]}>
          <Ionicons
            name={urgence ? 'alert-circle-outline' : 'business-outline'}
            size={18}
            color={urgence ? colors.danger.text : colors.blue[600]}
          />
        </View>
        <View style={styles.enteteTexte}>
          <Text style={[styles.type, urgence ? styles.typeUrgent : null]}>{visite.type_libelle}</Text>
          <Text style={styles.date}>{visite.a ? formatDateHeureFr(visite.a) : '—'}</Text>
        </View>
      </View>

      <Ligne
        libelle="Établissement"
        valeur={visite.etablissement ?? 'Établissement non enregistré'}
        attenue={visite.etablissement === null}
      />
      {visite.agent ? <Ligne libelle="Soignant" valeur={visite.agent} /> : null}
      <Ligne
        libelle="Durée"
        valeur={
          visite.cloturee && visite.duree_minutes !== null
            ? `${visite.duree_minutes} min`
            : 'Consultation non clôturée'
        }
        attenue={!visite.cloturee}
      />
      {visite.sections_consultees.length > 0 ? (
        <Ligne
          libelle="Consulté"
          valeur={visite.sections_consultees.map((s) => sectionParSlug(s)?.titre ?? s).join(', ')}
        />
      ) : null}

      {/* Le motif d'un accès sans consentement doit rester explicable par ceux qu'il concerne. */}
      {visite.motif_urgence ? (
        <View style={styles.motif}>
          <Text style={styles.motifLib}>Motif de l'urgence</Text>
          <Text style={styles.motifTxt}>{visite.motif_urgence}</Text>
        </View>
      ) : null}

      {visite.entrees.length > 0 ? (
        <View style={styles.ecrit}>
          <Text style={styles.ecritTitre}>Écrit pendant cette consultation</Text>
          {visite.entrees.map((e) => (
            <Entree key={`e-${e.section}-${e.id}`} entree={e} />
          ))}
        </View>
      ) : null}
    </Card>
  );
}

/** Une entrée du carnet, nommée sans son contenu clinique. */
function Entree({ entree }: { entree: EntreeParcours }) {
  const supprimee = entree.toujours_au_carnet === false;

  return (
    <View style={styles.entree}>
      <Ionicons
        name={supprimee ? 'remove-circle-outline' : 'document-text-outline'}
        size={16}
        color={supprimee ? colors.disabled : colors.blue[500]}
      />
      <Text style={[styles.entreeTxt, supprimee ? styles.entreeSupprimee : null]}>
        {entree.libelle}
      </Text>
    </View>
  );
}

/** Une contribution familiale et son statut — décidé par le serveur, jamais ici. */
function Contribution({ contribution }: { contribution: ContributionParcours }) {
  const libelleStatut: Record<ContributionParcours['statut'], string> = {
    BROUILLON: 'En attente de validation',
    VALIDEE: 'Validé',
    REJETEE: 'Refusé',
  };

  return (
    <View style={styles.entree}>
      <Ionicons name="person-add-outline" size={16} color={colors.blue[500]} />
      <View style={styles.contribTexte}>
        <Text style={styles.entreeTxt}>
          {sectionParSlug(contribution.section)?.titre ?? contribution.section}
          {contribution.auteur ? ` — proposé par ${contribution.auteur}` : ''}
        </Text>
        <Text style={styles.contribStatut}>{libelleStatut[contribution.statut]}</Text>
      </View>
    </View>
  );
}

function Ligne({
  libelle,
  valeur,
  attenue = false,
}: {
  libelle: string;
  valeur: string;
  attenue?: boolean;
}) {
  return (
    <View style={styles.ligne}>
      <Text style={styles.ligneLib}>{libelle}</Text>
      <Text style={[styles.ligneVal, attenue ? styles.ligneAttenuee : null]}>{valeur}</Text>
    </View>
  );
}

/** Une fiche vide n'est pas une erreur — mais elle ne prouve rien non plus, et doit le dire. */
function FicheVide() {
  return (
    <Card style={styles.vide}>
      <Ionicons name="reader-outline" size={32} color={colors.blue[400]} />
      <Text style={styles.videTxt}>Aucun passage enregistré</Text>
      <Text style={styles.videSous}>
        Un passage n'apparaît ici que si l'établissement a scanné le QR du carnet. Une fiche vide ne
        signifie pas qu'il ne s'est rien passé.
      </Text>
    </Card>
  );
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },
  contenu: { gap: spacing[3] },
  section: { ...typography.bodyStrong, color: colors.blue[900], marginTop: spacing[2] },
  visite: {},
  visiteUrgente: { borderLeftWidth: 3, borderLeftColor: colors.danger.text },
  entete: { flexDirection: 'row', alignItems: 'center', marginBottom: spacing[2] },
  pastille: {
    width: 36,
    height: 36,
    borderRadius: radius.pill,
    backgroundColor: colors.blue[100],
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing[3],
  },
  pastilleUrgente: { backgroundColor: colors.danger.bg },
  enteteTexte: { flex: 1 },
  type: { ...typography.bodyStrong, color: colors.blue[900] },
  typeUrgent: { color: colors.danger.text },
  date: { ...typography.caption, color: colors.ink[700], marginTop: 2 },
  ligne: { flexDirection: 'row', justifyContent: 'space-between', paddingTop: spacing[2] },
  ligneLib: { ...typography.caption, color: colors.ink[500], marginRight: spacing[4] },
  ligneVal: { ...typography.caption, color: colors.ink[900], flex: 1, textAlign: 'right' },
  ligneAttenuee: { color: colors.disabled, fontStyle: 'italic' },
  motif: { marginTop: spacing[3] },
  motifLib: { ...typography.caption, color: colors.ink[500] },
  motifTxt: { ...typography.caption, color: colors.ink[900], marginTop: 2 },
  ecrit: { marginTop: spacing[3], borderTopWidth: 1, borderTopColor: colors.blue[100], paddingTop: spacing[2] },
  ecritTitre: { ...typography.caption, color: colors.blue[700], marginBottom: spacing[1] },
  entree: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing[2], paddingVertical: spacing[1] },
  entreeTxt: { ...typography.caption, color: colors.ink[900], flex: 1 },
  entreeSupprimee: { color: colors.disabled, fontStyle: 'italic' },
  contribTexte: { flex: 1 },
  contribStatut: { ...typography.caption, color: colors.ink[500], marginTop: 2 },
  avertissement: { backgroundColor: colors.blue[50] },
  avertissementTxt: { ...typography.caption, color: colors.ink[700] },
  limite: { ...typography.caption, color: colors.ink[500], marginTop: spacing[3] },
  vide: { alignItems: 'center' },
  videTxt: { ...typography.bodyStrong, color: colors.ink[700], marginTop: spacing[3] },
  videSous: { ...typography.caption, color: colors.ink[500], textAlign: 'center', marginTop: spacing[1] },
});
