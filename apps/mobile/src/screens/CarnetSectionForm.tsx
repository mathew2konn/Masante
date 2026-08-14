import React, { useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../components/Screen';
import { ScreenHeader } from '../components/ScreenHeader';
import { Card } from '../components/Card';
import { TextField } from '../components/TextField';
import { DateField } from '../components/DateField';
import { Chip } from '../components/Chip';
import { Segmented } from '../components/Segmented';
import { PrimaryButton } from '../components/PrimaryButton';
import { SecondaryButton } from '../components/SecondaryButton';
import { sectionParSlug } from '../carnet/registre';
import { creerItem, modifierItem, obtenirItem } from '../api/carnet';
import { estCarnetPartage } from '../api/delegations';
import { deposerContribution } from '../api/contributions';
import { rechercherMedicaments } from '../api/medicaments';
import { obtenirReferences, rechercherAnalyses } from '../api/analyses';
import { obtenirMembre } from '../api/membres';
import { messageErreur } from '../utils/erreurs';
import { heureCourte, isoVersDateInput, validerDate, validerHeure } from '../utils/dates';
import type { Champ, Medicament, ParametreResultat, SectionDescriptor } from '../types/carnet';
import type { Medicament as MedicamentCatalogue } from '../types/medicament';
import type { AnalyseCatalogue, ReferencesAnalyse } from '../types/analyse';
import { colors, spacing, typography } from '../theme/theme';

/**
 * CarnetSectionForm — formulaire générique création / édition d'un élément de section,
 * piloté par le schéma du registre (src/carnet/registre.ts). Aucune logique spécifique à
 * une section ici : rendu, validation et payload sont dérivés des champs déclarés.
 */
export function CarnetSectionForm({
  membreId,
  slug,
  itemId,
  nomMembre,
}: {
  membreId: number;
  slug: string;
  itemId?: number;
  nomMembre?: string;
}) {
  const section = sectionParSlug(slug);
  const edition = itemId !== undefined;

  const [valeurs, setValeurs] = useState<Record<string, unknown>>({});
  const [erreurs, setErreurs] = useState<Record<string, string | null>>({});
  const [chargement, setChargement] = useState(edition);
  const [chargementErreur, setChargementErreur] = useState<string | null>(null);
  const [envoi, setEnvoi] = useState(false);
  const [erreurServeur, setErreurServeur] = useState<string | null>(null);
  // Carnet familial partagé (C) — sur un carnet qu'on m'a confié, je PROPOSE, je n'écris pas.
  // `null` = pas encore su ; le bouton attend la réponse plutôt que de deviner.
  const [carnetPartage, setCarnetPartage] = useState<boolean | null>(null);
  // P6.7b — l'age et le sexe servent a RESOUDRE la strate de reference applicable. Ils ne servent
  // a rien d'autre : la plateforme montre la plage, elle ne compare pas le resultat.
  const [patient, setPatient] = useState<{ ageJours?: number; sexe?: 'M' | 'F' }>({});

  useEffect(() => {
    let actif = true;
    obtenirMembre(membreId)
      .then((m) => {
        if (!actif) return;
        const naissance = m.date_naissance ? new Date(m.date_naissance) : null;
        const ageJours = naissance && !Number.isNaN(naissance.getTime())
          ? Math.floor((Date.now() - naissance.getTime()) / 86400000)
          : undefined;
        setPatient({ ageJours, sexe: m.sexe === 'M' || m.sexe === 'F' ? m.sexe : undefined });
      })
      // Silence volontaire : sans ces informations, l'API renvoie les strates communes et DIT
      // ce qui manque. Une erreur a l'ecran ferait croire que le formulaire est casse.
      .catch(() => undefined);
    return () => {
      actif = false;
    };
  }, [membreId]);

  useEffect(() => {
    let actif = true;
    estCarnetPartage(membreId)
      .then((p) => actif && setCarnetPartage(p))
      // Si l'on ne sait pas, on suppose le cas le PLUS restrictif : proposer plutôt qu'écrire.
      // Le serveur tranchera de toute façon — mais l'écran ne doit pas promettre l'inverse.
      .catch(() => actif && setCarnetPartage(true));
    return () => {
      actif = false;
    };
  }, [membreId]);

  // Valeurs initiales (création) ou préchargement (édition).
  useEffect(() => {
    if (!section) return;
    let actif = true;
    if (!edition) {
      setValeurs(initiales(section, null));
      return;
    }
    (async () => {
      try {
        const item = await obtenirItem(membreId, section.chemin, itemId!);
        if (actif) setValeurs(initiales(section, item));
      } catch (e) {
        if (actif) setChargementErreur(messageErreur(e));
      } finally {
        if (actif) setChargement(false);
      }
    })();
    return () => {
      actif = false;
    };
  }, [section, edition, membreId, itemId]);

  const setVal = (cle: string, v: unknown) => setValeurs((prev) => ({ ...prev, [cle]: v }));

  if (!section) {
    return (
      <Screen>
        <ScreenHeader title="Carnet" onBack={() => router.back()} />
        <Text style={styles.erreur}>Section inconnue.</Text>
      </Screen>
    );
  }

  const soumettre = async () => {
    const errs = validerTout(section, valeurs);
    setErreurs(errs);
    if (Object.values(errs).some((v) => v)) return;

    const payload = construirePayload(section, valeurs);
    setErreurServeur(null);
    setEnvoi(true);
    try {
      if (edition) {
        await modifierItem(membreId, section.chemin, itemId!, payload);
      } else if (carnetPartage) {
        // Carnet confié par un proche : on dépose une proposition, un responsable la validera.
        await deposerContribution(membreId, section.chemin, payload);
      } else {
        await creerItem(membreId, section.chemin, payload);
      }
      router.back();
    } catch (e) {
      setErreurServeur(messageErreur(e));
    } finally {
      setEnvoi(false);
    }
  };

  return (
    <Screen>
      <ScreenHeader
        title={edition ? 'Modifier' : 'Ajouter'}
        subtitle={[section.titre, nomMembre].filter(Boolean).join(' · ')}
        onBack={() => router.back()}
      />

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      ) : chargementErreur ? (
        <Text style={styles.erreur}>{chargementErreur}</Text>
      ) : (
        <>
          <Card style={styles.carte}>
            {section.champs.map((champ) => (
              <ChampVue
                key={champ.cle}
                champ={champ}
                valeur={valeurs[champ.cle]}
                erreur={erreurs[champ.cle]}
                onChange={(v) => setVal(champ.cle, v)}
                patient={patient}
              />
            ))}
          </Card>

          {/* Carnet familial partagé (C) — on annonce la portée AVANT que la personne écrive,
              pas après coup : ce qu'elle note n'entrera au carnet qu'après validation. */}
          {!edition && carnetPartage ? (
            <Text style={styles.portee}>
              Ce carnet vous a été confié. Votre ajout sera soumis à un responsable de la famille
              avant d&apos;entrer au dossier.
            </Text>
          ) : null}

          {erreurServeur ? <Text style={styles.erreurServeur}>{erreurServeur}</Text> : null}

          <PrimaryButton
            label={edition ? 'Enregistrer' : carnetPartage ? 'Proposer cet ajout' : 'Ajouter'}
            onPress={soumettre}
            loading={envoi || carnetPartage === null}
            disabled={carnetPartage === null}
          />
        </>
      )}
    </Screen>
  );
}

