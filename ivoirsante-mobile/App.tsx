import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { BackHandler } from 'react-native';
import { AccueilScreen } from './src/screens/AccueilScreen';
import { SymptomesScreen } from './src/screens/SymptomesScreen';
import { QuestionsScreen } from './src/screens/QuestionsScreen';
import { ResultatScreen } from './src/screens/ResultatScreen';
import { HistoriqueScreen } from './src/screens/HistoriqueScreen';
import { analyserTriage } from './src/api/triage';
import type {
  AnalyseResultat,
  AnalyserPayload,
  ContextePatient,
  Reponse,
  Symptome,
  ValeurReponse,
} from './src/types/triage';

/**
 * App — Module 1 (Triage). Navigation « assistant » à état local (pas de lib de
 * navigation : les modules 2-5, dont la navigation basse à 4 onglets du §5.6, ne sont
 * pas encore là → on n'anticipe jamais le module suivant).
 *
 * Flux : Accueil → Symptômes (F1.1) → Questions (F1.2) → Résultat (F1.3 + partage F1.8).
 * Le brouillon (sélection, patient, réponses) vit ici pour survivre aux retours arrière.
 */
type Route = 'accueil' | 'symptomes' | 'questions' | 'resultat' | 'historique';

export default function App() {
  const [route, setRoute] = useState<Route>('accueil');

  // Brouillon du triage en cours.
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

  // Symptômes sélectionnés (objets) et ceux qui ont des questions (F1.2).
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
      // On n'envoie que les réponses des symptômes encore sélectionnés.
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

  // Retour logique (utilisé par le bouton matériel Android et les flèches retour).
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
        return false; // accueil : laisse le système quitter l'app.
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
        // Garde-fou : pas de résultat → on repart proprement.
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
