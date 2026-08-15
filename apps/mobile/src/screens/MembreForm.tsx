import React, { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Card } from '../components/Card';
import { TextField } from '../components/TextField';
import { DateField } from '../components/DateField';
import { Segmented } from '../components/Segmented';
import { Chip } from '../components/Chip';
import { PrimaryButton } from '../components/PrimaryButton';
import { colors, spacing, typography } from '../theme/theme';
import {
  GROUPES_SANGUINS,
  type GroupeSanguin,
  type Membre,
  type MembrePayload,
  type Sexe,
} from '../types/membre';
import { isoVersDateInput, validerDateNaissance } from '../utils/dates';

/** Bornes du sélecteur de date de naissance : pas de futur, plancher raisonnable (~120 ans). */
const AUJOURDHUI = new Date();
const NAISSANCE_MIN = new Date(AUJOURDHUI.getFullYear() - 120, 0, 1);

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
  const estEdition = Boolean(initial);

  const [erreurs, setErreurs] = useState<Record<string, string | null>>({});

  const valider = (): MembrePayload | null => {
    const e: Record<string, string | null> = {};
    if (!nom.trim()) e.nom = 'Le nom est obligatoire.';
    if (!prenom.trim()) e.prenom = 'Le prénom est obligatoire.';
    e.date_naissance = validerDateNaissance(dateNaissance);
    if (!sexe) e.sexe = 'Sélectionnez le sexe.';

    setErreurs(e);
    if (Object.values(e).some((v) => v)) return null;

    // P6.8d — plus aucun champ `cmu_*` : le serveur ne les accepte plus, et les envoyer donnerait
    // l'illusion d'enregistrer une couverture là où plus rien ne l'écrit.
    return {
      nom: nom.trim(),
      prenom: prenom.trim(),
      date_naissance: dateNaissance.trim(),
      sexe: sexe as Sexe,
      groupe_sanguin: groupe,
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

      {/*
        P6.8d — LE BLOC « CMU » A QUITTÉ CE FORMULAIRE.

        Il faisait déclarer un numéro, un STATUT (`actif` / `expiré` / `non inscrit`) et une date de
        validité comme s'il s'agissait d'attributs de la personne, au même titre que le groupe
        sanguin. Une couverture est un CONTRAT avec un organisme, et il peut y en avoir plusieurs
        (« CNAM, PUIS assurances privées » — CDC_06 §8). Elle se déclare désormais dans l'écran
        « Couvertures santé », où elle nomme son organisme au registre national.

        Le `Segmented` de statut disparaît par la même occasion : le statut est CALCULÉ à partir des
        dates du contrat, il ne se coche plus (même bascule que le statut vaccinal en P6.8b).
      */}
      {estEdition ? (
        <Card style={styles.carte}>
          <Text style={styles.section}>Couverture santé</Text>
          <Text style={styles.aide}>
            La CMU et les autres couvertures se gèrent maintenant depuis la fiche du membre, dans
            « Couvertures santé » — une ligne par organisme.
          </Text>
        </Card>
      ) : null}

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