/** Rendu d'un champ selon son kind. */
function ChampVue({
  champ,
  valeur,
  erreur,
  onChange,
  patient,
}: {
  champ: Champ;
  valeur: unknown;
  erreur?: string | null;
  onChange: (v: unknown) => void;
  patient: { ageJours?: number; sexe?: 'M' | 'F' };
}) {
  switch (champ.kind) {
    case 'texte':
      return (
        <TextField
          label={libelle(champ)}
          value={(valeur as string) ?? ''}
          onChangeText={onChange}
          multiline={champ.multiligne}
          maxLength={champ.max}
          placeholder={champ.format === 'telephone' ? '+225XXXXXXXXXX' : undefined}
          keyboardType={
            champ.format === 'telephone' ? 'phone-pad' : champ.format === 'email' ? 'email-address' : undefined
          }
          autoCapitalize={champ.format ? 'none' : champ.autoCap ?? 'sentences'}
          erreur={erreur}
        />
      );
    case 'date':
      return (
        <DateField
          label={libelle(champ)}
          value={((valeur as string) ?? '') || null}
          onChange={(v) => onChange(v ?? '')}
          obligatoire={champ.obligatoire}
          // Bornage doux : pas de futur si demandé. La contrainte apresChamp (date_fin ≥ date_debut)
          // reste vérifiée à la soumission (validerDate), le picker ne l'impose pas.
          max={champ.futurInterdit ? new Date() : undefined}
          erreur={erreur}
        />
      );
    case 'heure':
      return (
        <TextField
          label={libelle(champ)}
          value={(valeur as string) ?? ''}
          onChangeText={onChange}
          placeholder="HH:MM"
          keyboardType="numbers-and-punctuation"
          maxLength={5}
          erreur={erreur}
        />
      );
    case 'select':
      return (
        <View style={styles.bloc}>
          <Text style={styles.label}>{libelle(champ)}</Text>
          <View style={styles.chips}>
            {champ.options.map((o) => (
              <Chip
                key={o.value}
                label={o.label}
                selected={valeur === o.value}
                onPress={() => onChange(valeur === o.value && !champ.obligatoire ? '' : o.value)}
              />
            ))}
          </View>
          {erreur ? <Text style={styles.champErreur}>{erreur}</Text> : null}
        </View>
      );
    case 'booleen':
      return (
        <View style={styles.bloc}>
          <Text style={styles.label}>{champ.label}</Text>
          <Segmented
            options={[
              { value: true, label: 'Oui' },
              { value: false, label: 'Non' },
            ]}
            value={valeur === true}
            onChange={onChange}
            accessibilityLabel={champ.label}
          />
        </View>
      );
    case 'medicaments':
      return (
        <RepeaterMedicaments
          label={libelle(champ)}
          rows={(valeur as Medicament[]) ?? []}
          onChange={onChange}
          erreur={erreur}
        />
      );
    case 'resultats':
      return (
        <RepeaterResultats
          label={libelle(champ)}
          rows={(valeur as ParametreResultat[]) ?? []}
          onChange={onChange}
          patient={patient}
        />
      );
  }
}

