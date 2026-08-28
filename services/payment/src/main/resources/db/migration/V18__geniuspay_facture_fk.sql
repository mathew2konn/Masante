-- Lot 7, correctif issu du G2 live — la facture visée par un checkout doit exister.
--
-- Le G2 a ouvert un checkout sur un identifiant de facture inventé. L'initiation l'a accepté sans
-- broncher ; l'incohérence n'est apparue qu'à l'arrivée du webhook, plusieurs minutes plus tard, sous
-- la forme d'une violation de `payments_facture_id_fkey` dans le relais planifié. Le paiement était
-- alors déjà ouvert chez le prestataire, et le patient aurait pu le régler.
--
-- Le contrôle doit avoir lieu là où l'appelant peut encore corriger : à l'initiation. Le moteur s'en
-- charge, ce n'est pas une garde applicative qu'on peut oublier d'écrire ailleurs.
--
-- Pas d'action référentielle en cascade : une facture ne se supprime pas dans ce service (§ immuabilité
-- financière), et un `SET NULL` ferait perdre la cible d'un checkout déjà ouvert.
ALTER TABLE geniuspay_transactions
    ADD CONSTRAINT fk_gp_tx_facture FOREIGN KEY (facture_id) REFERENCES factures (id);
