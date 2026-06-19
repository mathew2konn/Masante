import React, { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Card } from '../components/Card';
import { TextField } from '../components/TextField';
import { Segmented } from '../components/Segmented';
import { Chip } from '../components/Chip';
import { PrimaryButton } from '../components/PrimaryButton';
import { colors, spacing, typography } from '../theme/theme';
import {
  GROUPES_SANGUINS,
  LIBELLE_CMU_STATUT,
  type CmuStatut,
  type GroupeSanguin,
  type Membre,
  type MembrePayload,
  type Sexe,
} from '../types/membre';
import { isoVersDateInput, validerDateFacultative, validerDateNaissance } from '../utils/dates';

/**
 * MembreForm — formulaire partagé création / édition d'un membre de la famille (F2.1).
 *
 * Aucune logique réseau ici : le parent (écran nouveau / modifier) fournit `onSubmit`
 * et gère l'appel API + la navigation. Couleurs/espacements via les tokens du DS.
 */
export function MembreForm({
  initial,
  submitLabel,
  submitting,
  erreurServeur,
  onSubmit,
}: {
  initial?: Membre;
  submitLabel: string;
  submitting: boolean;
  erreurServeur?: string | null;
  onSubmit: (payload: MembrePayload) => void;
}) {
  const [nom, setNom] = useState(initial?.nom ?? '');
  const [prenom, setPrenom] = useState(initial?.prenom ?? '');
  const [dateNaissance, setDateNaissance] = useState(isoVersDateInput(initial?.date_naissance));
  const [sexe, setSexe] = useState<Sexe | null>(initial?.sexe ?? null);
  const [groupe, setGroupe] = useState<GroupeSanguin | null>(initial?.groupe_sanguin ?? null);
  const [cmuNumero, setCmuNumero] = useState(initial?.cmu_numero ?? '');
  const [cmuStatut, setCmuStatut] = useState<CmuStatut | null>(initial?.cmu_statut ?? null);
  const [cmuValidite, setCmuValidite] = useState(isoVersDateInput(initial?.cmu_validite));

  const [erreurs, setErreurs] = useState<Record<string, string | null>>({});

  const valider = (): MembrePayload | null => {
    const e: Record<string, string | null> = {};
    if (!nom.trim()) e.nom = 'Le nom est obligatoire.';
    if (!prenom.trim()) e.prenom = 'Le prénom est obligatoire.';
    e.date_naissance = validerDateNaissance(dateNaissance);
    if (!sexe) e.sexe = 'Sélectionnez le sexe.';
    e.cmu_validite = validerDateFacultative(cmuValidite);

    setErreurs(e);
    if (Object.values(e).some((v) => v)) return null;

    return {
      nom: nom.trim(),
      prenom: prenom.trim(),
      date_naissance: dateNaissance.trim(),
      sexe: sexe as Sexe,
      groupe_sanguin: groupe,
      cmu_numero: cmuNumero.trim() || null,
      cmu_statut: cmuStatut,
      cmu_validite: cmuValidite.trim() || null,
    };
  };

  const soumettre = () => {
    const payload = valider();
    if (payload) onSubmit(payload);
  };

  return (
    <View>
      <Card style={styles.carte}>
        <Text style={styles.section}>Identité</Text>

        <TextField
          label="Nom"
          value={nom}
          onChangeText={setNom}
          placeholder="Nom de famille"
          autoCapitalize="words"
          maxLength={100}
          erreur={erreurs.nom}
        />
        <TextField
          label="Prénom"
          value={prenom}
          onChangeText={setPrenom}
          placeholder="Prénom"
          autoCapitalize="words"
          maxLength={100}
          erreur={erreurs.prenom}
        />
        <TextField
          label="Date de naissance"
          value={dateNaissance}
          onChangeText={setDateNaissance}
          placeholder="AAAA-MM-JJ"
          keyboardType="numbers-and-punctuation"
          maxLength={10}
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
          accessibilityLabel="Sexe du membre"
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
      </Card>

      <Card style={styles.carte}>
        <Text style={styles.section}>CMU (assurance santé)</Text>
        <Text style={styles.aide}>Couverture Maladie Universelle — facultatif.</Text>

        <TextField
          label="Numéro CMU"
          value={cmuNumero}
          onChangeText={setCmuNumero}
          placeholder="Numéro d'assuré"
          autoCapitalize="characters"
          maxLength={50}
        />

        <Text style={styles.label}>Statut</Text>
        <Segmented
          options={(Object.keys(LIBELLE_CMU_STATUT) as CmuStatut[]).map((s) => ({
            value: s,
            label: LIBELLE_CMU_STATUT[s],
          }))}
          value={cmuStatut}
          onChange={(v) => setCmuStatut(v as CmuStatut)}
          accessibilityLabel="Statut CMU"
        />

        <View style={styles.espaceHaut}>
          <TextField
            label="Validité (facultatif)"
            value={cmuValidite}
            onChangeText={setCmuValidite}
            placeholder="AAAA-MM-JJ"
            keyboardType="numbers-and-punctuation"
            maxLength={10}
            erreur={erreurs.cmu_validite}
          />
        </View>
      </Card>

      {erreurServeur ? <Text style={styles.erreur}>{erreurServeur}</Text> : null}

      <PrimaryButton label={submitLabel} onPress={soumettre} loading={submitting} />
    </View>
  );
}

const styles = StyleSheet.create({
  carte: { marginBottom: spacing[5] },
  section: { ...typography.h2, color: colors.blue[900], marginBottom: spacing[4] },
  aide: { ...typography.caption, color: colors.ink[500], marginTop: -spacing[2], marginBottom: spacing[4] },
  label: { ...typography.bodyStrong, color: colors.ink[700], marginBottom: spacing[2] },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing[2], marginTop: spacing[1] },
  espaceHaut: { marginTop: spacing[4] },
  erreurChamp: { ...typography.caption, color: colors.danger.text, marginTop: spacing[1] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text, marginBottom: spacing[3] },
});
