package ci.masante.payment.service;

import ci.masante.payment.domain.model.Avoir;
import ci.masante.payment.domain.model.Facture;
import ci.masante.payment.domain.model.FactureLigne;
import com.google.zxing.BarcodeFormat;
import com.google.zxing.MultiFormatWriter;
import com.google.zxing.client.j2se.MatrixToImageWriter;
import com.google.zxing.common.BitMatrix;
import com.lowagie.text.Document;
import com.lowagie.text.Element;
import com.lowagie.text.Font;
import com.lowagie.text.FontFactory;
import com.lowagie.text.Image;
import com.lowagie.text.PageSize;
import com.lowagie.text.Paragraph;
import com.lowagie.text.Phrase;
import com.lowagie.text.pdf.PdfPCell;
import com.lowagie.text.pdf.PdfPTable;
import com.lowagie.text.pdf.PdfWriter;
import org.springframework.stereotype.Service;

import java.awt.Color;
import java.io.ByteArrayOutputStream;
import java.time.ZoneOffset;
import java.time.format.DateTimeFormatter;
import java.util.List;

/**
 * Rend une facture en PDF avec QR Code (CDC_06 §7.4 : PDF + QR + numérotation + horodatage).
 *
 * <p>Le QR encode numéro + montants + hash d'intégrité → vérifiable à l'accueil. Signature PKI
 * « prête à activer » (incrément ultérieur) ; le hash d'intégrité tient lieu de sceau en attendant.
 * Génération 100 % locale (OpenPDF + ZXing), aucun appel réseau.</p>
 */
@Service
public class ServiceFacturePdf {

    private static final DateTimeFormatter DATE =
            DateTimeFormatter.ofPattern("dd/MM/yyyy HH:mm").withZone(ZoneOffset.UTC);

    public byte[] genererPdf(Facture f, List<FactureLigne> lignes) {
        try {
            ByteArrayOutputStream sortie = new ByteArrayOutputStream();
            Document doc = new Document(PageSize.A4, 40, 40, 48, 40);
            PdfWriter.getInstance(doc, sortie);
            doc.open();

            Font titre = FontFactory.getFont(FontFactory.HELVETICA_BOLD, 16, new Color(0x0B, 0x5F, 0xA5));
            Font gras = FontFactory.getFont(FontFactory.HELVETICA_BOLD, 10);
            Font normal = FontFactory.getFont(FontFactory.HELVETICA, 10);
            Font petit = FontFactory.getFont(FontFactory.HELVETICA, 8, Color.DARK_GRAY);

            doc.add(new Paragraph("MASANTÉ — Facture", titre));
            doc.add(new Paragraph("Votre Santé Notre Priorité", petit));
            doc.add(chuchote(" "));

            doc.add(new Paragraph("Facture n° " + f.getNumero(), gras));
            doc.add(new Paragraph("Établissement : " + f.getEtablissementRef(), normal));
            if (f.getPatientRef() != null) {
                doc.add(new Paragraph("Patient : " + f.getPatientRef(), normal));
            }
            doc.add(new Paragraph("Exercice : " + f.getExercice()
                    + "     Émise le : " + DATE.format(f.getCreatedAt()), normal));
            doc.add(new Paragraph("Statut : " + f.getStatut(), normal));
            doc.add(chuchote(" "));

            PdfPTable table = new PdfPTable(new float[]{5, 1.2f, 2, 1.6f, 1.4f, 2});
            table.setWidthPercentage(100);
            entete(table, gras, "Désignation", "Qté", "P.U.", "Remise", "TVA %", "Montant TTC");
            for (FactureLigne l : lignes) {
                cellule(table, normal, l.getLibelle(), Element.ALIGN_LEFT);
                cellule(table, normal, String.valueOf(l.getQuantite()), Element.ALIGN_CENTER);
                cellule(table, normal, fcfa(l.getPrixUnitaire()), Element.ALIGN_RIGHT);
                cellule(table, normal, fcfa(l.getRemise()), Element.ALIGN_RIGHT);
                cellule(table, normal, l.getTauxTva() + " %", Element.ALIGN_CENTER);
                cellule(table, normal, fcfa(l.getMontantTtc()), Element.ALIGN_RIGHT);
            }
            doc.add(table);
            doc.add(chuchote(" "));

            PdfPTable totaux = new PdfPTable(new float[]{3, 2});
            totaux.setWidthPercentage(55);
            totaux.setHorizontalAlignment(Element.ALIGN_RIGHT);
            ligneTotal(totaux, normal, "Sous-total HT", fcfa(f.getSousTotalHt()));
            ligneTotal(totaux, normal, "Total remises", fcfa(f.getTotalRemises()));
            ligneTotal(totaux, normal, "Total TVA", fcfa(f.getTotalTva()));
            ligneTotal(totaux, gras, "Montant TTC", fcfa(f.getMontantTtc()));
            if (f.getCouvertureType() != null) {
                ligneTotal(totaux, normal,
                        "Prise en charge " + f.getCouvertureType() + " (" + f.getCouvertureTaux() + " %)",
                        "- " + fcfa(f.getMontantCouvert()));
            }
            ligneTotal(totaux, gras, "Reste à payer", fcfa(f.getResteAPayer()));
            doc.add(totaux);
            doc.add(chuchote(" "));

            Image qr = Image.getInstance(qrPng(contenuQr(f)));
            qr.scaleToFit(110, 110);
            doc.add(qr);
            doc.add(new Paragraph("QR de vérification — hash : " + f.getHashIntegrite().substring(0, 16) + "…", petit));
            doc.add(new Paragraph("Document généré par MASANTÉ. Signature PKI « prête à activer ».", petit));

            doc.close();
            return sortie.toByteArray();
        } catch (Exception e) {
            throw new IllegalStateException("Génération du PDF de facture impossible", e);
        }
    }

