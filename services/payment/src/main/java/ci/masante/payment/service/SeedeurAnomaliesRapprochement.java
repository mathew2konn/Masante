package ci.masante.payment.service;

import ci.masante.payment.domain.model.CommissionConfig;
import ci.masante.payment.domain.model.Facture;
import ci.masante.payment.domain.model.FactureStatut;
import ci.masante.payment.domain.model.LigneReversement;
import ci.masante.payment.domain.model.ReversementReleve;
import ci.masante.payment.domain.model.TypeLigneReversement;
import ci.masante.payment.repository.CommissionConfigRepository;
import ci.masante.payment.repository.FactureRepository;
import ci.masante.payment.repository.LigneReversementRepository;
import ci.masante.payment.repository.ReversementReleveRepository;
import jakarta.persistence.EntityManager;
import jakarta.persistence.PersistenceContext;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.time.temporal.ChronoUnit;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;

/**
 * Injecteur d'anomalies du rapprochement « factures ↔ reversements » — outil de DÉV UNIQUEMENT
 * (gaté OFF par défaut).
 *
 * <p>Un run vert sur des données saines ne prouve RIEN (il peut être vert parce que vide). Ce seedeur
 * insère volontairement des incohérences ENTRE LES DEUX SOURCES — en contournant les services (écriture
 * directe en base) — pour prouver que chaque écart est réellement DÉTECTÉ. Trois fixtures, une par type :
 * B1 pièce due jamais reversée, B2 ligne orpheline, B3 montant divergent.</p>
 *
 * <p>Ce n'est PAS une correction ni une donnée de production : à n'activer qu'en dév/démo. Les données
 * injectées sont préfixées {@code ANOMALIE-} et suffixées d'un jeton par run (ré-exécutable).</p>
 */
@Service
public class SeedeurAnomaliesRapprochement {

    private static final String HASH_BIDON = "0".repeat(64); // CHAR(64) NOT NULL — non vérifié ici

    private final boolean actif;
    private final FactureRepository factures;
    private final CommissionConfigRepository configs;
    private final ReversementReleveRepository releves;
    private final LigneReversementRepository lignes;

    @PersistenceContext
    private EntityManager em;

    public SeedeurAnomaliesRapprochement(
            @Value("${masante.payment.reversement.rapprochement.dev-seed-enabled:false}") boolean actif,
            FactureRepository factures, CommissionConfigRepository configs,
            ReversementReleveRepository releves, LigneReversementRepository lignes) {
        this.actif = actif;
        this.factures = factures;
        this.configs = configs;
        this.releves = releves;
        this.lignes = lignes;
    }

    public boolean estActif() {
        return actif;
    }

    /** Injecte les 3 anomalies et renvoie la liste des références créées. */
    @Transactional
    public List<String> injecter() {
        String jeton = UUID.randomUUID().toString().substring(0, 8);
        int exercice = OffsetDateTime.now(ZoneOffset.UTC).getYear();
        String etab = "ANOMALIE-REV-" + jeton;
        List<String> refs = new ArrayList<>();

        // ── B1 — PIECE_NON_REVERSEE : facture PAYEE, réglée, soldée il y a longtemps, sur AUCUN relevé.
        //    soldee_a est piloté par trigger (insertable=false JPA) → on la fige via UPDATE natif : le
        //    trigger conserve une soldee_a fournie non nulle (pas de re-stamp).
        Facture b1 = factures.saveAndFlush(new Facture("ANOMALIE-B1-" + jeton, etab + "-B1", "ANO-PAT",
                exercice, "XOF", 6000, 0, 0, 6000, null, null, 0, 6000, FactureStatut.EMISE, HASH_BIDON));
        Instant vieux = Instant.now().minus(30, ChronoUnit.DAYS);
        em.createNativeQuery("update factures set statut='PAYEE', montant_regle=6000, soldee_a=:sa where id=:id")
                .setParameter("sa", OffsetDateTime.ofInstant(vieux, ZoneOffset.UTC))
                .setParameter("id", b1.getId())
                .executeUpdate();
        refs.add("B1 facture:" + b1.getNumero() + " → PIECE_NON_REVERSEE");

        // Relevé minimal (tous montants à zéro) pour héberger les lignes B2/B3. Nécessite une config de
        // commission (FK) : on la crée et la clôture (hors index « un seul taux ouvert »).
        CommissionConfig cfg = new CommissionConfig(etab, 0, vieux, "anomalie rapprochement", null, "anomalie");
        cfg.cloturer(Instant.now().plus(365, ChronoUnit.DAYS));
        cfg = configs.save(cfg);

        Instant debut = Instant.now().minus(10, ChronoUnit.DAYS);
        Instant fin = Instant.now().minus(1, ChronoUnit.DAYS);
        ReversementReleve releve = new ReversementReleve("ANOMALIE-REL-" + jeton, etab, exercice, debut, fin,
                Instant.now(), 1, "XOF", 0, 0, cfg.getId(), 0, 0, 0, 0, 0, null, HASH_BIDON, "anomalie");
        releve = releves.save(releve);

        // ── B2 — REVERSEMENT_SANS_PIECE : ligne FACTURE active pointant une facture inexistante (orpheline).
        UUID factureFantome = UUID.randomUUID();
        lignes.save(new LigneReversement(releve.getId(), TypeLigneReversement.FACTURE, factureFantome, null,
                "ANOMALIE-B2-" + jeton, Instant.now(), 5000, 0, 0, 5000));
        refs.add("B2 ligne facture:" + factureFantome + " → REVERSEMENT_SANS_PIECE");

        // ── B3 — MONTANT_REVERSE_DIVERGENT : facture PAYEE réglée 10000, imputée 7000 par une ligne active.
        //    Même établissement que le relevé (sinon ce serait un orphelin par divergence d'établissement).
        Facture b3 = factures.save(new Facture("ANOMALIE-B3-" + jeton, etab, "ANO-PAT", exercice, "XOF",
                10000, 0, 0, 10000, null, null, 0, 10000, FactureStatut.PAYEE, HASH_BIDON));
        b3.setMontantRegle(10000);
        b3 = factures.saveAndFlush(b3);
        lignes.save(new LigneReversement(releve.getId(), TypeLigneReversement.FACTURE, b3.getId(), null,
                b3.getNumero(), Instant.now(), 7000, 0, 0, 7000));
        refs.add("B3 facture:" + b3.getNumero() + " → MONTANT_REVERSE_DIVERGENT (imputé 7000 vs réglé 10000)");

        return refs;
    }
}
