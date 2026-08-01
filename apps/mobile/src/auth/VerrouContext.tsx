import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { AppState, type AppStateStatus } from 'react-native';
import { lireConfig, type ConfigVerrou } from './verrou';

/**
 * VerrouContext — état du verrou applicatif (note Securite_2, chap. 3.3).
 *
 * Règles de comportement portées ici :
 *  - période de GRÂCE de 2 min : un déverrouillage réussi ouvre les sections sensibles sans
 *    re-demande pendant la navigation active ;
 *  - re-verrouillage IMMÉDIAT dès que l'app passe en arrière-plan.
 *
 * La logique de secret (PIN haché, biométrie, blocage) vit dans `verrou.ts` ; ce contexte ne
 * gère que l'état de session du verrou et l'écoute du cycle de vie de l'app.
 */
const GRACE_MS = 2 * 60 * 1000;

type VerrouValue = {
  config: ConfigVerrou;
  pretConfig: boolean;            // config chargée depuis le stockage sécurisé ?
  estOuvert: () => boolean;       // section sensible actuellement accessible ?
  ouvrir: () => void;             // après un déverrouillage réussi (démarre/renouvelle la grâce)
  toucherSiOuvert: () => void;    // prolonge la grâce si déjà ouvert (navigation active)
  verrouiller: () => void;        // re-verrouille immédiatement
  rafraichirConfig: () => Promise<void>;
};

const CONFIG_DEFAUT: ConfigVerrou = { actif: false, aPin: false, biometrie: false, biometrieDispo: false };

const VerrouContext = createContext<VerrouValue | null>(null);

export function useVerrou(): VerrouValue {
  const v = useContext(VerrouContext);
  if (v === null) throw new Error('useVerrou doit être utilisé dans un <VerrouProvider />.');
  return v;
}

export function VerrouProvider({ children }: { children: React.ReactNode }) {
  const [config, setConfig] = useState<ConfigVerrou>(CONFIG_DEFAUT);
  const [pretConfig, setPretConfig] = useState(false);
  // Horodatage de fin de grâce. Un `ref` miroir permet une lecture synchrone dans estOuvert().
  const [ouvertJusqua, setOuvertJusqua] = useState(0);
  const ouvertRef = useRef(0);

  const majOuvert = useCallback((valeur: number) => {
    ouvertRef.current = valeur;
    setOuvertJusqua(valeur);
  }, []);

  const rafraichirConfig = useCallback(async () => {
    setConfig(await lireConfig());
  }, []);

  useEffect(() => {
    (async () => {
      await rafraichirConfig();
      setPretConfig(true);
    })();
  }, [rafraichirConfig]);

  // Re-verrouillage dès la mise en arrière-plan (l'appareil peut changer de mains).
  useEffect(() => {
    const sub = AppState.addEventListener('change', (etat: AppStateStatus) => {
      if (etat === 'background' || etat === 'inactive') majOuvert(0);
    });
    return () => sub.remove();
  }, [majOuvert]);

  const estOuvert = useCallback(() => {
    if (!config.actif) return true; // verrou désactivé : tout est accessible.
    return Date.now() < ouvertRef.current;
  }, [config.actif]);

  const ouvrir = useCallback(() => majOuvert(Date.now() + GRACE_MS), [majOuvert]);

  const toucherSiOuvert = useCallback(() => {
    if (Date.now() < ouvertRef.current) majOuvert(Date.now() + GRACE_MS);
  }, [majOuvert]);

  const verrouiller = useCallback(() => majOuvert(0), [majOuvert]);

  const value = useMemo<VerrouValue>(
    () => ({ config, pretConfig, estOuvert, ouvrir, toucherSiOuvert, verrouiller, rafraichirConfig }),
    // ouvertJusqua est inclus pour re-render les consommateurs quand l'état de grâce change.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [config, pretConfig, ouvertJusqua, estOuvert, ouvrir, toucherSiOuvert, verrouiller, rafraichirConfig],
  );

  return <VerrouContext.Provider value={value}>{children}</VerrouContext.Provider>;
}
