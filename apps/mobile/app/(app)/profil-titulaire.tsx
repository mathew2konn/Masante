import React, { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Screen } from '../../src/components/Screen';
import { ScreenHeader } from '../../src/components/ScreenHeader';
import { Card } from '../../src/components/Card';
import { DateField } from '../../src/components/DateField';
import { Segmented } from '../../src/components/Segmented';
import { Chip } from '../../src/components/Chip';
import { PrimaryButton } from '../../src/components/PrimaryButton';
import { useSession } from '../../src/auth/SessionContext';
import { creerDossierTitulaire } from '../../src/api/titulaire';
import { messageErreur } from '../../src/utils/erreurs';
import { validerDateNaissance } from '../../src/utils/dates';
import { GROUPES_SANGUINS, type GroupeSanguin, type Sexe } from '../../src/types/membre';
import { colors, spacing, typography } from '../../src/theme/theme';

/** Bornes du sélecteur : pas de futur, plancher raisonnable (~120 ans) — miroir de MembreForm. */
const AUJOURDHUI = new Date();
const NAISSANCE_MIN = new Date(AUJOURDHUI.getFullYear() - 120, 0, 1);

/**
 * Complétion du profil santé du titulaire du compte (P6.1, ADR-021 §2.1, variante (c)).
 *
 * POURQUOI CET ÉCRAN EXISTE : `membres_famille` exige la date de naissance et le sexe, que
 * l'inscription ne collecte pas. Plutôt que d'alourdir le tunnel d'inscription (P1, validé G5,
 * et parcours critique en zone à faible connectivité), on complète ici, au premier accès au
 * Carnet. Le dossier créé reçoit alors son Identifiant National de Santé.
 *
 * FRONTIÈRE : aucune règle métier ici. Le NIS est généré, validé et attribué par le backend ;
 * l'écran ne fait que collecter deux champs et afficher ce que le serveur renvoie. Le nom et le
 * prénom ne sont même pas envoyés — le serveur les reprend du compte.
 */
export default function ProfilTitulaireScreen() {
  const { user } = useSession();

  const [dateNaissance, setDateNaissance] = useState('');
  const [sexe, setSexe] = useState<Sexe | null>(null);
  const [groupe, setGroupe] = useState<GroupeSanguin | null>(null);

  const [erreurs, setErreurs] = useState<{ date_naissance?: string; sexe?: string }>({});
  const [erreurServeur, setErreurServeur] = useState<string | null>(null);
  const [envoi, setEnvoi] = useState(false);

  const valider = () => {
    const e: typeof erreurs = {};
    const messageDate = validerDateNaissance(dateNaissance);
    if (messageDate) e.date_naissance = messageDate;
    if (!sexe) e.sexe = 'Sélectionnez le sexe.';
    setErreurs(e);
    return Object.keys(e).length === 0;
  };

  const enregistrer = async () => {
    if (!valider()) return;

    setErreurServeur(null);
    setEnvoi(true);
    try {
      await creerDossierTitulaire({
        date_naissance: dateNaissance.trim(),
        sexe: sexe as Sexe,
        groupe_sanguin: groupe,
      });
      // Retour au Carnet : il réinterroge le backend au focus et débloque la suite.
      router.back();
    } catch (err) {
      setErreurServeur(messageErreur(err));
    } finally {
      setEnvoi(false);
    }
  };

  return (
    <Screen>
      <ScreenHeader
        title="Votre profil santé"
        subtitle="Une dernière étape avant d'ouvrir votre carnet"
      />

      <Card style={styles.carte}>
        <Text style={styles.intro}>
          Ces informations créent <Text style={styles.gras}>votre</Text> dossier de santé et vous
          attribuent votre numéro national de santé. Elles sont indispensables aux soignants en cas
          d&apos;urgence.
        </Text>

        <View style={styles.identite}>
          <Text style={styles.identiteLabel}>Dossier au nom de</Text>
          <Text style={styles.identiteValeur}>
            {user?.prenom} {user?.nom}
          </Text>
          <Text style={styles.identiteAide}>
            Repris de votre compte. Pour le corriger, modifiez votre compte.
          </Text>
        </View>

        <DateField
          label="Date de naissance"
          value={dateNaissance || null}
          onChange={(v) => setDateNaissance(v ?? '')}
          placeholder="Sélectionner la date"
          min={NAISSANCE_MIN}
          max={AUJOURDHUI}
          obligatoire
          erreur={erreurs.date_naissance}
        />

        <Text style={styles.label}>Sexe</Text>
        <Segmented
          options={[
            { value: 'M', label: 'Masculin' },
            { value: 'F', label: 'Féminin' },
          ]}
          value={sexe}
          onChange={(v) => setSexe(v as Sexe)}
          accessibilityLabel="Votre sexe"
        />
        {erreurs.sexe ? <Text style={styles.erreurChamp}>{erreurs.sexe}</Text> : null}

        <Text style={styles.label}>Groupe sanguin (facultatif)</Text>
        <View style={styles.chips}>
          {GROUPES_SANGUINS.map((g) => (
            <Chip
              key={g}
              label={g}
              selected={groupe === g}
              onPress={() => setGroupe((actuel) => (actuel === g ? null : g))}
            />
          ))}
        </View>

        {erreurServeur ? <Text style={styles.erreurServeur}>{erreurServeur}</Text> : null}

        <PrimaryButton
          label="Créer mon dossier de santé"
          onPress={enregistrer}
          loading={envoi}
          disabled={envoi}
        />
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  carte: { gap: spacing[3] },
  intro: { ...typography.body, color: colors.ink[700] },
  gras: { fontWeight: '700' },
  identite: {
    backgroundColor: colors.blue[50],
    borderRadius: spacing[2],
    padding: spacing[3],
    gap: spacing[1],
  },
  identiteLabel: { ...typography.caption, color: colors.ink[500] },
  identiteValeur: { ...typography.bodyStrong, color: colors.ink[900] },
  identiteAide: { ...typography.caption, color: colors.ink[500] },
  label: { ...typography.bodyStrong, color: colors.ink[700], marginTop: spacing[2] },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing[2] },
  erreurChamp: { ...typography.caption, color: colors.danger.text, marginTop: spacing[1] },
  erreurServeur: { ...typography.body, color: colors.danger.text },
});