/** Répéteur de médicaments (nom obligatoire + posologie). */
function RepeaterMedicaments({
  label,
  rows,
  onChange,
  erreur,
}: {
  label: string;
  rows: Medicament[];
  onChange: (v: Medicament[]) => void;
  erreur?: string | null;
}) {
  const maj = (idx: number, champ: 'nom' | 'posologie', val: string) =>
    onChange(rows.map((r, i) => (i === idx ? { ...r, [champ]: val } : r)));
  const ajouter = () => onChange([...rows, { nom: '', posologie: '' }]);
  const retirer = (idx: number) => onChange(rows.filter((_, i) => i !== idx));

  /**
   * Rattache la ligne à un produit du référentiel national (P6.6b).
   *
   * On ne pose QUE `medicament_id` : le code national et la DCI viennent du serveur, qui les relit
   * au référentiel et les fige. Les écrire ici donnerait l'illusion qu'ils font autorité alors
   * qu'ils n'auraient été vérifiés par personne.
   */
  const rattacher = (idx: number, produit: MedicamentCatalogue) =>
    onChange(rows.map((r, i) => (i === idx ? { ...r, nom: produit.libelle, medicament_id: produit.id } : r)));

  const detacher = (idx: number) =>
    onChange(
      rows.map((r, i) =>
        i === idx ? { nom: r.nom, posologie: r.posologie, medicament_id: undefined } : r,
      ),
    );

  return (
    <View style={styles.bloc}>
      <Text style={styles.label}>{label}</Text>
      {rows.map((r, idx) => (
        <View key={idx} style={styles.repeaterRow}>
          <TextField label={`Médicament ${idx + 1}`} value={r.nom} onChangeText={(t) => maj(idx, 'nom', t)} autoCapitalize="sentences" placeholder="Nom" />

          <ChercheurReferentiel
            terme={r.nom}
            rattache={r.medicament_id !== undefined}
            codeNational={r.code_national}
            onChoisir={(produit) => rattacher(idx, produit)}
            onDetacher={() => detacher(idx)}
          />

          <TextField label="Posologie" value={r.posologie ?? ''} onChangeText={(t) => maj(idx, 'posologie', t)} placeholder="ex. 1 cp matin et soir" autoCapitalize="sentences" />
          {rows.length > 1 ? (
            <Pressable onPress={() => retirer(idx)} accessibilityRole="button" accessibilityLabel={`Retirer le médicament ${idx + 1}`} style={styles.retirer}>
              <Ionicons name="trash-outline" size={18} color={colors.danger.text} />
              <Text style={styles.retirerTxt}>Retirer</Text>
            </Pressable>
          ) : null}
        </View>
      ))}
      {erreur ? <Text style={styles.champErreur}>{erreur}</Text> : null}
      <View style={styles.ajouterLigne}>
        <SecondaryButton label="Ajouter un médicament" onPress={ajouter} />
      </View>
    </View>
  );
}

