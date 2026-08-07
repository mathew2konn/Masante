package ci.masante.payment.service;

import ci.masante.payment.domain.model.DestinationReversement;
import ci.masante.payment.domain.model.TypeDestination;
import ci.masante.payment.domain.reversement.ReglesDestination;
import ci.masante.payment.repository.DestinationReversementRepository;
import jakarta.persistence.EntityManager;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.UUID;

/**
 * Registre des destinations de reversement (CDC_06 §11). Append-only, une active par établissement,
 * chiffrée. Non-chevauchement garanti par l'index unique partiel {@code uq_dest_active_par_etab} ET,
 * sous concurrence, par un <b>verrou consultatif</b> (classid dédié) qui sérialise les écrivains autour
 * de la bascule « clôturer l'actuelle → ouvrir la nouvelle » (corrige le TOCTOU d'une vérification Java).
 */
@Service
public class ServiceDestinationReversement {

    /** classid dédié aux destinations (distinct de la config commission pour éviter les contentions croisées). */
    private static final int CLASSID_DESTINATION = 5511;

    private final DestinationReversementRepository depot;
    private final ServiceChiffrementDestination chiffrement;
    private final ServiceAudit audit;
    private final EntityManager em;

    public ServiceDestinationReversement(DestinationReversementRepository depot,
                                         ServiceChiffrementDestination chiffrement,
                                         ServiceAudit audit, EntityManager em) {
        this.depot = depot;
        this.chiffrement = chiffrement;
        this.audit = audit;
        this.em = em;
    }

    @Transactional
    public DestinationReversement ouvrir(String etablissementRef, TypeDestination type, String refClair,
                                         String motif, String acteur) {
        String ref = ReglesDestination.normaliser(type, refClair);
        ReglesDestination.valider(type, ref);

        verrouConsultatif(etablissementRef);

        UUID id = UUID.randomUUID(); // requis avant chiffrement (entre dans l'AAD)
        ServiceChiffrementDestination.ResultatChiffrement chiffre = chiffrement.chiffrer(ref, etablissementRef, id);
        String empreinte = chiffrement.empreinte(ref);
        String libelle = ReglesDestination.operateur(type, ref) + " " + empreinte.substring(0, 8);

        Instant maintenant = Instant.now();
        UUID remplaceId = null;
        Optional<DestinationReversement> active = depot.findByEtablissementRefAndValideAuIsNull(etablissementRef);
        if (active.isPresent()) {
            DestinationReversement ancienne = active.get();
            ancienne.cloturer(maintenant);
            // saveAndFlush : force l'UPDATE de clôture AVANT l'INSERT de la nouvelle active. Sans cela,
            // Hibernate ordonne les INSERT avant les UPDATE → deux valide_au NULL simultanés → violation
            // de uq_dest_active_par_etab.
            depot.saveAndFlush(ancienne);
            remplaceId = ancienne.getId();
        }

        DestinationReversement dest = depot.save(new DestinationReversement(id, etablissementRef, type,
                chiffre.cipher(), chiffre.nonce(), chiffre.cleVersion(), empreinte, chiffrement.versionEmpreinte(),
                libelle, maintenant, motif, remplaceId, acteur));

        audit.enregistrer("SettlementDestinationSet", "settlement_destination", id.toString(),
                Map.of("etablissement", etablissementRef, "type", type.name(),
                        "empreinte", empreinte, "acteur", acteur, "remplace", remplaceId == null ? "" : remplaceId.toString()));
        return dest;
    }

    @Transactional(readOnly = true)
    public Optional<DestinationReversement> active(String etablissementRef) {
        return depot.findByEtablissementRefAndValideAuIsNull(etablissementRef);
    }

    @Transactional(readOnly = true)
    public List<DestinationReversement> historique(String etablissementRef) {
        return depot.findByEtablissementRefOrderByValideDuDesc(etablissementRef);
    }

    private void verrouConsultatif(String etablissementRef) {
        em.createNativeQuery("SELECT pg_advisory_xact_lock(:classid, :objid)")
                .setParameter("classid", CLASSID_DESTINATION)
                .setParameter("objid", etablissementRef.hashCode())
                .getSingleResult();
    }
}
