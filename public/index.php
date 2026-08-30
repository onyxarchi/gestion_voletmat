<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Voletmat\Auth;
use Voletmat\CaService;
use Voletmat\Database;
use Voletmat\Importers\CicExcelImporter;
use Voletmat\PlanningService;

Auth::startSession();

$page = $_GET['page'] ?? 'accueil';
if (!is_string($page) || !preg_match('/^[a-z0-9_-]+$/', $page)) {
    $page = 'accueil';
}

$publicPages = ['login'];
if (!in_array($page, $publicPages, true) && $page !== 'logout') {
    Auth::requireLogin();
}

$pdo = Database::pdo();

switch ($page) {
    case 'login':
        if (Auth::check()) {
            redirect('accueil');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $login = trim((string) ($_POST['login'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if (Auth::attempt($login, $password)) {
                redirect('accueil');
            }
            flash('erreur', 'Identifiant ou mot de passe incorrect.');
        }
        require dirname(__DIR__) . '/templates/login.php';
        break;

    case 'logout':
        Auth::logout();
        redirect('login');

    case 'accueil':
        $exercice = exercice_courant($pdo);
        $stats = ['nb_factures' => 0, 'ca_ht' => 0.0, 'nb_ops' => 0, 'ventes' => 0.0];
        $eid = $exercice ? (int) $exercice['id'] : null;
        if ($exercice) {
            $st = $pdo->prepare('SELECT COUNT(*) c, COALESCE(SUM(ht),0) s FROM factures WHERE exercice_id = ?');
            $st->execute([$eid]);
            $r = $st->fetch();
            $stats['nb_factures'] = (int) $r['c'];
            $stats['ca_ht'] = (float) $r['s'];
            $st = $pdo->prepare('SELECT COUNT(*) c FROM operations_bancaires WHERE exercice_id = ? OR exercice_id IS NULL');
            $st->execute([$eid]);
            $stats['nb_ops'] = (int) $st->fetchColumn();
            $st = $pdo->prepare(
                "SELECT COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) FROM operations_bancaires
                 WHERE categorie_code = 'VENTE' AND (exercice_id = ? OR exercice_id IS NULL)"
            );
            $st->execute([$eid]);
            $stats['ventes'] = (float) $st->fetchColumn();
        }
        $ca = (new CaService($pdo))->dashboard($eid);
        $prog = $ca['progression'];
        $stats['ca_progression'] = $prog ? (float) $prog[array_key_last($prog)]['ca_ht'] : 0.0;
        $stats['ca_evolution'] = $prog ? $prog[array_key_last($prog)]['evolution'] : null;
        require dirname(__DIR__) . '/templates/accueil.php';
        break;

    case 'factures':
        $exercice = exercice_courant($pdo);
        $factures = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $exercice) {
            csrf_verify();
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'creer') {
                $numero = trim((string) ($_POST['numero'] ?? ''));
                $date = trim((string) ($_POST['date_facture'] ?? ''));
                $client = trim((string) ($_POST['client'] ?? ''));
                $htRaw = str_replace([' ', ','], ['', '.'], trim((string) ($_POST['ht'] ?? '0')));
                $tauxRaw = str_replace([' ', ','], ['', '.'], trim((string) ($_POST['taux_tva'] ?? '20')));
                $canal = trim((string) ($_POST['canal'] ?? ''));
                $canal = $canal !== '' ? $canal : null;

                $ht = is_numeric($htRaw) ? (float) $htRaw : null;
                $tauxPct = is_numeric($tauxRaw) ? (float) $tauxRaw : null;
                $okDate = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);

                if ($numero === '' || $client === '' || !$okDate || $ht === null || $tauxPct === null) {
                    flash('erreur', 'Saisie incomplète : date, n°, client, HT et taux TVA sont obligatoires.');
                    redirect('factures');
                }
                if ($date < $exercice['date_debut'] || $date > $exercice['date_fin']) {
                    flash(
                        'attention',
                        'La date est hors exercice en cours (' .
                        date_fr($exercice['date_debut']) . ' → ' . date_fr($exercice['date_fin']) . '). Facture quand même enregistrée.'
                    );
                }

                $taux = $tauxPct / 100.0;
                $tva = round($ht * $taux, 2);
                $ttc = round($ht + $tva, 2);
                $eid = (int) $exercice['id'];

                try {
                    $pdo->prepare(
                        'INSERT INTO factures
                        (exercice_id, numero, date_facture, client, ht, taux_tva, tva, ttc, canal, notes)
                        VALUES (?,?,?,?,?,?,?,?,?,?)'
                    )->execute([
                        $eid,
                        $numero,
                        $date,
                        $client,
                        $ht,
                        $taux,
                        $tva,
                        $ttc,
                        $canal,
                        'saisi',
                    ]);
                    flash('ok', 'Facture ' . $numero . ' enregistrée.');
                } catch (PDOException $e) {
                    if (str_contains($e->getMessage(), 'UNIQUE')) {
                        flash('erreur', 'Ce n° de facture existe déjà pour cet exercice.');
                    } else {
                        flash('erreur', 'Enregistrement impossible.');
                    }
                }
                redirect('factures');
            }
        }

        if ($exercice) {
            $st = $pdo->prepare('SELECT * FROM factures WHERE exercice_id = ? ORDER BY date_facture DESC, id DESC');
            $st->execute([(int) $exercice['id']]);
            $factures = $st->fetchAll();
        }
        require dirname(__DIR__) . '/templates/factures.php';
        break;

    case 'banque':
        $st = $pdo->query('SELECT * FROM operations_bancaires ORDER BY date_operation DESC, id DESC LIMIT 500');
        $operations = $st->fetchAll();
        require dirname(__DIR__) . '/templates/banque.php';
        break;

    case 'exercices':
        exercice_courant($pdo); // synchronise le flag « en cours » selon la date
        $exercices = $pdo->query('SELECT * FROM exercices ORDER BY date_debut')->fetchAll();
        require dirname(__DIR__) . '/templates/exercices.php';
        break;

    case 'import':
        $preview = null;
        $import_id = isset($_GET['import_id']) ? (int) $_GET['import_id'] : null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $action = (string) ($_POST['action'] ?? '');

            if ($action === 'upload') {
                if (empty($_FILES['fichier']['tmp_name']) || !is_uploaded_file($_FILES['fichier']['tmp_name'])) {
                    flash('erreur', 'Fichier manquant.');
                    redirect('import');
                }
                $name = (string) ($_FILES['fichier']['name'] ?? 'releve.xlsx');
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, ['xlsx', 'xls', 'csv', 'pdf'], true)) {
                    flash('erreur', 'Format non accepté.');
                    redirect('import');
                }
                if ($ext !== 'xlsx') {
                    flash('attention', 'Pour l’instant seul l’Excel CIC (.xlsx) est parsé. CSV/PDF : à venir.');
                    redirect('import');
                }
                if ((int) $_FILES['fichier']['size'] > (int) app_config('upload_max_bytes')) {
                    flash('erreur', 'Fichier trop volumineux.');
                    redirect('import');
                }

                $destDir = (string) app_config('storage_uploads');
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0775, true);
                }
                $stored = $destDir . '/' . date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
                if (!move_uploaded_file($_FILES['fichier']['tmp_name'], $stored)) {
                    flash('erreur', 'Échec de l’enregistrement du fichier.');
                    redirect('import');
                }

                try {
                    $parsed = (new CicExcelImporter())->parse($stored);
                } catch (Throwable $e) {
                    flash('erreur', 'Lecture impossible : ' . $e->getMessage());
                    redirect('import');
                }

                $pdo->beginTransaction();
                $pdo->prepare(
                    'INSERT INTO imports (fichier_nom, format, statut, lignes_lues) VALUES (?,?,?,?)'
                )->execute([$name, 'cic_xlsx', 'brouillon', count($parsed['lignes'])]);
                $import_id = (int) $pdo->lastInsertId();

                $ins = $pdo->prepare(
                    'INSERT INTO imports_lignes
                    (import_id, ligne_no, date_operation, date_valeur, libelle, debit, credit, statut, motif, empreinte, raw_json)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                );
                $chk = $pdo->prepare('SELECT 1 FROM operations_bancaires WHERE empreinte = ? LIMIT 1');

                foreach ($parsed['lignes'] as &$ligne) {
                    if ($ligne['statut'] === 'ok' && $ligne['empreinte']) {
                        $chk->execute([$ligne['empreinte']]);
                        if ($chk->fetch()) {
                            $ligne['statut'] = 'doublon';
                            $ligne['motif'] = 'Déjà présent en base (même date, libellé, montants)';
                        }
                    }
                    $ins->execute([
                        $import_id,
                        $ligne['ligne_no'],
                        $ligne['date_operation'],
                        $ligne['date_valeur'],
                        $ligne['libelle'],
                        $ligne['debit'],
                        $ligne['credit'],
                        $ligne['statut'],
                        $ligne['motif'],
                        $ligne['empreinte'],
                        json_encode($ligne, JSON_UNESCAPED_UNICODE),
                    ]);
                }
                unset($ligne);
                $pdo->commit();
                flash('info', 'Prévisualisation prête. Rien n’est encore comptabilisé.');
                redirect('import', ['import_id' => $import_id]);
            }

            if ($action === 'valider') {
                $import_id = (int) ($_POST['import_id'] ?? 0);
                $st = $pdo->prepare('SELECT * FROM imports WHERE id = ? AND statut = ?');
                $st->execute([$import_id, 'brouillon']);
                $imp = $st->fetch();
                if (!$imp) {
                    flash('erreur', 'Import introuvable ou déjà validé.');
                    redirect('import');
                }
                $exercice = exercice_courant($pdo);
                $eid = $exercice ? (int) $exercice['id'] : null;

                $st = $pdo->prepare("SELECT * FROM imports_lignes WHERE import_id = ? AND statut = 'ok'");
                $st->execute([$import_id]);
                $lignes = $st->fetchAll();

                $pdo->beginTransaction();
                $ins = $pdo->prepare(
                    'INSERT OR IGNORE INTO operations_bancaires
                    (exercice_id, date_operation, date_valeur, libelle, debit, credit, annee_mois, source, import_id, empreinte)
                    VALUES (?,?,?,?,?,?,?,?,?,?)'
                );
                $accepted = 0;
                foreach ($lignes as $l) {
                    $mois = $l['date_operation'] ? annee_mois_from_date($l['date_operation']) : null;
                    $ins->execute([
                        $eid,
                        $l['date_operation'],
                        $l['date_valeur'],
                        $l['libelle'],
                        $l['debit'],
                        $l['credit'],
                        $mois,
                        'import_cic',
                        $import_id,
                        $l['empreinte'],
                    ]);
                    if ($ins->rowCount() > 0) {
                        $accepted++;
                    }
                }
                $pdo->prepare(
                    'UPDATE imports SET statut = ?, lignes_acceptees = ?, valide_at = datetime(\'now\') WHERE id = ?'
                )->execute(['valide', $accepted, $import_id]);
                $pdo->commit();
                flash('ok', $accepted . ' opération(s) enregistrée(s). Doublons / incertains non comptabilisés.');
                redirect('banque');
            }
        }

        if ($import_id) {
            $st = $pdo->prepare('SELECT * FROM imports WHERE id = ?');
            $st->execute([$import_id]);
            $imp = $st->fetch();
            if ($imp) {
                $st = $pdo->prepare('SELECT * FROM imports_lignes WHERE import_id = ? ORDER BY ligne_no');
                $st->execute([$import_id]);
                $preview = [
                    'compte' => $imp['fichier_nom'],
                    'solde_final' => null,
                    'lignes' => $st->fetchAll(),
                ];
            }
        }
        require dirname(__DIR__) . '/templates/import.php';
        break;

    case 'planning':
        $exercice = exercice_courant($pdo);
        $ajax = wants_json();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $exercice) {
            csrf_verify();
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'maj_affaire') {
                $affaireId = (int) ($_POST['affaire_id'] ?? 0);
                $reference = trim((string) ($_POST['reference'] ?? ''));
                $client = trim((string) ($_POST['client'] ?? ''));
                $contratRaw = str_replace([' ', ',', "\xc2\xa0"], ['', '.', ''], trim((string) ($_POST['montant_contrat_ht'] ?? '')));
                $contrat = $contratRaw === '' ? null : (is_numeric($contratRaw) ? (float) $contratRaw : null);

                $st = $pdo->prepare('SELECT id, encaisse_n1 FROM affaires WHERE id = ? AND exercice_id = ?');
                $st->execute([$affaireId, (int) $exercice['id']]);
                $aff = $st->fetch();
                if (!$aff) {
                    if ($ajax) {
                        json_out(['ok' => false, 'erreur' => 'Affaire introuvable.'], 404);
                    }
                    flash('erreur', 'Affaire introuvable.');
                    redirect('planning');
                }
                if ($client === '') {
                    if ($ajax) {
                        json_out(['ok' => false, 'erreur' => 'Le client est obligatoire.'], 422);
                    }
                    flash('erreur', 'Le client est obligatoire.');
                    redirect('planning', [], 'row-' . $affaireId);
                }
                if ($reference === '') {
                    $reference = 'Divers';
                }

                $type = PlanningService::detectType($reference);
                $moisPost = $_POST['mois'] ?? [];
                if (!is_array($moisPost)) {
                    $moisPost = [];
                }

                try {
                    $pdo->beginTransaction();
                    $pdo->prepare(
                        'UPDATE affaires
                         SET reference = ?, client = ?, montant_contrat_ht = ?, type = ?
                         WHERE id = ? AND exercice_id = ?'
                    )->execute([
                        $reference,
                        $client,
                        $contrat,
                        $type,
                        $affaireId,
                        (int) $exercice['id'],
                    ]);

                    $selEch = $pdo->prepare(
                        'SELECT id FROM echeances_facturation WHERE affaire_id = ? AND annee_mois = ?'
                    );
                    $insEch = $pdo->prepare(
                        'INSERT INTO echeances_facturation (affaire_id, annee_mois, montant_ht, statut, couleur)
                         VALUES (?,?,?,?,?)'
                    );
                    $updEch = $pdo->prepare(
                        'UPDATE echeances_facturation SET montant_ht = ?, statut = ?, couleur = ? WHERE id = ?'
                    );
                    $delEch = $pdo->prepare(
                        'DELETE FROM echeances_facturation WHERE affaire_id = ? AND annee_mois = ?'
                    );

                    $statutsPost = $_POST['statut'] ?? [];
                    if (!is_array($statutsPost)) {
                        $statutsPost = [];
                    }
                    $statutsOk = [
                        'a_facturer' => 'rouge',
                        'facture' => 'vert',
                        'litige' => 'jaune',
                        'paye' => 'bleu',
                    ];

                    foreach ($moisPost as $anneeMois => $raw) {
                        if (!is_string($anneeMois) || !preg_match('/^\d{6}$/', $anneeMois)) {
                            continue;
                        }
                        $raw = str_replace([' ', ',', "\xc2\xa0"], ['', '.', ''], trim((string) $raw));
                        if ($raw === '' || !is_numeric($raw) || (float) $raw == 0.0) {
                            $delEch->execute([$affaireId, $anneeMois]);
                            continue;
                        }
                        $montant = round((float) $raw, 2);
                        $statut = (string) ($statutsPost[$anneeMois] ?? 'a_facturer');
                        if (!isset($statutsOk[$statut])) {
                            $statut = 'a_facturer';
                        }
                        $couleur = $statutsOk[$statut];
                        $selEch->execute([$affaireId, $anneeMois]);
                        $exist = $selEch->fetch();
                        if ($exist) {
                            $updEch->execute([$montant, $statut, $couleur, (int) $exist['id']]);
                        } else {
                            $insEch->execute([$affaireId, $anneeMois, $montant, $statut, $couleur]);
                        }
                    }
                    $pdo->commit();
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $msg = str_contains($e->getMessage(), 'UNIQUE')
                        ? 'Enregistrement impossible (contrainte unique).'
                        : 'Mise à jour impossible.';
                    if ($ajax) {
                        json_out(['ok' => false, 'erreur' => $msg], 500);
                    }
                    flash('erreur', $msg);
                    redirect('planning', [], 'row-' . $affaireId);
                }

                if ($ajax) {
                    $stSum = $pdo->prepare(
                        'SELECT COALESCE(SUM(montant_ht), 0) FROM echeances_facturation WHERE affaire_id = ?'
                    );
                    $stSum->execute([$affaireId]);
                    $factureEnN = round((float) $stSum->fetchColumn(), 2);
                    $encaisseN1 = (float) $aff['encaisse_n1'];
                    $restant = $contrat !== null
                        ? round($contrat - $encaisseN1 - $factureEnN, 2)
                        : null;
                    json_out([
                        'ok' => true,
                        'affaire_id' => $affaireId,
                        'reference' => $reference,
                        'facture_en_n' => $factureEnN,
                        'facture_en_n_label' => euro($factureEnN),
                        'restant_a_facturer' => $restant,
                        'restant_a_facturer_label' => euro($restant),
                    ]);
                }
                redirect('planning', [], 'row-' . $affaireId);
            }
        }

        $planning = [
            'mois' => [],
            'lignes' => [],
            'totaux_mois' => [],
            'totaux_statut' => [],
        ];
        if ($exercice) {
            $planning = (new PlanningService($pdo))->grille((int) $exercice['id']);
        }
        require dirname(__DIR__) . '/templates/planning.php';
        break;

    case 'analytique':
        $title = 'Compta analytique';
        $message = 'Sommes par code TRI et par mois, calculées depuis la banque (pas de double comptage). À venir après import.';
        require dirname(__DIR__) . '/templates/placeholder.php';
        break;

    case 'previsionnel':
        $title = 'Prévisionnel';
        $message = 'Budget société vs réel (feuille Prévisionnel). À venir après import.';
        require dirname(__DIR__) . '/templates/placeholder.php';
        break;

    case 'export':
        $title = 'Export / sauvegarde';
        $message = 'Export CSV/Excel et copie du fichier SQLite (storage/data/). Fonction à compléter.';
        require dirname(__DIR__) . '/templates/placeholder.php';
        break;

    default:
        http_response_code(404);
        $title = 'Page introuvable';
        $message = 'Cette page n’existe pas.';
        $page = 'accueil';
        require dirname(__DIR__) . '/templates/placeholder.php';
}
