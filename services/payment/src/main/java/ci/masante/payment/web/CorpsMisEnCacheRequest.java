package ci.masante.payment.web;

import jakarta.servlet.ReadListener;
import jakarta.servlet.ServletInputStream;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletRequestWrapper;

import java.io.BufferedReader;
import java.io.ByteArrayInputStream;
import java.io.IOException;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;

/**
 * Enveloppe de requête qui MET EN CACHE le corps à la lecture, afin qu'il puisse être inspecté (filtre
 * anti-PAN) PUIS relu intact par le contrôleur. Sans elle, lire le flux une fois le viderait.
 */
class CorpsMisEnCacheRequest extends HttpServletRequestWrapper {

    private final byte[] corps;

    CorpsMisEnCacheRequest(HttpServletRequest requete) throws IOException {
        super(requete);
        this.corps = requete.getInputStream().readAllBytes();
    }

    String corpsUtf8() {
        return new String(corps, StandardCharsets.UTF_8);
    }

    @Override
    public ServletInputStream getInputStream() {
        ByteArrayInputStream source = new ByteArrayInputStream(corps);
        return new ServletInputStream() {
            @Override
            public int read() {
                return source.read();
            }

            @Override
            public boolean isFinished() {
                return source.available() == 0;
            }

            @Override
            public boolean isReady() {
                return true;
            }

            @Override
            public void setReadListener(ReadListener readListener) {
                // Mode bloquant : aucune notification asynchrone requise.
            }
        };
    }

    @Override
    public BufferedReader getReader() {
        return new BufferedReader(new InputStreamReader(getInputStream(), StandardCharsets.UTF_8));
    }
}
