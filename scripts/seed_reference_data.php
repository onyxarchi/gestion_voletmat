<?php
declare(strict_types=1);

/**
 * Données de référence (catégories TRI + exercices N4/N5).
 * @param PDO $pdo
 */
function seed_reference_data(PDO $pdo): void
{
    $categories = [
        ['VENTE', 'Ventes', 1],
        ['AVOIR', 'Avoir', 2],
        ['REM', 'Rémunération', 3],
        ['DIVIDENDES', 'Dividendes', 4],
        ['CCA', 'Compte courant associé', 5],
        ['IK', 'IK', 6],
        ['CESU', 'CESU', 7],
        ['FORMATION', 'Formation', 8],
        ['URSSAF', 'Charges', 9],
        ['PREV', 'Prévoyance (Madelin)', 10],
        ['PER', 'PER - GALYA - GAN Assurance', 11],
        ['PJ', 'Protection Juridique', 12],
        ['SMABTP', 'Assurance PRO', 13],
        ['OA', 'Cotisation Ordre Archi', 14],
        ['ASS BUREAU', 'Assurance du bureau', 15],
        ['CFE', 'CFE', 16],
        ['IS', 'IS', 17],
        ['COMPTA', 'Comptable', 18],
        ['JURIDIQUE', 'Actes obligatoires société', 19],
        ['TVA', 'Paiement de la TVA', 20],
        ['RECOUV', 'Frais de recouvrement (avocat)', 21],
        ['ASSOS', "Club d'entreprises / syndicat / association", 22],
        ['FOURN', 'Fournitures admin/ divers', 23],
        ['INFORMATIQUE', 'Informatique investissement matos', 24],
        ['LOGICIEL', 'Abonnement logiciel (marius, henrri, etc)', 25],
        ['ARCHICAD', 'Archicad mise à jour', 26],
        ['SITE', 'Site internet + WIX + commercial', 27],
        ['RESTO', 'Restaurant', 28],
        ['POSTE', 'Frais postaux', 29],
        ['TEL', 'Téléphone + internet', 30],
        ['NET', 'Fournisseur internet', 31],
        ['DEPLACEMENT', 'Frais de placement / parking/ asf', 32],
        ['BANQUE', 'Abonnement compte Bancaire', 33],
        ['EPARGNE', 'compte à terme (épargne)', 34],
        ['ADMIN', 'Assistante administrative', 35],
        ['SST', 'Sous-traitance - dessin / audit', 36],
        ['BUREAU', 'Loyer et charges locatives', 37],
        ['DIVERS', 'inclassable', 38],
    ];
    $ins = $pdo->prepare('INSERT OR IGNORE INTO categories (code, libelle, ordre) VALUES (?,?,?)');
    foreach ($categories as $c) {
        $ins->execute($c);
    }

    $exercices = [
        ['N4', 'Exercice N4 (juil. 2024 – juin 2025)', '2024-07-01', '2025-06-30', 0, 91760.0],
        ['N5', 'Exercice N5 (juil. 2025 – déc. 2026, 18 mois)', '2025-07-01', '2026-12-31', 1, 109561.65],
        // À partir du 1er janvier 2027 : années civiles uniquement (plus d’exercice long)
        ['N6', 'Exercice N6 (année civile 2027)', '2027-01-01', '2027-12-31', 0, null],
    ];
    $ins = $pdo->prepare(
        'INSERT OR IGNORE INTO exercices (code, libelle, date_debut, date_fin, actif, objectif_ca_ht) VALUES (?,?,?,?,?,?)'
    );
    foreach ($exercices as $e) {
        $ins->execute($e);
    }
    $pdo->exec("UPDATE exercices SET objectif_ca_ht = 91760 WHERE code = 'N4' AND objectif_ca_ht IS NULL");
    $pdo->exec("UPDATE exercices SET objectif_ca_ht = 109561.65 WHERE code = 'N5' AND objectif_ca_ht IS NULL");
    $pdo->exec("UPDATE exercices SET previ_mois = 12 WHERE code = 'N6' AND (previ_mois IS NULL OR previ_mois <> 12)");
    // Solde créditeur banque au 30/06/2025 → point de départ N5
    $pdo->exec(
        "UPDATE exercices SET solde_ouverture = 25235.76, solde_ouverture_date = '2025-06-30'
         WHERE code = 'N5'"
    );
}
