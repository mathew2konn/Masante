import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../components/Screen';
import { ScreenHeader } from '../components/ScreenHeader';
import { Card } from '../components/Card';
import { PrimaryButton } from '../components/PrimaryButton';
import { SecondaryButton } from '../components/SecondaryButton';
import { TextField } from '../components/TextField';
import { DateField } from '../components/DateField';
import {
  ajouterCouverture,
  listerCouvertures,
  rechercherOrganismes,
  supprimerCouverture,
} from '../api/assurances';
import { messageErreur } from '../utils/erreurs';
import { formatDateFr } from '../utils/dates';
import {
  LIBELLE_STATUT_COUVERTURE,
  type Couverture,
  type OrganismeAssurance,
} from '../types/assurance';
import { colors, radius, spacing, typography } from '../theme/theme';

/** Tonalité du badge de statut. Le statut lui-même est CALCULÉ PAR LE SERVEUR — jamais ici. */
const STATUT_TON: Record<string, { bg: string; text: string }> = {
  active: { bg: colors.success.bg, text: colors.success.text },
  expiree: { bg: colors.danger.bg, text: colors.danger.text },
  resiliee: { bg: colors.surfaceMuted, text: colors.ink[500] },
};

/**
 * CouverturesEcran (P6.8d) — les couvertures santé déclarées d'un membre.
 *
 * ═══ CE QU'IL REMPLACE ═══
 *
 * Le bloc « CMU » du formulaire de membre, qui faisait déclarer un numéro, un STATUT et une date
 * comme s'ils étaient des attributs de la personne. Une couverture est un CONTRAT avec un organisme,
 * et le §8 du CDC_06 en enchaîne plusieurs sur la même facture.
 *
 * ═══ FRONTIÈRE ═══
 *
 * Rien n'est calculé ici : le `statut` (en cours / expirée / résiliée), le libellé de la famille et
 * la mention de provenance arrivent décidés par le serveur. L'écran affiche et envoie.
 *
 * ═══ CE QUE L'ÉCRAN NE PROMET PAS ═══
 *
 * La mention de provenance est **servie par l'API** et affichée telle quelle. MaSanté ne vérifie
 * rien auprès d'un organisme — l'étape 2 du §8.1 du CDC_06 n'existe pas dans ce projet — et l'écran
 * ne doit pas laisser croire le contraire.
 *
 * ═══ HORS LIGNE ═══
 *
 * La recherche au registre national se tait (motif P6.6b) : une recherche impossible n'est pas une
 * panne, et la saisie libre reste ouverte. Ce qu'on ne peut pas faire hors ligne, c'est enregistrer
 * — et le message d'erreur du serveur le dit.
 */
