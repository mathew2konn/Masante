package ci.masante.payment.config;

import jakarta.annotation.PostConstruct;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.Positive;
import org.springframework.boot.context.properties.ConfigurationProperties;
import org.springframework.stereotype.Component;
import org.springframework.validation.annotation.Validated;

/**
 * Configuration de l'intégration GeniusPay (§6.1). Toutes les valeurs sont des <b>données</b> :
 * aucun seuil, aucun délai, aucune URL n'est écrit dans le code.
 *
 * <p><b>Garde-fou D7.</b> {@code environnement} doit valoir {@code sandbox}. Toute autre valeur fait
 * <b>échouer le démarrage</b>, elle n'est ni corrigée ni ignorée : un service qui démarrerait en
 * « live » parce qu'une variable a été mal saisie encaisserait de l'argent réel sur une intégration
 * qui n'a jamais été validée pour cela.</p>
 */
@Component
@Validated
@ConfigurationProperties(prefix = "masante.payment.geniuspay")
public class ProprietesGeniusPay {

    public static final String ENVIRONNEMENT_AUTORISE = "sandbox";

    @NotBlank
    private String environnement = ENVIRONNEMENT_AUTORISE;

    /** Aucune valeur par défaut : une base absente doit s'entendre, jamais se deviner. */
    @NotBlank
    private String baseUrl;

    @Positive
    private int timeoutConnexionMs = 5000;

    @Positive
    private int timeoutLectureMs = 15000;

    @Positive
    private long montantMinimum = 200;

    @Positive
    private long fenetreAntirejeuSecondes = 300;

    @Positive
    private long leveeIncertitudeMinutes = 15;

    @Positive
    private int balayageMaxConsultations = 200;

    @Positive
    private long abandonApresHeures = 24;

    /**
     * Écrans applicatifs de retour. Ils <b>n'authentifient rien</b> (D5) : le patient qui revient sur
     * {@code success_url} n'a rien prouvé, seul le webhook fait foi. Ils servent l'expérience, jamais
     * la décision.
     */
    private String successUrl;

    private String errorUrl;

    private Planification planification = new Planification();

    @PostConstruct
    void verifierAuDemarrage() {
        // L'ÉCHEC BRUYANT NE VA PAS DE SOI, et il a fallu un démarrage réel pour s'en apercevoir.
        // `${GENIUSPAY_BASE_URL}` sans valeur par défaut ne fait PAS échouer le binding : le
        // résolveur de `@ConfigurationProperties` laisse passer un placeholder non résolu et rend la
        // chaîne littérale. Elle n'est pas vide, donc `@NotBlank` est satisfaite — et le service
        // démarre en apparence sain, pour n'échouer qu'au premier paiement, avec un message
        // incompréhensible. On vérifie donc que l'URL est RÉSOLUE, pas seulement présente.
        if (baseUrl == null || baseUrl.contains("${") || !(baseUrl.startsWith("http://")
                || baseUrl.startsWith("https://"))) {
            throw new IllegalStateException(
                    "GENIUSPAY_BASE_URL n'est pas renseignée : masante.payment.geniuspay.base-url vaut « "
                    + baseUrl + " ». Une base absente doit s'entendre au démarrage, jamais se deviner.");
        }
        if (!ENVIRONNEMENT_AUTORISE.equals(environnement)) {
            throw new IllegalStateException(
                    "masante.payment.geniuspay.environnement doit valoir '" + ENVIRONNEMENT_AUTORISE
                    + "' (D7 : sandbox uniquement). Valeur reçue : '" + environnement + "'.");
        }
    }

    public static class Planification {
        private boolean enabled = true;
        private long traitementEvenementsMs = 5000;
        private long reconciliationMs = 600000;

        public boolean isEnabled() {
            return enabled;
        }

        public void setEnabled(boolean enabled) {
            this.enabled = enabled;
        }

        public long getTraitementEvenementsMs() {
            return traitementEvenementsMs;
        }

        public void setTraitementEvenementsMs(long traitementEvenementsMs) {
            this.traitementEvenementsMs = traitementEvenementsMs;
        }

        public long getReconciliationMs() {
            return reconciliationMs;
        }

        public void setReconciliationMs(long reconciliationMs) {
            this.reconciliationMs = reconciliationMs;
        }
    }

    public String getEnvironnement() {
        return environnement;
    }

    public void setEnvironnement(String environnement) {
        this.environnement = environnement;
    }

    public String getBaseUrl() {
        return baseUrl;
    }

    public void setBaseUrl(String baseUrl) {
        this.baseUrl = baseUrl;
    }

    public int getTimeoutConnexionMs() {
        return timeoutConnexionMs;
    }

    public void setTimeoutConnexionMs(int timeoutConnexionMs) {
        this.timeoutConnexionMs = timeoutConnexionMs;
    }

    public int getTimeoutLectureMs() {
        return timeoutLectureMs;
    }

    public void setTimeoutLectureMs(int timeoutLectureMs) {
        this.timeoutLectureMs = timeoutLectureMs;
    }

    public long getMontantMinimum() {
        return montantMinimum;
    }

    public void setMontantMinimum(long montantMinimum) {
        this.montantMinimum = montantMinimum;
    }

    public long getFenetreAntirejeuSecondes() {
        return fenetreAntirejeuSecondes;
    }

    public void setFenetreAntirejeuSecondes(long fenetreAntirejeuSecondes) {
        this.fenetreAntirejeuSecondes = fenetreAntirejeuSecondes;
    }

    public long getLeveeIncertitudeMinutes() {
        return leveeIncertitudeMinutes;
    }

    public void setLeveeIncertitudeMinutes(long leveeIncertitudeMinutes) {
        this.leveeIncertitudeMinutes = leveeIncertitudeMinutes;
    }

    public int getBalayageMaxConsultations() {
        return balayageMaxConsultations;
    }

    public void setBalayageMaxConsultations(int balayageMaxConsultations) {
        this.balayageMaxConsultations = balayageMaxConsultations;
    }

    public long getAbandonApresHeures() {
        return abandonApresHeures;
    }

    public void setAbandonApresHeures(long abandonApresHeures) {
        this.abandonApresHeures = abandonApresHeures;
    }

    public String getSuccessUrl() {
        return successUrl;
    }

    public void setSuccessUrl(String successUrl) {
        this.successUrl = successUrl;
    }

    public String getErrorUrl() {
        return errorUrl;
    }

    public void setErrorUrl(String errorUrl) {
        this.errorUrl = errorUrl;
    }

    public Planification getPlanification() {
        return planification;
    }

    public void setPlanification(Planification planification) {
        this.planification = planification;
    }
}