    /** Rend un avoir / note de crédit en PDF avec QR (CDC_06 §7.1). */
    public byte[] genererAvoirPdf(Avoir a) {
        try {
            ByteArrayOutputStream sortie = new ByteArrayOutputStream();
            Document doc = new Document(PageSize.A4, 40, 40, 48, 40);
            PdfWriter.getInstance(doc, sortie);
            doc.open();

            Font titre = FontFactory.getFont(FontFactory.HELVETICA_BOLD, 16, new Color(0xB0, 0x2A, 0x2A));
            Font gras = FontFactory.getFont(FontFactory.HELVETICA_BOLD, 10);
            Font normal = FontFactory.getFont(FontFactory.HELVETICA, 10);
            Font petit = FontFactory.getFont(FontFactory.HELVETICA, 8, Color.DARK_GRAY);

            doc.add(new Paragraph("MASANTÉ — Avoir (note de crédit)", titre));
            doc.add(new Paragraph("Votre Santé Notre Priorité", petit));
            doc.add(chuchote(" "));

            doc.add(new Paragraph("Avoir n° " + a.getNumero(), gras));
            doc.add(new Paragraph("Établissement : " + a.getEtablissementRef(), normal));
            doc.add(new Paragraph("Exercice : " + a.getExercice()
                    + "     Émis le : " + DATE.format(a.getCreatedAt()), normal));
            doc.add(new Paragraph("Facture d'origine (réf. interne) : " + a.getFactureId(), normal));
            doc.add(new Paragraph("Motif : " + a.getMotif(), normal));
            doc.add(chuchote(" "));

            PdfPTable totaux = new PdfPTable(new float[]{3, 2});
            totaux.setWidthPercentage(55);
            totaux.setHorizontalAlignment(Element.ALIGN_RIGHT);
            ligneTotal(totaux, gras, "Montant de l'avoir", "- " + fcfa(a.getMontant()));
            doc.add(totaux);
            doc.add(chuchote(" "));

            Image qr = Image.getInstance(qrPng(
                    "MASANTE-AV|" + a.getNumero() + "|MT:" + a.getMontant() + "|" + a.getHashIntegrite().substring(0, 16)));
            qr.scaleToFit(110, 110);
            doc.add(qr);
            String sceau = a.getSignature() != null
                    ? "Signé (" + a.getSignatureAlgo() + ")." : "Signature PKI « prête à activer ».";
            doc.add(new Paragraph("QR de vérification — hash : " + a.getHashIntegrite().substring(0, 16) + "…", petit));
            doc.add(new Paragraph("Document généré par MASANTÉ. " + sceau, petit));

            doc.close();
            return sortie.toByteArray();
        } catch (Exception e) {
            throw new IllegalStateException("Génération du PDF d'avoir impossible", e);
        }
    }

    private static String contenuQr(Facture f) {
        return "MASANTE|" + f.getNumero() + "|TTC:" + f.getMontantTtc()
                + "|RAP:" + f.getResteAPayer() + "|" + f.getHashIntegrite().substring(0, 16);
    }

    private static byte[] qrPng(String contenu) throws Exception {
        BitMatrix matrix = new MultiFormatWriter().encode(contenu, BarcodeFormat.QR_CODE, 220, 220);
        ByteArrayOutputStream png = new ByteArrayOutputStream();
        MatrixToImageWriter.writeToStream(matrix, "PNG", png);
        return png.toByteArray();
    }

    private static Paragraph chuchote(String s) {
        return new Paragraph(s, FontFactory.getFont(FontFactory.HELVETICA, 6));
    }

    private static void entete(PdfPTable t, Font f, String... libelles) {
        for (String l : libelles) {
            PdfPCell c = new PdfPCell(new Phrase(l, f));
            c.setBackgroundColor(new Color(0xE8, 0xF1, 0xFA));
            c.setPadding(5);
            t.addCell(c);
        }
    }

    private static void cellule(PdfPTable t, Font f, String texte, int alignement) {
        PdfPCell c = new PdfPCell(new Phrase(texte, f));
        c.setPadding(4);
        c.setHorizontalAlignment(alignement);
        t.addCell(c);
    }

    private static void ligneTotal(PdfPTable t, Font f, String libelle, String valeur) {
        PdfPCell g = new PdfPCell(new Phrase(libelle, f));
        g.setBorder(0);
        g.setPadding(3);
        PdfPCell d = new PdfPCell(new Phrase(valeur, f));
        d.setBorder(0);
        d.setPadding(3);
        d.setHorizontalAlignment(Element.ALIGN_RIGHT);
        t.addCell(g);
        t.addCell(d);
    }

    /** Séparateur de milliers façon FCFA (espace insécable) sans dépendre de la locale JVM. */
    private static String fcfa(long montant) {
        String chiffres = Long.toString(Math.abs(montant));
        StringBuilder sb = new StringBuilder();
        int c = 0;
        for (int i = chiffres.length() - 1; i >= 0; i--) {
            sb.append(chiffres.charAt(i));
            if (++c % 3 == 0 && i > 0) {
                sb.append(' ');
            }
        }
        String corps = sb.reverse().toString();
        return (montant < 0 ? "-" : "") + corps + " FCFA";
    }
}