/**
 * Rattachement d'une ligne au référentiel national des médicaments (CDC_09 §6.1).
 *
 * FACULTATIF, ET IL DOIT LE RESTER. Un patient qui recopie une ordonnance papier ne trouvera pas
 * toujours le produit — le référentiel est incomplet, et l'y contraindre ferait de ses lacunes un
 * blocage. Le champ libre au-dessus continue donc de suffire : ce composant PROPOSE, il n'exige pas.
 *
 * HORS LIGNE, IL SE TAIT. Une recherche impossible n'est pas une panne : la saisie libre reste
 * entière, et afficher une erreur ferait croire que le formulaire est cassé.
 */
function ChercheurReferentiel({
  terme,
  rattache,
  codeNational,
  onChoisir,
  onDetacher,
}: {
  terme: string;
  rattache: boolean;
  codeNational?: string;
  onChoisir: (produit: MedicamentCatalogue) => void;
  onDetacher: () => void;
}) {
  const [suggestions, setSuggestions] = useState<MedicamentCatalogue[]>([]);
  const [ouvert, setOuvert] = useState(false);

  useEffect(() => {
    const q = terme.trim();

    if (rattache || q.length < 3) {
      setSuggestions([]);
      return;
    }

    let vivant = true;
    const minuteur = setTimeout(() => {
      rechercherMedicaments(q)
        .then((liste) => {
          if (vivant) setSuggestions(liste.slice(0, 5));
        })
        // Silence volontaire : sans réseau, la saisie libre suffit (voir l'en-tête).
        .catch(() => {
          if (vivant) setSuggestions([]);
        });
    }, 350);

    return () => {
      vivant = false;
      clearTimeout(minuteur);
    };
  }, [terme, rattache]);

  if (rattache) {
    return (
      <View style={styles.lienReferentiel}>
        <Ionicons name="shield-checkmark-outline" size={16} color={colors.success.text} />
        <Text style={styles.lienTexte}>
          Référentiel national{codeNational ? ` · ${codeNational}` : ''}
        </Text>
        <Pressable onPress={onDetacher} accessibilityRole="button" accessibilityLabel="Détacher du référentiel">
          <Text style={styles.lienDetacher}>Détacher</Text>
        </Pressable>
      </View>
    );
  }

  if (suggestions.length === 0) {
    return null;
  }

  return (
    <View style={styles.suggestions}>
      {!ouvert ? (
        <Pressable onPress={() => setOuvert(true)} accessibilityRole="button">
          <Text style={styles.suggestionsInvite}>
            {suggestions.length} produit(s) au référentiel national — appuyez pour les voir
          </Text>
        </Pressable>
      ) : (
        suggestions.map((s) => (
          <Pressable
            key={s.id}
            onPress={() => {
              onChoisir(s);
              setOuvert(false);
            }}
            accessibilityRole="button"
            accessibilityLabel={`Rattacher à ${s.libelle}`}
            style={styles.suggestion}
          >
            <Text style={styles.suggestionNom}>{s.libelle}</Text>
            {s.code ? <Text style={styles.suggestionCode}>{s.code}</Text> : null}
          </Pressable>
        ))
      )}
    </View>
  );
}

