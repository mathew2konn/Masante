package ci.masante.payment.service;

/** Sceau apposé sur un document : signature base64, clé publique (X.509 base64), algorithme. */
public record SceauSignature(String signature, String cléPublique, String algorithme) {
}
