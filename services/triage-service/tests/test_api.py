"""Contrat d'API et dégradation honnête (P10c-2-i F5/F6, révisé par P10c-3-ii).

Ce fichier couvre le CONTRAT et les REFUS. Les prédictions réelles (chargement d'artefact, SHAP,
confiance, limites) vivent dans ``test_score.py`` — elles entraînent un vrai modèle et coûtent
plusieurs secondes, alors que ce qui suit doit rester instantané.
"""
from __future__ import annotations

from fastapi.testclient import TestClient

from app.main import app

client = TestClient(app)

EXEMPLE = {
    "reference": "triage:1234",
    # P10c-3-ii (F26) : une TRANCHE, jamais l'âge exact — le modèle a appris sur des tranches, et
    # l'âge exact n'a plus à sortir du backend (§9.4).
    "bande_age": "25-44",
    "sexe": "F",
    "symptomes": [12, 47],
    "constantes": {"temperature": 38.9, "pouls": 96},
    "duree_jours": 2,
    "intensite": 5,
    "grossesse": False,
    "score_antecedents": 6,
    "niveau_protocole": "modere",
}


def test_health():
    r = client.get("/health")
    assert r.status_code == 200
    assert r.json() == {"status": "UP"}


def test_ready_dit_l_etat_sans_jamais_proposer_de_repli():
    """Réécrit pour P10c-3-ii : `mode` décrit la POSTURE, `modele_charge` dit la disponibilité.

    L'ancienne version affirmait `mode == "sans_modele"`, ce qui mêlait les deux. La posture de ce
    service est `observation` qu'un modèle soit chargé ou non — il n'influence jamais une décision
    (F22). Ce qui compte et n'a pas changé : Y5, aucun repli « règles seules » n'est proposé.
    """
    r = client.get("/ready")
    assert r.status_code == 200
    corps = r.json()
    assert corps["mode"] == "observation"
    assert "regles_seules" not in corps.values()
    assert isinstance(corps["runs_charges"], list)


def test_score_sans_modele_designe_refuse_honnetement():
    """Régime nominal tant qu'aucune version n'est `actif` côté registre (F23)."""
    r = client.post("/api/v1/triage/score", json=EXEMPLE)
    assert r.status_code == 503
    corps = r.json()
    assert corps["motif"] == "modele_indisponible"
    assert corps["message"]  # jamais vide (Rule-005)
    assert "triage:1234" in corps["message"]


def test_modele_designe_mais_absent_a_son_propre_motif():
    """F23 — la panne d'exploitation ne se cache pas derrière le régime nominal.

    Le registre dit « le modèle actif est X », cette instance ne l'a pas. Servir un autre artefact
    ferait deux vérités sur ce qui a produit une prédiction médicale ; le confondre avec
    `modele_indisponible` rendrait la panne indiscernable du fonctionnement normal.
    """
    corps = dict(EXEMPLE, modele_attendu="run_qui_n_existe_pas")
    r = client.post("/api/v1/triage/score", json=corps)
    assert r.status_code == 503
    reponse = r.json()
    assert reponse["motif"] == "modele_absent_du_service"
    assert "run_qui_n_existe_pas" in reponse["message"]


def test_bande_age_inconnue_refuse_en_422_et_pas_en_500():
    """La divergence de bornes entre Laravel et ce service doit être LISIBLE.

    Sans exception nommée, `vecteur_features` levait un `ValueError` nu que FastAPI rendait en 500 :
    l'exploitant aurait lu « erreur interne » là où le fait est précis et actionnable. Même leçon
    que la classe à un seul exemplaire de P10c-3-i.
    """
    corps = dict(EXEMPLE, bande_age="30-40", modele_attendu="peu_importe")
    r = client.post("/api/v1/triage/score", json=corps)
    assert r.status_code == 422, "un 500 opaque plutôt qu'un refus nommé serait le défaut"
    reponse = r.json()
    assert reponse["motif"] == "bande_age_inconnue"
    assert "30-40" in reponse["message"]
    # Le refus NOMME les tranches admises — refuser sans dire quoi envoyer laisserait chercher.
    assert "25-44" in reponse["message"]


def test_reference_absente_est_rejetee_422():
    corps = dict(EXEMPLE)
    del corps["reference"]
    r = client.post("/api/v1/triage/score", json=corps)
    assert r.status_code == 422


def test_age_exact_n_est_plus_un_champ_du_contrat():
    """F26 — l'âge exact ne doit plus jamais sortir du backend.

    Envoyé quand même, il est écarté : ce vecteur garantit qu'il n'a pas été discrètement remis en
    service par une sous-classe, et qu'il n'atteint donc jamais le vecteur de features.
    """
    corps = dict(EXEMPLE, age=34)
    r = client.post("/api/v1/triage/score", json=corps)
    # Refusé pour absence de modèle, pas pour l'âge — mais `age` n'a été lu par personne.
    assert r.status_code == 503
    assert "age" not in app.openapi()["components"]["schemas"]["RequeteTriageScore"]["properties"]


def test_score_antecedents_est_bien_au_contrat():
    """Constat Z8 — il était envoyé par Laravel et écarté EN SILENCE par ce schéma.

    Sans effet tant que tout répondait 503 ; le jour où le modèle tourne, la feature aurait valu
    `NaN` à l'inférence alors qu'elle existait à l'entraînement. Il est désormais déclaré une seule
    fois, dans `TraitsCliniques` dont les deux schémas héritent (F25).
    """
    proprietes = app.openapi()["components"]["schemas"]["RequeteTriageScore"]["properties"]
    assert "score_antecedents" in proprietes
    assert "bande_age" in proprietes


def test_sexe_hors_vocabulaire_est_rejete_422():
    r = client.post("/api/v1/triage/score", json=dict(EXEMPLE, sexe="X"))
    assert r.status_code == 422


def test_intensite_hors_bornes_est_rejetee_422():
    r = client.post("/api/v1/triage/score", json=dict(EXEMPLE, intensite=11))
    assert r.status_code == 422


def test_docs_disponibles():
    assert client.get("/docs").status_code == 200
