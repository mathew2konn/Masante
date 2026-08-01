import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { BackHandler } from 'react-native';
import { AccueilScreen } from './AccueilScreen';
import { SymptomesScreen } from './SymptomesScreen';
import { QuestionsScreen } from './QuestionsScreen';
import { ResultatScreen } from './ResultatScreen';
import { HistoriqueScreen } from './HistoriqueScreen';
import { analyserTriage } from '../api/triage';
import type {
  AnalyseResultat,
  AnalyserPayload,
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
type Route = 'accueil' | 'symptomes' | 'questions' | 'resultat' | 'historique';

export function TriageFlow() {
  const [route, setRoute] = useState<Route>('accueil');

  const [symptomesCache, setSymptomesCache] = useState<Symptome[] | null>(null);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [patient, setPatient] = useState<ContextePatient>({});
  const [reponses, setReponses] = useState<Record<string, ValeurReponse>>({});
  const [resultat, setResultat] = useState<AnalyseResultat | null>(null);

  const reinitialiser = useCallback(() => {
    setSelectedIds([]);
    setPatient({});
    setReponses({});
    setResultat(null);
  }, []);

  const selectedSymptomes = useMemo(
    () => (symptomesCache ?? []).filter((s) => selectedIds.includes(s.id)),
    [symptomesCache, selectedIds],
  );
  const symptomesAvecQuestions = useMemo(
    () => selectedSymptomes.filter((s) => (s.questions_complementaires_json?.length ?? 0) > 0),
    [selectedSymptomes],
  );

  const toggleSymptome = useCallback((id: number) => {
    setSelectedIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  }, []);

  const setReponse = useCallback((key: string, valeur: ValeurReponse) => {
    setReponses((prev) => ({ ...prev, [key]: valeur }));
  }, []);

  // F1.3 — Construit le payload et lance l'analyse côté serveur.
  const analyser = useCallback(async () => {
    const reponsesArr: Reponse[] = Object.entries(reponses)
      .filter(([, v]) => v !== '' && v !== null && v !== undefined)
      .map(([key, valeur]) => {
        const sep = key.indexOf(':');
        return { symptome_id: Number(key.slice(0, sep)), cle: key.slice(sep + 1), valeur };
      })
      .filter((r) => selectedIds.includes(r.symptome_id));

    const payload: AnalyserPayload = {
      symptomes: selectedIds,
      ...(reponsesArr.length ? { reponses: reponsesArr } : {}),
      patient_nom: patient.patient_nom ?? null,
      patient_age: patient.patient_age ?? null,
      patient_sexe: patient.patient_sexe ?? null,
    };

    const res = await analyserTriage(payload);
    setResultat(res);
    setRoute('resultat');
  }, [reponses, selectedIds, patient]);

  // Retour logique au sein de l'assistant (bouton matériel Android).
  const retour = useCallback((): boolean => {
    switch (route) {
      case 'symptomes':
      case 'historique':
        setRoute('accueil');
        return true;
      case 'questions':
        setRoute('symptomes');
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
          onContinue={() => setRoute('questions')}
        />
      );

    case 'questions':
      return (
        <QuestionsScreen
          symptomesAvecQuestions={symptomesAvecQuestions}
          reponses={reponses}
          onSetReponse={setReponse}
          onBack={() => setRoute('symptomes')}
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