export function CouverturesEcran({
  membreId,
  nomMembre,
  proprietaire,
}: {
  membreId: number;
  nomMembre?: string;
  proprietaire: boolean;
}) {
  const [couvertures, setCouvertures] = useState<Couverture[]>([]);
  const [mention, setMention] = useState<string | null>(null);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);
  const [formulaire, setFormulaire] = useState(false);

  const charger = useCallback(async () => {
    setErreur(null);
    setChargement(true);
    try {
      const reponse = await listerCouvertures(membreId);
      setCouvertures(reponse.couvertures);
      setMention(reponse.mention_provenance ?? null);
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, [membreId]);

  useEffect(() => {
    charger();
  }, [charger]);

  const retirer = async (couverture: Couverture) => {
    try {
      await supprimerCouverture(membreId, couverture.id);
      await charger();
    } catch (e) {
      setErreur(messageErreur(e));
    }
  };

  return (
    <Screen>
      <ScreenHeader
        title="Couvertures santé"
        subtitle={nomMembre ? `Assuré : ${nomMembre}` : undefined}
        onBack={() => router.back()}
      />

      {mention ? (
        <View style={styles.mention}>
          <Ionicons name="information-circle-outline" size={16} color={colors.ink[700]} />
          <Text style={styles.mentionTxt}>{mention}</Text>
        </View>
      ) : null}

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      ) : (
        <>
          {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}

          {couvertures.length === 0 ? (
            <Card style={styles.vide}>
              <Text style={styles.videTitre}>Aucune couverture déclarée</Text>
              <Text style={styles.videTxt}>
                CMU, mutuelle, assurance d'entreprise… Déclarez-en autant que vous en avez : elles
                s'additionnent sur une même facture.
              </Text>
            </Card>
          ) : (
            couvertures.map((c) => (
              <Card key={c.id} style={styles.ligne}>
                <View style={styles.ligneEntete}>
                  <Text style={styles.organisme}>
                    {c.organisme_sigle ?? c.organisme_nom ?? 'Organisme non renseigné'}
                  </Text>
                  <View
                    style={[
                      styles.statutBadge,
                      { backgroundColor: (STATUT_TON[c.statut] ?? STATUT_TON.resiliee).bg },
                    ]}
                  >
                    <Text
                      style={[
                        styles.statutTxt,
                        { color: (STATUT_TON[c.statut] ?? STATUT_TON.resiliee).text },
                      ]}
                    >
                      {LIBELLE_STATUT_COUVERTURE[c.statut] ?? c.statut}
                    </Text>
                  </View>
                </View>

                {c.organisme_sigle && c.organisme_nom ? (
                  <Text style={styles.organismeNom}>{c.organisme_nom}</Text>
                ) : null}

                {c.type_libelle ? <Text style={styles.famille}>{c.type_libelle}</Text> : null}

                {/*
                  Le témoin de l'écart (motif E4) : dit à l'assuré ce que MaSanté sait — et ce
                  qu'elle ne sait pas. Ce n'est pas un reproche, c'est la mesure de ce qui manque
                  au registre.
                */}
                {c.hors_referentiel ? (
                  <View style={styles.hors}>
                    <Text style={styles.horsTxt}>
                      Hors référentiel national — nom saisi par vous, MaSanté ne confirme rien à son
                      sujet.
                    </Text>
                  </View>
                ) : null}

                {c.numero_masque ? <Text style={styles.numero}>{c.numero_masque}</Text> : null}

                <Text style={styles.dates}>
                  {c.date_debut ? `Depuis le ${formatDateFr(c.date_debut)}` : 'Début non renseigné'}
                  {c.date_fin ? ` · jusqu'au ${formatDateFr(c.date_fin)}` : ''}
                  {c.resiliee_le ? ` · résiliée le ${formatDateFr(c.resiliee_le)}` : ''}
                </Text>

                {proprietaire ? (
                  <Pressable
                    onPress={() => retirer(c)}
                    accessibilityRole="button"
                    accessibilityLabel={`Supprimer la couverture ${c.organisme_nom ?? ''}`}
                    style={styles.supprimer}
                  >
                    <Text style={styles.supprimerTxt}>Supprimer</Text>
                  </Pressable>
                ) : null}
              </Card>
            ))
          )}

          {proprietaire ? (
            formulaire ? (
              <FormulaireCouverture
                membreId={membreId}
                onAnnuler={() => setFormulaire(false)}
                onEnregistre={async () => {
                  setFormulaire(false);
                  await charger();
                }}
              />
            ) : (
              <PrimaryButton label="Ajouter une couverture" onPress={() => setFormulaire(true)} />
            )
          ) : null}
        </>
      )}
    </Screen>
  );
}

/**
 * Formulaire d'ajout. L'organisme se choisit AU REGISTRE NATIONAL ; la saisie libre reste ouverte
 * parce que le registre livré est incomplet — imposer le référentiel ferait payer nos lacunes à un
 * assuré réel (motif E4, 3ᵉ application).
 */