/** Répéteur paramètre/valeur (résultats d'analyse, facultatif). */
function RepeaterResultats({
  label,
  rows,
  onChange,
  patient,
}: {
  label: string;
  rows: ParametreResultat[];
  onChange: (v: ParametreResultat[]) => void;
  patient: { ageJours?: number; sexe?: 'M' | 'F' };
}) {
  const maj = (idx: number, champ: 'parametre' | 'valeur' | 'unite', val: string) =>
    onChange(rows.map((r, i) => (i === idx ? { ...r, [champ]: val } : r)));
  const ajouter = () => onChange([...rows, { parametre: '', valeur: '' }]);
  const retirer = (idx: number) => onChange(rows.filter((_, i) => i !== idx));

  /** Rattache la ligne au catalogue. On ne pose QUE `analyse_id` : le serveur relit et fige. */
  const rattacher = (idx: number, a: AnalyseCatalogue) =>
    onChange(rows.map((r, i) => (i === idx ? { ...r, parametre: a.libelle, analyse_id: a.id } : r)));

  const detacher = (idx: number) =>
    onChange(
      rows.map((r, i) =>
        i === idx ? { parametre: r.parametre, valeur: r.valeur, unite: r.unite, analyse_id: undefined } : r,
      ),
    );

  return (
    <View style={styles.bloc}>
      <Text style={styles.label}>{label}</Text>
      {rows.length === 0 ? <Text style={styles.aide}>Aucune ligne. Ajoutez vos résultats si besoin.</Text> : null}
      {rows.map((r, idx) => (
        <View key={idx} style={styles.repeaterRow}>
          <TextField label="Paramètre" value={r.parametre} onChangeText={(t) => maj(idx, 'parametre', t)} placeholder="ex. Glycémie" autoCapitalize="sentences" />

          <ChercheurAnalyse
            terme={r.parametre}
            rattache={r.analyse_id !== undefined}
            codeNational={r.code_national}
            onChoisir={(a) => rattacher(idx, a)}
            onDetacher={() => detacher(idx)}
          />

          <TextField label="Valeur" value={r.valeur} onChangeText={(t) => maj(idx, 'valeur', t)} placeholder="ex. 0.95" autoCapitalize="none" />
          <TextField label="Unité" value={r.unite ?? ''} onChangeText={(t) => maj(idx, 'unite', t)} placeholder="ex. g/L" autoCapitalize="none" />

          {r.analyse_id !== undefined ? <ValeursDeReference analyseId={r.analyse_id} patient={patient} /> : null}

          <Pressable onPress={() => retirer(idx)} accessibilityRole="button" accessibilityLabel={`Retirer la ligne ${idx + 1}`} style={styles.retirer}>
            <Ionicons name="trash-outline" size={18} color={colors.danger.text} />
            <Text style={styles.retirerTxt}>Retirer</Text>
          </Pressable>
        </View>
      ))}
      <View style={styles.ajouterLigne}>
        <SecondaryButton label="Ajouter une ligne" onPress={ajouter} />
      </View>
    </View>
  );
}

/** Rattachement d'une ligne au catalogue national — même forme que pour un médicament (P6.6b). */
function ChercheurAnalyse({
  terme,
  rattache,
  codeNational,
  onChoisir,
  onDetacher,
}: {
  terme: string;
  rattache: boolean;
  codeNational?: string;
  onChoisir: (a: AnalyseCatalogue) => void;
  onDetacher: () => void;
}) {
  const [suggestions, setSuggestions] = useState<AnalyseCatalogue[]>([]);
  const [ouvert, setOuvert] = useState(false);

  useEffect(() => {
    const q = terme.trim();

    if (rattache || q.length < 3) {
      setSuggestions([]);
      return;
    }

    let vivant = true;
    const minuteur = setTimeout(() => {
      rechercherAnalyses(q)
        .then((liste) => {
          if (vivant) setSuggestions(liste.slice(0, 5));
        })
        .catch(() => {
          if (vivant) setSuggestions([]);
        });
    }, 350);

    return () => {
      vivant = false;
      clearTimeout(minuteur);
    };
  }, [terme, rattache]);

  if (rattache) {
    return (
      <View style={styles.lienReferentiel}>
        <Ionicons name="shield-checkmark-outline" size={16} color={colors.success.text} />
        <Text style={styles.lienTexte}>Catalogue national{codeNational ? ` · ${codeNational}` : ''}</Text>
        <Pressable onPress={onDetacher} accessibilityRole="button" accessibilityLabel="Détacher du catalogue">
          <Text style={styles.lienDetacher}>Détacher</Text>
        </Pressable>
      </View>
    );
  }

  if (suggestions.length === 0) {
    return null;
  }

  return (
    <View style={styles.suggestions}>
      {!ouvert ? (
        <Pressable onPress={() => setOuvert(true)} accessibilityRole="button">
          <Text style={styles.suggestionsInvite}>
            {suggestions.length} analyse(s) au catalogue national — appuyez pour les voir
          </Text>
        </Pressable>
      ) : (
        suggestions.map((a) => (
          <Pressable
            key={a.id}
            onPress={() => {
              onChoisir(a);
              setOuvert(false);
            }}
            accessibilityRole="button"
            accessibilityLabel={`Rattacher à ${a.designation}`}
            style={styles.suggestion}
          >
            <Text style={styles.suggestionNom}>{a.designation}</Text>
            <Text style={styles.suggestionCode}>{[a.code, a.unite].filter(Boolean).join(' · ')}</Text>
          </Pressable>
        ))
      )}
    </View>
  );
}

