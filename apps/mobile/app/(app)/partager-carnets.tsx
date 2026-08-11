import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../src/components/Screen';
import { ScreenHeader } from '../../src/components/ScreenHeader';
import { Card } from '../../src/components/Card';
import { TextField } from '../../src/components/TextField';
import { PrimaryButton } from '../../src/components/PrimaryButton';
import { listerMembres } from '../../src/api/membres';
import { partagerEnMasse } from '../../src/api/delegations';
import { messageErreur } from '../../src/utils/erreurs';
import type { Membre } from '../../src/types/membre';
import { colors, radius, spacing, typography } from '../../src/theme/theme';

/** Format attendu par le backend (miroir de la règle `regex:/^\+225[0-9]{10}$/`). */
const FORMAT_TELEPHONE = /^\+225[0-9]{10}$/;

/**
 * Partage familial (incrément A) — confier ses carnets à un proche en une seule fois.
 *
 * POURQUOI CET ÉCRAN : le scénario validé au G1 est qu'un responsable de famille, qui a créé
 * tous les carnets, les partage aux autres membres à mesure qu'ils ouvrent leur compte. Le faire
 * carnet par carnet, c'est autant d'allers-retours réseau — sur une 3G ivoirienne, l'ergonomie
 * décide si la fonctionnalité est utilisée ou abandonnée.
 *
 * FRONTIÈRE : aucune règle ici. Le serveur décide qui peut déléguer, à qui, et ce que le droit
 * ouvre. L'écran collecte un numéro, une sélection, et affiche la réponse.
 */
