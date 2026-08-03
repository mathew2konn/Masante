package ci.masante.payment.service;

import ci.masante.payment.domain.model.EntreeAudit;
import ci.masante.payment.repository.EntreeAuditRepository;
import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.util.List;
import java.util.Map;

/**
 * Journal d'audit immuable, append-only à hachage chaîné (CDC_06 §9.7).
 *
 * <p>Chaque ajout rejoint la transaction de l'appelant (cohérence forte : l'audit commit avec
 * l'opération financière). La dernière entrée est lue avec un verrou pessimiste pour sérialiser les
 * ajouts concurrents ; l'ancre GENESIS (seq 0, posée par Flyway) garantit une ligne à verrouiller.</p>
 */
@Service
public class ServiceAudit {

    private final EntreeAuditRepository depot;
    private final ObjectMapper json;

    public ServiceAudit(EntreeAuditRepository depot, ObjectMapper json) {
        this.depot = depot;
        this.json = json;
    }

    @Transactional
    public EntreeAudit enregistrer(String evenement, String refType, String refId, Map<String, Object> payload) {
        EntreeAudit derniere = depot.findDerniereVerrouillee().orElse(null);
        long sequence = derniere == null ? 1L : derniere.getSequence() + 1L;
        String previousHash = derniere == null ? "GENESIS" : derniere.getHash();

        String payloadJson = serialiser(payload);
        String hash = hacher(sequence, evenement, refType, refId, payloadJson, previousHash);

        return depot.save(new EntreeAudit(sequence, evenement, refType, refId, payloadJson, previousHash, hash));
    }

    @Transactional(readOnly = true)
    public List<EntreeAudit> pour(String refType, String refId) {
        return depot.findByRefTypeAndRefIdOrderBySequenceAsc(refType, refId);
    }

    /**
     * Revérifie l'intégrité de toute la chaîne : recalcule chaque hash et contrôle le chaînage.
     * @return true si aucune altération n'est détectée.
     */
    @Transactional(readOnly = true)
    public boolean verifierIntegrite() {
        String attenduPrecedent = "GENESIS";
        long attendueSequence = 0L;
        for (EntreeAudit e : depot.findAllByOrderBySequenceAsc()) {
            if (e.getSequence() == 0L) {
                // Ancre GENESIS : point de départ du chaînage, non recalculée.
                attenduPrecedent = e.getHash();
                attendueSequence = 1L;
                continue;
            }
            if (e.getSequence() != attendueSequence || !e.getPreviousHash().equals(attenduPrecedent)) {
                return false;
            }
            String recalcule = hacher(e.getSequence(), e.getEvenement(), e.getRefType(),
                    e.getRefId(), e.getPayload(), e.getPreviousHash());
            if (!recalcule.equals(e.getHash())) {
                return false;
            }
            attenduPrecedent = e.getHash();
            attendueSequence++;
        }
        return true;
    }

    private String serialiser(Map<String, Object> payload) {
        try {
            return json.writeValueAsString(payload);
        } catch (JsonProcessingException e) {
            throw new IllegalStateException("Sérialisation du payload d'audit impossible", e);
        }
    }

    private static String hacher(long sequence, String evenement, String refType, String refId,
                                 String payloadJson, String previousHash) {
        String canonique = sequence + "|" + evenement + "|" + refType + "|" + refId + "|"
                + payloadJson + "|" + previousHash;
        try {
            MessageDigest md = MessageDigest.getInstance("SHA-256");
            byte[] digest = md.digest(canonique.getBytes(StandardCharsets.UTF_8));
            StringBuilder hex = new StringBuilder(64);
            for (byte b : digest) {
                hex.append(Character.forDigit((b >> 4) & 0xF, 16));
                hex.append(Character.forDigit(b & 0xF, 16));
            }
            return hex.toString();
        } catch (NoSuchAlgorithmException e) {
            throw new IllegalStateException("SHA-256 indisponible", e);
        }
    }
}