/**
 * Les valeurs de référence applicables — AFFICHÉES, JAMAIS COMPARÉES.
 *
 * Ce composant ne regarde pas la valeur saisie et ne dit jamais si elle est normale. Une plage
 * biologique dépend du sexe, de l'âge et parfois de l'état physiologique : conclure sur une seule
 * d'entre elles dirait à une femme enceinte que son hémoglobine est basse alors qu'elle est normale
 * pour elle. On montre, le lecteur juge.
 *
 * Les strates conditionnelles (grossesse) sont affichées EN PLUS et marquées comme telles : la
 * plateforme ne décide pas qu'une patiente est enceinte.
 */
function ValeursDeReference({
  analyseId,
  patient,
}: {
  analyseId: number;
  patient: { ageJours?: number; sexe?: 'M' | 'F' };
}) {
  const [donnees, setDonnees] = useState<ReferencesAnalyse | null>(null);

  useEffect(() => {
    let vivant = true;
    obtenirReferences(analyseId, patient.ageJours, patient.sexe)
      .then((d) => {
        if (vivant) setDonnees(d);
      })
      // Hors ligne : aucune référence affichée, et aucune erreur — le résultat reste lisible.
      .catch(() => {
        if (vivant) setDonnees(null);
      });
    return () => {
      vivant = false;
    };
  }, [analyseId, patient.ageJours, patient.sexe]);

  if (!donnees || (donnees.references.length === 0 && donnees.incertitude.length === 0)) {
    return null;
  }

  const demonstration = donnees.references.some((r) => r.source === 'demonstration');

  return (
    <View style={styles.references}>
      <Text style={styles.referencesTitre}>Valeurs habituellement observées</Text>

      {donnees.references.map((r, i) => (
        <View key={i} style={styles.referenceLigne}>
          <Text style={styles.referenceStrate}>
            {r.libelle_strate}
            {r.conditionnelle ? ' (selon votre situation)' : ''}
          </Text>
          <Text style={styles.referencePlage}>
            {r.plage} {donnees.analyse.unite}
          </Text>
        </View>
      ))}

      {donnees.incertitude.map((i, k) => (
        <Text key={k} style={styles.referenceIncertitude}>
          {i}
        </Text>
      ))}

      {demonstration ? (
        <Text style={styles.referenceDemonstration}>Valeurs de démonstration, non validées cliniquement.</Text>
      ) : null}

      <Text style={styles.referenceAvertissement}>{donnees.avertissement}</Text>
    </View>
  );
}

// --- Helpers (hors composant) ---

const libelle = (c: Champ) => (c.obligatoire ? `${c.label} *` : c.label);

/** Valide un champ texte formaté (miroir des règles backend). Renvoie un message ou null. */
function validerFormat(format: 'telephone' | 'email', valeur: string): string | null {
  if (format === 'telephone') {
    return /^\+225[0-9]{10}$/.test(valeur) ? null : 'Numéro invalide (format +225 puis 10 chiffres).';
  }
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(valeur) ? null : 'Adresse e-mail invalide.';
}