export default function PartagerCarnetsScreen() {
  const [membres, setMembres] = useState<Membre[]>([]);
  const [selection, setSelection] = useState<number[]>([]);
  const [telephone, setTelephone] = useState('+225');
  // Incrément B — LEQUEL de ces carnets est celui de la personne invitée. C'est cette assertion,
  // et elle seule, qui lui permettra ensuite de le reconnaître comme sien. Au plus un : on ne
  // peut pas être deux personnes à la fois.
  const [sonCarnet, setSonCarnet] = useState<number | null>(null);

  const [chargement, setChargement] = useState(true);
  const [envoi, setEnvoi] = useState(false);
  const [erreurTel, setErreurTel] = useState<string | undefined>();
  const [erreur, setErreur] = useState<string | null>(null);
  const [resultat, setResultat] = useState<string | null>(null);

  useFocusEffect(
    useCallback(() => {
      let actif = true;
      (async () => {
        try {
          const liste = await listerMembres();
          if (actif) {
            setMembres(liste);
            setSelection(liste.map((m) => m.id)); // tout coché par défaut : c'est le cas courant.
          }
        } catch (e) {
          if (actif) setErreur(messageErreur(e));
        } finally {
          if (actif) setChargement(false);
        }
      })();
      return () => {
        actif = false;
      };
    }, []),
  );

  const basculer = (id: number) =>
    setSelection((actuelle) => {
      const retire = actuelle.includes(id);
      // Un carnet décoché ne peut plus être désigné comme celui de la personne invitée.
      if (retire && sonCarnet === id) setSonCarnet(null);
      return retire ? actuelle.filter((x) => x !== id) : [...actuelle, id];
    });

  const tousSelectionnes = membres.length > 0 && selection.length === membres.length;

  const partager = async () => {
    const numero = telephone.trim();

    if (!FORMAT_TELEPHONE.test(numero)) {
      setErreurTel('Format attendu : +225 suivi de 10 chiffres.');
      return;
    }
    setErreurTel(undefined);
    setErreur(null);
    setResultat(null);
    setEnvoi(true);

    try {
      const r = await partagerEnMasse(numero, selection, sonCarnet);
      setResultat(
        r.invitations_creees === 0
          ? 'Ces carnets étaient déjà partagés avec ce proche.'
          : `${r.invitations_creees} invitation(s) envoyée(s).` +
              (r.deja_partages > 0 ? ` ${r.deja_partages} déjà partagé(s).` : ''),
      );
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setEnvoi(false);
    }
  };

  return (
    <Screen>
      <ScreenHeader
        title="Partager mes carnets"
        subtitle="Confier l'accès à un proche"
        onBack={() => router.back()}
      />

      <Card style={styles.carte}>
        <Text style={styles.intro}>
          Le proche que vous invitez pourra <Text style={styles.gras}>consulter</Text> les carnets
          choisis, y compris ce qu&apos;un médecin y ajoute. Il ne pourra rien modifier.
        </Text>
        <Text style={styles.aide}>
          Il devra accepter l&apos;invitation depuis son application. Vous pouvez retirer
          l&apos;accès à tout moment, sans justification.
        </Text>

        <TextField
          label="Numéro du proche"
          value={telephone}
          onChangeText={setTelephone}
          placeholder="+225XXXXXXXXXX"
          keyboardType="phone-pad"
          maxLength={14}
          erreur={erreurTel}
        />
      </Card>

      <View style={styles.entete}>
        <Text style={styles.section}>Carnets à partager</Text>
        <Pressable
          onPress={() => setSelection(tousSelectionnes ? [] : membres.map((m) => m.id))}
          accessibilityRole="button"
        >
          <Text style={styles.toutCocher}>
            {tousSelectionnes ? 'Tout décocher' : 'Tout cocher'}
          </Text>
        </Pressable>
      </View>

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      ) : membres.length === 0 ? (
        <Card style={styles.vide}>
          <Ionicons name="people-outline" size={28} color={colors.blue[400]} />
          <Text style={styles.videTxt}>Aucun carnet à partager.</Text>
        </Card>
      ) : (
        <View style={styles.liste}>
          {membres.map((m) => {
            const coche = selection.includes(m.id);
            const estSonCarnet = sonCarnet === m.id;
            // Le dossier titulaire du compte est le SIEN : il ne peut pas être celui d'un autre.
            const designable = coche && !m.est_titulaire;

            return (
              <Card key={m.id} style={styles.bloc}>
                <Pressable
                  onPress={() => basculer(m.id)}
                  accessibilityRole="checkbox"
                  accessibilityState={{ checked: coche }}
                  accessibilityLabel={`${m.prenom} ${m.nom}`}
                  style={styles.item}
                >
                  <Ionicons
                    name={coche ? 'checkbox' : 'square-outline'}
                    size={22}
                    color={coche ? colors.blue[600] : colors.ink[500]}
                  />
                  <Text style={styles.itemNom}>
                    {m.prenom} {m.nom}
                    {m.est_titulaire ? ' (vous)' : ''}
                  </Text>
                </Pressable>

                {designable ? (
                  <Pressable
                    onPress={() => setSonCarnet(estSonCarnet ? null : m.id)}
                    accessibilityRole="radio"
                    accessibilityState={{ checked: estSonCarnet }}
                    accessibilityLabel={`Ce carnet est celui de ${m.prenom} ${m.nom}`}
                    style={styles.assertion}
                  >
                    <Ionicons
                      name={estSonCarnet ? 'radio-button-on' : 'radio-button-off'}
                      size={18}
                      color={estSonCarnet ? colors.blue[600] : colors.ink[500]}
                    />
                    <Text style={estSonCarnet ? styles.assertionActive : styles.assertionTxt}>
                      C&apos;est le carnet de la personne que j&apos;invite
                    </Text>
                  </Pressable>
                ) : null}
              </Card>
            );
          })}
        </View>
      )}

      {sonCarnet !== null ? (
        <Text style={styles.avertissement}>
          Cette personne pourra reconnaître ce carnet comme le sien et{' '}
          <Text style={styles.gras}>en devenir propriétaire</Text>. Vous garderez l&apos;accès en
          lecture, qu&apos;elle pourra retirer.
        </Text>
      ) : null}

      {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}
      {resultat ? <Text style={styles.succes}>{resultat}</Text> : null}

      <PrimaryButton
        label="Envoyer l'invitation"
        onPress={partager}
        loading={envoi}
        disabled={envoi || selection.length === 0}
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  carte: { gap: spacing[3], marginBottom: spacing[5] },
  intro: { ...typography.body, color: colors.ink[700] },
  gras: { fontWeight: '700' },
  aide: { ...typography.caption, color: colors.ink[500] },
  entete: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: spacing[3],
  },
  section: { ...typography.h2, color: colors.blue[900] },
  toutCocher: { ...typography.caption, color: colors.blue[700], fontWeight: '700' },
  loader: { marginVertical: spacing[5] },
  vide: { alignItems: 'center', marginBottom: spacing[5] },
  videTxt: { ...typography.bodyStrong, color: colors.ink[700], marginTop: spacing[2] },
  liste: { gap: spacing[2], marginBottom: spacing[4] },
  bloc: { borderRadius: radius.md, gap: spacing[2] },
  item: { flexDirection: 'row', alignItems: 'center', gap: spacing[3] },
  itemNom: { ...typography.bodyStrong, color: colors.blue[900], flex: 1 },
  assertion: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[2],
    paddingLeft: spacing[5],
  },
  assertionTxt: { ...typography.caption, color: colors.ink[500], flex: 1 },
  assertionActive: { ...typography.caption, color: colors.blue[700], fontWeight: '700', flex: 1 },
  avertissement: { ...typography.caption, color: colors.ink[700], marginBottom: spacing[4] },
  erreur: { ...typography.body, color: colors.danger.text, marginBottom: spacing[3] },
  succes: { ...typography.bodyStrong, color: colors.success.text, marginBottom: spacing[3] },
});
