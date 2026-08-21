import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { BackHandler } from 'react-native';
import { AccueilScreen } from './AccueilScreen';
import { SymptomesScreen } from './SymptomesScreen';
import { ConstantesScreen } from './ConstantesScreen';
import { QuestionsScreen } from './QuestionsScreen';
import { ResultatScreen } from './ResultatScreen';
import { HistoriqueScreen } from './HistoriqueScreen';
import { analyserTriage } from '../api/triage';
import type {
  AnalyseResultat,
  AnalyserPayload,
  ConstanteSaisie,
  ContextePatient,
  Reponse,
  Symptome,
  ValeurReponse,
} from '../types/triage';

/**
 * TriageFlow — assistant de triage du Module 1, monté par l'onglet « Triage ».
 *
 * Conserve à l'identique la navigation « assistant » à état local du Module 1 (aucune
 * réécriture de la logique métier) : Accueil triage → Symptômes (F1.1) → Questions (F1.2)
 * → Résultat (F1.3 + partage F1.8), plus l'historique (F1.6). Le brouillon vit ici.
 */
type Route = 'accueil' | 'symptomes' | 'constantes' | 'questions' | 'resultat' | 'historique';

export function TriageFlow() {
  const [route, setRoute] = useState<Route>('accueil');

  const [symptomesCache, setSymptomesCache] = useState<Symptome[] | null>(null);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [patient, setPatient] = useState<ContextePatient>({});
  const [reponses, setReponses] = useState<Record<string, ValeurReponse>>({});

  // P10c-1 — Les constantes du §5.2, gardées EN TEXTE tant qu'elles sont à l'écran : convertir à
  // chaque frappe empêcherait de taper « 39. » avant le dixième, et un champ vidé deviendrait 0.
  const [constantes, setConstantes] = useState<Record<string, string>>({});
  const [resultat, setResultat] = useState<AnalyseResultat | null>(null);

  const reinitialiser = useCallback(() => {
    setSelectedIds([]);
    setPatient({});
    setReponses({});
    setConstantes({});
    setResultat(null);
  }, []);

  const toggleSymptome = useCallback((id: number) => {
    setSelectedIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  }, []);

  const setReponse = useCallback((cle: string, valeur: ValeurReponse) => {
    setReponses((prev) => ({ ...prev, [cle]: valeur }));
  }, []);

  const setConstante = useCallback((type: string, valeur: string) => {
    setConstantes((prev) => ({ ...prev, [type]: valeur }));
  }, []);

  /**
   * P10c-1 — Les constantes renseignées, sous la forme que l'API attend.
   *
   * Les champs vides et les saisies inexploitables sont ÉCARTÉS plutôt qu'envoyés : le triage est
   * facultatif depuis le Module 1, et transmettre une chaîne vide ferait refuser toute la requête
   * pour une mesure que le patient n'a simplement pas.
   *
   * Aucune borne n'est appliquée ici. Le serveur refuse lui-même une valeur hors plage, sur la
   * version publiée, et il la REFUSE au lieu de l'écrêter — filtrer ici en ferait une seconde
   * autorité, et surtout cacherait au patient que sa saisie posait problème.
   */
  const constantesEnvoyees = useMemo<ConstanteSaisie[]>(
    () =>
      Object.entries(constantes)
        .map(([type_mesure, brut]) => ({
          type_mesure,
          valeur: Number(String(brut).replace(',', '.')),
          brut,
        }))
        .filter((c) => c.brut.trim() !== '' && Number.isFinite(c.valeur))
        .map(({ type_mesure, valeur }) => ({ type_mesure, valeur })),
    [constantes],
  );

  /**
   * P10b-3-i — Les réponses déjà données, sous la forme que l'API attend.
   *
   * Les valeurs vides sont écartées : le questionnaire est facultatif depuis le Module 1, et
   * envoyer une chaîne vide ferait refuser la requête pour une question que le patient a
   * simplement passée.
   */
  const reponsesEnvoyees = useMemo<Reponse[]>(
    () =>
      Object.entries(reponses)
        .filter(([, v]) => v !== '' && v !== null && v !== undefined)
        .map(([cle, valeur]) => ({ cle, valeur })),
    [reponses],
  );

  // F1.3 — Construit le payload et lance l'analyse côté serveur.
  const analyser = useCallback(async () => {
    const payload: AnalyserPayload = {
      symptomes: selectedIds,
      ...(reponsesEnvoyees.length ? { reponses: reponsesEnvoyees } : {}),
      ...(constantesEnvoyees.length ? { constantes: constantesEnvoyees } : {}),
      patient_nom: patient.patient_nom ?? null,
      patient_age: patient.patient_age ?? null,
      patient_sexe: patient.patient_sexe ?? null,
    };

    const res = await analyserTriage(payload);
    setResultat(res);
    setRoute('resultat');
  }, [reponsesEnvoyees, selectedIds, patient]);

  // Retour logique au sein de l'assistant (bouton matériel Android).
  const retour = useCallback((): boolean => {
    switch (route) {
      case 'symptomes':
      case 'historique':
        setRoute('accueil');
        return true;
      case 'constantes':
        setRoute('symptomes');
        return true;
      case 'questions':
        setRoute('constantes');
        return true;
      case 'resultat':
        setRoute('accueil');
        return true;
      default:
        return false; // accueil triage : laisse le système gérer (onglet racine).
    }
  }, [route]);

  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', retour);
    return () => sub.remove();
  }, [retour]);

  switch (route) {
    case 'symptomes':
      return (
        <SymptomesScreen
          cached={symptomesCache}
          onCached={setSymptomesCache}
          selectedIds={selectedIds}
          onToggle={toggleSymptome}
          patient={patient}
          onPatientChange={setPatient}
          onBack={() => setRoute('accueil')}
          onContinue={() => setRoute('constantes')}
        />
      );

    case 'constantes':
      return (
        <ConstantesScreen
          valeurs={constantes}
          onSetValeur={setConstante}
          onBack={() => setRoute('symptomes')}
          onContinue={() => setRoute('questions')}
        />
      );

    case 'questions':
      return (
        <QuestionsScreen
          symptomes={selectedIds}
          patient={patient}
          reponses={reponses}
          reponsesEnvoyees={reponsesEnvoyees}
          onSetReponse={setReponse}
          constantes={constantesEnvoyees}
          onBack={() => setRoute('constantes')}
          onAnalyser={analyser}
        />
      );

    case 'resultat':
      return resultat ? (
        <ResultatScreen
          resultat={resultat}
          onNouveau={() => {
            reinitialiser();
            setRoute('symptomes');
          }}
          onAccueil={() => {
            reinitialiser();
            setRoute('accueil');
          }}
        />
      ) : (
        <AccueilScreen onStart={() => setRoute('symptomes')} onHistorique={() => setRoute('historique')} />
      );

    case 'historique':
      return <HistoriqueScreen onBack={() => setRoute('accueil')} />;

    case 'accueil':
    default:
      return (
        <AccueilScreen
          onStart={() => {
            reinitialiser();
            setRoute('symptomes');
          }}
          onHistorique={() => setRoute('historique')}
        />
      );
  }
}