/** Construit l'état initial du formulaire à partir d'un item (édition) ou des défauts. */
function initiales(section: SectionDescriptor, item: Record<string, unknown> | null): Record<string, unknown> {
  const v: Record<string, unknown> = {};
  for (const c of section.champs) {
    const brut = item?.[c.cle];
    switch (c.kind) {
      case 'texte':
        v[c.cle] = typeof brut === 'string' ? brut : item ? '' : c.defaut ?? '';
        break;
      case 'select':
        v[c.cle] = typeof brut === 'string' ? brut : '';
        break;
      case 'date':
        v[c.cle] = isoVersDateInput(typeof brut === 'string' ? brut : '');
        break;
      case 'heure':
        v[c.cle] = heureCourte(typeof brut === 'string' ? brut : '');
        break;
      case 'booleen':
        v[c.cle] = item ? brut === true : c.defaut ?? false;
        break;
      case 'medicaments': {
        const arr = Array.isArray(brut) ? (brut as Medicament[]) : [];
        v[c.cle] = arr.length
          ? arr.map((m) => ({
              nom: m.nom ?? '',
              posologie: m.posologie ?? '',
              // Le lien et ses valeurs figées sont RELUS tels quels : les perdre à l'édition
              // délierait silencieusement une ligne que le prescripteur avait rattachée.
              medicament_id: m.medicament_id,
              code_national: m.code_national,
              dci: m.dci,
              dosage_referentiel: m.dosage_referentiel,
            }))
          : [{ nom: '', posologie: '' }];
        break;
      }
      case 'resultats': {
        const arr = Array.isArray(brut) ? (brut as ParametreResultat[]) : [];
        v[c.cle] = arr.map((r) => ({
          parametre: r.parametre ?? '',
          valeur: r.valeur ?? '',
          unite: r.unite ?? r.unite_catalogue ?? '',
          // Le lien et ses valeurs figées sont RELUS tels quels : les perdre à l'édition délierait
          // silencieusement une ligne qui avait été rattachée au catalogue.
          analyse_id: r.analyse_id,
          code_national: r.code_national,
          libelle_catalogue: r.libelle_catalogue,
          unite_catalogue: r.unite_catalogue,
        }));
        break;
      }
    }
  }
  return v;
}

/** Valide tous les champs ; renvoie une map cle -> message (ou null). */
function validerTout(section: SectionDescriptor, valeurs: Record<string, unknown>): Record<string, string | null> {
  const errs: Record<string, string | null> = {};
  for (const c of section.champs) {
    const val = valeurs[c.cle];
    switch (c.kind) {
      case 'texte': {
        const t = String(val ?? '').trim();
        if (c.obligatoire && !t) errs[c.cle] = 'Ce champ est obligatoire.';
        else if (t && c.format) errs[c.cle] = validerFormat(c.format, t);
        else errs[c.cle] = null;
        break;
      }
      case 'select':
        errs[c.cle] = c.obligatoire && !String(val ?? '').trim() ? 'Ce champ est obligatoire.' : null;
        break;
      case 'date': {
        let e = validerDate(String(val ?? ''), { obligatoire: c.obligatoire, futurInterdit: c.futurInterdit });
        if (!e && c.apresChamp && String(val ?? '').trim()) {
          const ref = String(valeurs[c.apresChamp] ?? '').trim();
          if (ref && String(val).trim() < ref) e = 'La date de fin doit suivre la date de début.';
        }
        errs[c.cle] = e;
        break;
      }
      case 'heure':
        errs[c.cle] = validerHeure(String(val ?? ''), c.obligatoire);
        break;
      case 'medicaments': {
        const arr = (val as Medicament[]) ?? [];
        errs[c.cle] = c.obligatoire && !arr.some((m) => m.nom.trim()) ? 'Ajoutez au moins un médicament.' : null;
        break;
      }
      default:
        errs[c.cle] = null;
    }
  }
  return errs;
}

