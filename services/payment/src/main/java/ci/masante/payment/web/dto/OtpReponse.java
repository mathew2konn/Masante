package ci.masante.payment.web.dto;

import ci.masante.payment.service.ServiceSecuriteWallet.ResultatOtp;

import java.time.Instant;

/**
 * Réponse d'une demande d'OTP. {@code requis=false} si le montant est sous le seuil (aucun OTP).
 * {@code code} n'est renvoyé qu'en <b>mode simulé</b> (FT5) — le canal SMS est « prêt à activer ».
 */
public record OtpReponse(boolean requis, String code, Instant expireLe) {

    public static OtpReponse de(ResultatOtp r) {
        return new OtpReponse(r.requis(), r.code(), r.expireLe());
    }
}