function FormulaireCouverture({
  membreId,
  onAnnuler,
  onEnregistre,
}: {
  membreId: number;
  onAnnuler: () => void;
  onEnregistre: () => void;
}) {
  const [recherche, setRecherche] = useState('');
  const [suggestions, setSuggestions] = useState<OrganismeAssurance[]>([]);
  const [choisi, setChoisi] = useState<OrganismeAssurance | null>(null);
  const [libreOuvert, setLibreOuvert] = useState(false);
  const [numero, setNumero] = useState('');
  const [debut, setDebut] = useState<string | null>(null);
  const [fin, setFin] = useState<string | null>(null);
  const [envoi, setEnvoi] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);
  const [avertissements, setAvertissements] = useState<string[]>([]);

  useEffect(() => {
    const q = recherche.trim();

    if (choisi || libreOuvert || q.length < 2) {
      setSuggestions([]);
      return;
    }

    let vivant = true;
    const minuteur = setTimeout(() => {
      rechercherOrganismes(q)
        .then((registre) => {
          if (vivant) setSuggestions(registre.organismes.slice(0, 6));
        })
        // Silence volontaire : hors ligne — ou tant qu'aucune version du référentiel n'est en
        // vigueur (503) — la saisie libre suffit. Une recherche impossible n'est pas une panne.
        .catch(() => {
          if (vivant) setSuggestions([]);
        });
    }, 350);

    return () => {
      vivant = false;
      clearTimeout(minuteur);
    };
  }, [recherche, choisi, libreOuvert]);

  const enregistrer = async () => {
    setErreur(null);
    setEnvoi(true);
    try {
      const { avertissements: recus } = await ajouterCouverture(membreId, {
        organisme_assurance_id: choisi?.id ?? null,
        organisme_libelle: choisi ? null : recherche.trim() || null,
        numero_assure: numero.trim() || null,
        date_debut: debut,
        date_fin: fin,
      });

      // NON BLOQUANTS : la couverture est enregistrée. On les montre avant de refermer, parce
      // qu'un agrément suspendu est précisément ce que l'assuré doit savoir avant un guichet.
      if (recus.length > 0) {
        setAvertissements(recus.map((a) => a.message));
        return;
      }

      onEnregistre();
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setEnvoi(false);
    }
  };

  if (avertissements.length > 0) {
    return (
      <Card style={styles.form}>
        <Text style={styles.formTitre}>Couverture enregistrée</Text>
        {avertissements.map((m) => (
          <Text key={m} style={styles.avertissement}>
            {m}
          </Text>
        ))}
        <PrimaryButton label="J'ai compris" onPress={onEnregistre} />
      </Card>
    );
  }

  return (
    <Card style={styles.form}>
      <Text style={styles.formTitre}>Nouvelle couverture</Text>

      {choisi ? (
        <View style={styles.choisi}>
          <Ionicons name="shield-checkmark-outline" size={16} color={colors.success.text} />
          <View style={styles.choisiCorps}>
            <Text style={styles.choisiNom}>{choisi.nom}</Text>
            <Text style={styles.choisiMeta}>
              {choisi.type_libelle}
              {choisi.code ? ` · ${choisi.code}` : ''}
            </Text>
          </View>
          <Pressable
            onPress={() => {
              setChoisi(null);
              setRecherche('');
            }}
            accessibilityRole="button"
            accessibilityLabel="Changer d'organisme"
          >
            <Text style={styles.changer}>Changer</Text>
          </Pressable>
        </View>
      ) : (
        <>
          <TextField
            label={libreOuvert ? 'Nom de votre organisme' : 'Organisme'}
            value={recherche}
            onChangeText={setRecherche}
            placeholder={libreOuvert ? "Nom tel qu'il figure sur votre carte" : 'CNAM, mutuelle…'}
            maxLength={200}
          />

          {!libreOuvert && suggestions.length > 0 ? (
            <View style={styles.suggestions}>
              {suggestions.map((o) => (
                <Pressable
                  key={o.id}
                  onPress={() => setChoisi(o)}
                  accessibilityRole="button"
                  accessibilityLabel={`Choisir ${o.nom}`}
                  style={styles.suggestion}
                >
                  <Text style={styles.suggestionNom}>{o.nom}</Text>
                  <Text style={styles.suggestionMeta}>{o.type_libelle}</Text>
                </Pressable>
              ))}
            </View>
          ) : null}

          {!libreOuvert ? (
            <Pressable
              onPress={() => setLibreOuvert(true)}
              accessibilityRole="button"
              accessibilityLabel="Mon organisme n'est pas dans la liste"
            >
              <Text style={styles.lien}>Mon organisme n'est pas dans la liste</Text>
            </Pressable>
          ) : (
            <Text style={styles.aide}>
              Votre saisie sera conservée telle quelle. MaSanté ne pourra rien confirmer au sujet de
              cet organisme tant qu'il ne figure pas au registre national.
            </Text>
          )}
        </>
      )}

      <TextField
        label="Numéro d'assuré (facultatif)"
        value={numero}
        onChangeText={setNumero}
        placeholder="Numéro figurant sur votre carte"
        autoCapitalize="characters"
        maxLength={60}
      />
      <Text style={styles.aide}>
        Il est chiffré sur le serveur et n'en ressort jamais en entier : seuls les quatre derniers
        chiffres s'affichent.
      </Text>

      <DateField label="Début (facultatif)" value={debut} onChange={setDebut} placeholder="Sélectionner la date" />
      <View style={styles.espaceHaut}>
        <DateField label="Fin (facultatif)" value={fin} onChange={setFin} placeholder="Sélectionner la date" />
      </View>

      {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}

      <View style={styles.espaceHaut}>
        <PrimaryButton label="Enregistrer" onPress={enregistrer} loading={envoi} />
        <SecondaryButton label="Annuler" onPress={onAnnuler} />
      </View>
    </Card>
  );
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text, marginBottom: spacing[3] },

  mention: {
    flexDirection: 'row',
    gap: spacing[2],
    alignItems: 'flex-start',
    backgroundColor: colors.surfaceMuted,
    borderRadius: radius.md,
    padding: spacing[3],
    marginBottom: spacing[4],
  },
  mentionTxt: { ...typography.caption, color: colors.ink[700], flex: 1 },

  vide: { marginBottom: spacing[5] },
  videTitre: { ...typography.h2, color: colors.blue[900], marginBottom: spacing[2] },
  videTxt: { ...typography.body, color: colors.ink[700] },

  ligne: { marginBottom: spacing[4] },
  ligneEntete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  organisme: { ...typography.h2, color: colors.blue[900], flex: 1, marginRight: spacing[2] },
  organismeNom: { ...typography.caption, color: colors.ink[700], marginTop: 2 },
  famille: { ...typography.caption, color: colors.ink[500], marginTop: spacing[1] },
  statutBadge: { borderRadius: radius.pill, paddingHorizontal: spacing[3], paddingVertical: spacing[1] },
  statutTxt: { ...typography.caption, fontWeight: '700' },
  hors: {
    marginTop: spacing[3],
    backgroundColor: colors.warning.bg,
    borderRadius: radius.md,
    padding: spacing[3],
  },
  horsTxt: { ...typography.caption, color: colors.warning.text },
  numero: { ...typography.bodyStrong, color: colors.ink[700], letterSpacing: 2, marginTop: spacing[3] },
  dates: { ...typography.caption, color: colors.ink[500], marginTop: spacing[2] },
  supprimer: { marginTop: spacing[3], alignSelf: 'flex-start' },
  supprimerTxt: { ...typography.caption, color: colors.danger.text, fontWeight: '700' },

  form: { marginBottom: spacing[5] },
  formTitre: { ...typography.h2, color: colors.blue[900], marginBottom: spacing[4] },
  aide: { ...typography.caption, color: colors.ink[500], marginTop: -spacing[2], marginBottom: spacing[4] },
  lien: { ...typography.caption, color: colors.blue[600], fontWeight: '700', marginBottom: spacing[4] },
  espaceHaut: { marginTop: spacing[4] },
  avertissement: { ...typography.body, color: colors.warning.text, marginBottom: spacing[3] },

  suggestions: {
    borderWidth: 1,
    borderColor: colors.line,
    borderRadius: radius.md,
    marginBottom: spacing[3],
    overflow: 'hidden',
  },
  suggestion: { padding: spacing[3], borderBottomWidth: 1, borderBottomColor: colors.line },
  suggestionNom: { ...typography.bodyStrong, color: colors.ink[900] },
  suggestionMeta: { ...typography.caption, color: colors.ink[500] },

  choisi: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[2],
    backgroundColor: colors.success.bg,
    borderRadius: radius.md,
    padding: spacing[3],
    marginBottom: spacing[4],
  },
  choisiCorps: { flex: 1 },
  choisiNom: { ...typography.bodyStrong, color: colors.ink[900] },
  choisiMeta: { ...typography.caption, color: colors.ink[700] },
  changer: { ...typography.caption, color: colors.blue[700], fontWeight: '700' },
});