/** Construit le payload API à partir des valeurs validées. */
function construirePayload(section: SectionDescriptor, valeurs: Record<string, unknown>): Record<string, unknown> {
  const p: Record<string, unknown> = {};
  for (const c of section.champs) {
    const val = valeurs[c.cle];
    switch (c.kind) {
      case 'texte': {
        const t = String(val ?? '').trim();
        p[c.cle] = c.obligatoire ? t : t || null;
        break;
      }
      case 'select':
        p[c.cle] = String(val ?? '') || null;
        break;
      case 'date':
      case 'heure':
        p[c.cle] = String(val ?? '').trim() || null;
        break;
      case 'booleen':
        p[c.cle] = val === true;
        break;
      case 'medicaments': {
        const arr = (val as Medicament[]) ?? [];
        p[c.cle] = arr
          .filter((m) => m.nom.trim())
          .map((m) => {
            // On n'envoie JAMAIS `code_national`, `dci` ni `dosage_referentiel` : le serveur les
            // relit au référentiel et les fige. Les transmettre laisserait croire qu'ils viennent
            // du client, alors qu'ils n'auraient été vérifiés par personne.
            const ligne: Record<string, unknown> = { nom: m.nom.trim() };
            if (m.posologie?.trim()) ligne.posologie = m.posologie.trim();
            if (m.medicament_id !== undefined) ligne.medicament_id = m.medicament_id;
            return ligne;
          });
        break;
      }
      case 'resultats': {
        const arr = (val as ParametreResultat[]) ?? [];
        const nettoye = arr.filter((r) => r.parametre.trim()).map((r) => {
          // On n'envoie JAMAIS `code_national`, `libelle_catalogue` ni `unite_catalogue` : le
          // serveur les relit au catalogue et les fige.
          const ligne: Record<string, unknown> = { parametre: r.parametre.trim(), valeur: r.valeur.trim() };
          if (r.unite?.trim()) ligne.unite = r.unite.trim();
          if (r.analyse_id !== undefined) ligne.analyse_id = r.analyse_id;
          return ligne;
        });
        p[c.cle] = nettoye.length ? nettoye : null;
        break;
      }
    }
  }
  if (section.ajoutParPatient) p.added_by = 'patient';
  return p;
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },
  erreurServeur: { ...typography.bodyStrong, color: colors.danger.text, marginBottom: spacing[3] },
  // Carnet familial partagé (C) — portée annoncée avant la saisie.
  portee: { ...typography.caption, color: colors.ink[500], marginBottom: spacing[3] },
  carte: { marginBottom: spacing[5] },
  bloc: { marginBottom: spacing[4] },
  label: { ...typography.bodyStrong, color: colors.ink[700], marginBottom: spacing[2] },
  aide: { ...typography.caption, color: colors.ink[500], marginBottom: spacing[2] },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing[2] },
  champErreur: { ...typography.caption, color: colors.danger.text, marginTop: spacing[1] },
  repeaterRow: { borderTopWidth: 1, borderTopColor: colors.line, paddingTop: spacing[3], marginTop: spacing[2] },
  retirer: { flexDirection: 'row', alignItems: 'center', alignSelf: 'flex-start', paddingVertical: spacing[1] },
  retirerTxt: { ...typography.caption, color: colors.danger.text, marginLeft: spacing[1], fontWeight: '700' },
  ajouterLigne: { marginTop: spacing[3] },
  // P6.6b — rattachement au référentiel national des médicaments.
  lienReferentiel: { flexDirection: 'row', alignItems: 'center', gap: spacing[2], marginBottom: spacing[2] },
  lienTexte: { ...typography.caption, color: colors.success.text, flex: 1 },
  lienDetacher: { ...typography.caption, color: colors.ink[500], fontWeight: '700' },
  suggestions: { marginBottom: spacing[2] },
  suggestionsInvite: { ...typography.caption, color: colors.blue[600], fontWeight: '700' },
  suggestion: { paddingVertical: spacing[2], borderBottomWidth: 1, borderBottomColor: colors.line },
  suggestionNom: { ...typography.body, color: colors.ink[700] },
  suggestionCode: { ...typography.caption, color: colors.ink[500] },
  // P6.7b — valeurs de reference : montrees, jamais comparees.
  references: { marginTop: spacing[2], marginBottom: spacing[2], padding: spacing[3], borderRadius: 8, borderWidth: 1, borderColor: colors.line },
  referencesTitre: { ...typography.caption, color: colors.ink[700], fontWeight: '700', marginBottom: spacing[1] },
  referenceLigne: { flexDirection: 'row', justifyContent: 'space-between', gap: spacing[2], paddingVertical: spacing[1] },
  referenceStrate: { ...typography.caption, color: colors.ink[500], flex: 1 },
  referencePlage: { ...typography.caption, color: colors.ink[700], fontWeight: '700' },
  referenceIncertitude: { ...typography.caption, color: colors.ink[500], fontStyle: 'italic', marginTop: spacing[1] },
  referenceDemonstration: { ...typography.caption, color: colors.danger.text, fontWeight: '700', marginTop: spacing[2] },
  referenceAvertissement: { ...typography.caption, color: colors.ink[500], marginTop: spacing[1] },
});
