<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Voletmat\Auth;
use Voletmat\AnalytiqueService;
use Voletmat\BanqueTriSuggester;
use Voletmat\CaService;
use Voletmat\Database;
use Voletmat\Importers\ReleveImporter;
use Voletmat\PlanningService;
use Voletmat\PrevisionnelService;

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
                $htRaw = str_replace(
                    [' ', ',', "\xc2\xa0", "\xe2\x80\xaf"],
                    ['', '.', '', ''],
                    trim((string) ($_POST['ht'] ?? '0'))
                );
                $tauxRaw = str_replace(
                    [' ', ',', "\xc2\xa0", "\xe2\x80\xaf"],
                    ['', '.', '', ''],
                    trim((string) ($_POST['taux_tva'] ?? '20'))
                );
                $tag = strtolower(trim((string) ($_POST['tag'] ?? ($_POST['canal'] ?? ''))));
                $canal = null;
                $estMar = 0;
                if ($tag === 'mar') {
                    $estMar = 1;
                } elseif ($tag === 'stripe') {
                    $canal = 'Stripe';
                }

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
                        (exercice_id, numero, date_facture, client, ht, taux_tva, tva, ttc, canal, est_mar, notes)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)'
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
                        $estMar,
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

            if ($action === 'maj_meta_facture') {
                $ajax = wants_json();
                $fid = (int) ($_POST['facture_id'] ?? 0);
                $st = $pdo->prepare('SELECT id FROM factures WHERE id = ? AND exercice_id = ?');
                $st->execute([$fid, (int) $exercice['id']]);
                if (!$st->fetch()) {
                    if ($ajax) {
                        json_out(['ok' => false, 'erreur' => 'Facture introuvable.'], 404);
                    }
                    flash('erreur', 'Facture introuvable.');
                    redirect('factures');
                }
                $tag = strtolower(trim((string) ($_POST['tag'] ?? '')));
                // Compat ancien champ
                if ($tag === '' && isset($_POST['est_mar'])) {
                    $old = trim((string) $_POST['est_mar']);
                    $tag = ($old === '1' || $old === 'oui') ? 'mar' : '';
                }
                $canal = null;
                $estMar = 0;
                if ($tag === 'mar') {
                    $estMar = 1;
                } elseif ($tag === 'stripe') {
                    $canal = 'Stripe';
                }
                $pdo->prepare(
                    'UPDATE factures SET est_mar = ?, canal = ? WHERE id = ? AND exercice_id = ?'
                )->execute([$estMar, $canal, $fid, (int) $exercice['id']]);
                if ($ajax) {
                    $eid = (int) $exercice['id'];
                    $meta = (new PlanningService($pdo))->metaFactures($eid);
                    $recap = (new CaService($pdo))->dashboard($eid)['recap'] ?? null;
                    json_out([
                        'ok' => true,
                        'facture_id' => $fid,
                        'meta' => $meta[$fid] ?? null,
                        'recap' => $recap,
                    ]);
                }
                redirect('factures');
            }
        }

        $metaFactures = [];
        $recap = null;
        if ($exercice) {
            $st = $pdo->prepare('SELECT * FROM factures WHERE exercice_id = ? ORDER BY date_facture DESC, id DESC');
            $st->execute([(int) $exercice['id']]);
            $factures = $st->fetchAll();
            $metaFactures = (new PlanningService($pdo))->metaFactures((int) $exercice['id']);
            $caDash = (new CaService($pdo))->dashboard((int) $exercice['id']);
            $recap = $caDash['recap'] ?? null;
        }
        require dirname(__DIR__) . '/templates/factures.php';
        break;

    case 'banque':
        $exercice = exercice_courant($pdo);
        $banqueStats = ['nb' => 0, 'debits' => 0.0, 'credits' => 0.0, 'ventes' => 0.0];
        $categories = $pdo->query(
            'SELECT code, libelle FROM categories ORDER BY code COLLATE NOCASE'
        )->fetchAll();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $exercice) {
            csrf_verify();
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'maj_tri') {
                $ajax = wants_json();
                $oid = (int) ($_POST['operation_id'] ?? 0);
                $tri = trim((string) ($_POST['categorie_code'] ?? ''));
                if ($tri === '') {
                    // Chaîne vide = choix manuel « — » (ne pas re-suggérer)
                    $triDb = '';
                } else {
                    $chk = $pdo->prepare('SELECT code FROM categories WHERE code = ?');
                    $chk->execute([$tri]);
                    if (!$chk->fetch()) {
                        if ($ajax) {
                            json_out(['ok' => false, 'erreur' => 'Code TRI inconnu.'], 400);
                        }
                        flash('erreur', 'Code TRI inconnu.');
                        redirect('banque');
                    }
                    $triDb = $tri;
                }
                $st = $pdo->prepare(
                    'UPDATE operations_bancaires SET categorie_code = ?
                     WHERE id = ? AND (exercice_id = ? OR exercice_id IS NULL)'
                );
                $st->execute([$triDb, $oid, (int) $exercice['id']]);
                if ($ajax) {
                    json_out(['ok' => true, 'operation_id' => $oid, 'categorie_code' => $triDb]);
                }
                redirect('banque');
            }

            if ($action === 'maj_ligne') {
                $ajax = wants_json();
                $oid = (int) ($_POST['operation_id'] ?? 0);
                $parse = static function (string $raw): ?float {
                    $raw = str_replace(
                        [' ', ',', "\xc2\xa0", "\xe2\x80\xaf", '€', '−'],
                        ['', '.', '', '', '', '-'],
                        trim($raw)
                    );
                    if ($raw === '') {
                        return null;
                    }
                    if (!is_numeric($raw)) {
                        return null;
                    }
                    return round((float) $raw, 2);
                };
                $libelle = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['libelle'] ?? '')) ?? '');
                $debitIn = $parse((string) ($_POST['debit'] ?? ''));
                $creditIn = $parse((string) ($_POST['credit'] ?? ''));

                $st = $pdo->prepare(
                    'SELECT * FROM operations_bancaires
                     WHERE id = ? AND (exercice_id = ? OR exercice_id IS NULL)'
                );
                $st->execute([$oid, (int) $exercice['id']]);
                $row = $st->fetch();
                if (!$row) {
                    if ($ajax) {
                        json_out(['ok' => false, 'erreur' => 'Opération introuvable.'], 404);
                    }
                    flash('erreur', 'Opération introuvable.');
                    redirect('banque');
                }
                if ($libelle === '') {
                    if ($ajax) {
                        json_out(['ok' => false, 'erreur' => 'Libellé obligatoire.'], 400);
                    }
                    flash('erreur', 'Libellé obligatoire.');
                    redirect('banque');
                }

                // Une seule colonne : débit (négatif en base) ou crédit (positif)
                $debit = null;
                $credit = null;
                $hasD = $debitIn !== null && abs($debitIn) >= 0.005;
                $hasC = $creditIn !== null && abs($creditIn) >= 0.005;
                if ($hasD && $hasC) {
                    // Priorité à la colonne explicitement demandée, sinon débit
                    $side = (string) ($_POST['side'] ?? 'debit');
                    if ($side === 'credit') {
                        $credit = abs($creditIn);
                    } else {
                        $debit = -abs($debitIn);
                    }
                } elseif ($hasD) {
                    $debit = -abs($debitIn);
                } elseif ($hasC) {
                    $credit = abs($creditIn);
                } else {
                    if ($ajax) {
                        json_out(['ok' => false, 'erreur' => 'Indiquez un débit ou un crédit.'], 400);
                    }
                    flash('erreur', 'Indiquez un débit ou un crédit.');
                    redirect('banque');
                }

                $dateOp = (string) $row['date_operation'];
                $emp = empreinte_operation($dateOp, $libelle, $debit, $credit);
                $chk = $pdo->prepare('SELECT id FROM operations_bancaires WHERE empreinte = ? AND id <> ?');
                $chk->execute([$emp, $oid]);
                if ($chk->fetch()) {
                    // Collision rare : distinguer par id
                    $emp = hash('sha256', $emp . '|id|' . $oid);
                }

                $pdo->prepare(
                    'UPDATE operations_bancaires
                     SET libelle = ?, debit = ?, credit = ?, empreinte = ?
                     WHERE id = ? AND (exercice_id = ? OR exercice_id IS NULL)'
                )->execute([$libelle, $debit, $credit, $emp, $oid, (int) $exercice['id']]);

                if ($ajax) {
                    json_out([
                        'ok' => true,
                        'operation_id' => $oid,
                        'libelle' => $libelle,
                        'debit' => $debit,
                        'credit' => $credit,
                    ]);
                }
                redirect('banque');
            }
        }

        if ($exercice) {
            // Complète les TRI encore NULL (ex. anciens imports) via l’historique
            (new BanqueTriSuggester($pdo))->appliquerSurVides((int) $exercice['id']);
            $st = $pdo->prepare(
                'SELECT * FROM operations_bancaires
                 WHERE exercice_id = ?
                 ORDER BY date_operation DESC, id DESC'
            );
            $st->execute([(int) $exercice['id']]);
            $operations = $st->fetchAll();
            $st = $pdo->prepare(
                "SELECT COUNT(*) AS nb,
                        COALESCE(SUM(ABS(COALESCE(debit, 0))), 0) AS debits,
                        COALESCE(SUM(COALESCE(credit, 0)), 0) AS credits,
                        COALESCE(SUM(CASE WHEN categorie_code = 'VENTE' THEN credit ELSE 0 END), 0) AS ventes
                 FROM operations_bancaires WHERE exercice_id = ?"
            );
            $st->execute([(int) $exercice['id']]);
            $banqueStats = $st->fetch() ?: $banqueStats;
        } else {
            $operations = $pdo->query(
                'SELECT * FROM operations_bancaires ORDER BY date_operation DESC, id DESC LIMIT 500'
            )->fetchAll();
        }
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
                if (!in_array($ext, ['xlsx', 'pdf'], true)) {
                    flash('erreur', 'Formats acceptés : Excel CIC (.xlsx) ou relevé PDF (.pdf).');
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
                    $parsed = (new ReleveImporter())->parse($stored);
                } catch (Throwable $e) {
                    flash('erreur', 'Lecture impossible : ' . $e->getMessage());
                    redirect('import');
                }

                $pdo->beginTransaction();
                $controle = [
                    'solde_initial' => $parsed['solde_initial'] ?? null,
                    'solde_final' => $parsed['solde_final'] ?? null,
                    'solde_initial_deduit' => !empty($parsed['solde_initial_deduit']),
                    'ecart_solde' => $parsed['ecart_solde'] ?? null,
                    'sum_debit' => $parsed['sum_debit'] ?? null,
                    'sum_credit' => $parsed['sum_credit'] ?? null,
                    'format' => $parsed['format'] ?? $ext,
                ];
                $pdo->prepare(
                    'INSERT INTO imports
                    (fichier_nom, format, statut, lignes_lues, solde_initial, solde_final, ecart_solde, controle_json)
                    VALUES (?,?,?,?,?,?,?,?)'
                )->execute([
                    $name,
                    (string) ($parsed['format'] ?? ('cic_' . $ext)),
                    'brouillon',
                    count($parsed['lignes']),
                    $parsed['solde_initial'] ?? null,
                    $parsed['solde_final'] ?? null,
                    $parsed['ecart_solde'] ?? null,
                    json_encode($controle, JSON_UNESCAPED_UNICODE),
                ]);
                $import_id = (int) $pdo->lastInsertId();

                $ins = $pdo->prepare(
                    'INSERT INTO imports_lignes
                    (import_id, ligne_no, date_operation, date_valeur, libelle, debit, credit, statut, motif, empreinte, raw_json)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                );
                $chk = $pdo->prepare('SELECT 1 FROM operations_bancaires WHERE empreinte = ? LIMIT 1');

                $dates = [];
                $empreintesFichier = [];
                foreach ($parsed['lignes'] as &$ligne) {
                    if ($ligne['statut'] === 'ok' && !empty($ligne['empreinte'])) {
                        $isDup = false;
                        $chk->execute([$ligne['empreinte']]);
                        if ($chk->fetch()) {
                            $isDup = true;
                        } elseif (!empty($ligne['empreinte_legacy'])) {
                            $chk->execute([$ligne['empreinte_legacy']]);
                            if ($chk->fetch()) {
                                $isDup = true;
                                // Réutiliser l’empreinte déjà en base pour éviter un second insert
                                $ligne['empreinte'] = $ligne['empreinte_legacy'];
                            }
                        }
                        if ($isDup) {
                            $ligne['statut'] = 'doublon';
                            $ligne['motif'] = 'Déjà présent en base (même date, libellé, montants)';
                        }
                    }
                    if (!empty($ligne['date_operation'])) {
                        $dates[] = $ligne['date_operation'];
                    }
                    if (!empty($ligne['empreinte'])) {
                        $empreintesFichier[] = $ligne['empreinte'];
                    }
                    if (!empty($ligne['empreinte_legacy'])) {
                        $empreintesFichier[] = $ligne['empreinte_legacy'];
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

                // Oublis : opérations déjà en base sur la période, absentes de ce relevé
                $oublis = 0;
                if ($dates !== []) {
                    $minD = min($dates);
                    $maxD = max($dates);
                    $controle['periode'] = [$minD, $maxD];
                    $placeholders = [];
                    $params = [$minD, $maxD];
                    foreach (array_values(array_unique($empreintesFichier)) as $i => $e) {
                        $placeholders[] = '?';
                        $params[] = $e;
                    }
                    if ($placeholders) {
                        $sql = 'SELECT COUNT(*) FROM operations_bancaires
                                WHERE date_operation >= ? AND date_operation <= ?
                                  AND empreinte NOT IN (' . implode(',', $placeholders) . ')';
                        $stO = $pdo->prepare($sql);
                        $stO->execute($params);
                        $oublis = (int) $stO->fetchColumn();
                    } else {
                        $stO = $pdo->prepare(
                            'SELECT COUNT(*) FROM operations_bancaires
                             WHERE date_operation >= ? AND date_operation <= ?'
                        );
                        $stO->execute([$minD, $maxD]);
                        $oublis = (int) $stO->fetchColumn();
                    }
                    $controle['oublis_base'] = $oublis;
                    $pdo->prepare('UPDATE imports SET controle_json = ? WHERE id = ?')->execute([
                        json_encode($controle, JSON_UNESCAPED_UNICODE),
                        $import_id,
                    ]);
                }

                $pdo->commit();
                $msg = 'Prévisualisation prête. Rien n’est encore comptabilisé.';
                if ($parsed['ecart_solde'] !== null && abs((float) $parsed['ecart_solde']) >= 0.02) {
                    $msg .= ' Attention : écart de solde ' . number_format((float) $parsed['ecart_solde'], 2, ',', ' ') . ' €.';
                }
                if ($oublis > 0) {
                    $msg .= ' ' . $oublis . ' opération(s) en base absente(s) de ce relevé sur la période.';
                }
                flash('info', $msg);
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
                    (exercice_id, date_operation, date_valeur, libelle, debit, credit, categorie_code, annee_mois, source, import_id, empreinte)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                );
                $triSuggester = new BanqueTriSuggester($pdo);
                $accepted = 0;
                $preRemplis = 0;
                foreach ($lignes as $l) {
                    $mois = $l['date_operation'] ? annee_mois_from_date($l['date_operation']) : null;
                    $tri = $triSuggester->suggest((string) ($l['libelle'] ?? ''));
                    $ins->execute([
                        $eid,
                        $l['date_operation'],
                        $l['date_valeur'],
                        $l['libelle'],
                        $l['debit'],
                        $l['credit'],
                        $tri,
                        $mois,
                        'import_cic',
                        $import_id,
                        $l['empreinte'],
                    ]);
                    if ($ins->rowCount() > 0) {
                        $accepted++;
                        if ($tri !== null) {
                            $preRemplis++;
                        }
                    }
                }
                $pdo->prepare(
                    'UPDATE imports SET statut = ?, lignes_acceptees = ?, valide_at = datetime(\'now\') WHERE id = ?'
                )->execute(['valide', $accepted, $import_id]);
                $pdo->commit();
                $msg = $accepted . ' opération(s) enregistrée(s).';
                if ($preRemplis > 0) {
                    $msg .= ' TRI pré-rempli sur ' . $preRemplis . ' (récurrents).';
                }
                $msg .= ' Doublons / incertains non comptabilisés.';
                flash('ok', $msg);
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
                $ctrl = [];
                if (!empty($imp['controle_json'])) {
                    $decoded = json_decode((string) $imp['controle_json'], true);
                    if (is_array($decoded)) {
                        $ctrl = $decoded;
                    }
                }
                $preview = [
                    'compte' => $imp['fichier_nom'],
                    'solde_initial' => $imp['solde_initial'] ?? ($ctrl['solde_initial'] ?? null),
                    'solde_final' => $imp['solde_final'] ?? ($ctrl['solde_final'] ?? null),
                    'solde_initial_deduit' => !empty($ctrl['solde_initial_deduit']),
                    'ecart_solde' => $imp['ecart_solde'] ?? ($ctrl['ecart_solde'] ?? null),
                    'sum_debit' => $ctrl['sum_debit'] ?? null,
                    'sum_credit' => $ctrl['sum_credit'] ?? null,
                    'oublis_base' => (int) ($ctrl['oublis_base'] ?? 0),
                    'periode' => $ctrl['periode'] ?? null,
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

            if ($action === 'creer_affaire') {
                try {
                    $pdo->prepare(
                        'INSERT INTO affaires
                         (exercice_id, reference, client, type, montant_contrat_ht, encaisse_n1, fini)
                         VALUES (?,?,?,?,?,?,0)'
                    )->execute([
                        (int) $exercice['id'],
                        'Divers',
                        'Nouveau client',
                        'autre',
                        null,
                        0,
                    ]);
                    $newId = (int) $pdo->lastInsertId();
                } catch (PDOException) {
                    if ($ajax) {
                        json_out(['ok' => false, 'erreur' => 'Création impossible.'], 500);
                    }
                    flash('erreur', 'Création de ligne impossible.');
                    redirect('planning');
                }
                if ($ajax) {
                    json_out(['ok' => true, 'affaire_id' => $newId]);
                }
                redirect('planning', [], 'row-' . $newId);
            }

            if ($action === 'valider_ecarts') {
                try {
                    $n = (new PlanningService($pdo))->validerRecoupementsRestants((int) $exercice['id']);
                } catch (Throwable) {
                    if ($ajax) {
                        json_out(['ok' => false, 'erreur' => 'Validation impossible.'], 500);
                    }
                    flash('erreur', 'Validation impossible.');
                    redirect('planning');
                }
                if ($ajax) {
                    json_out(['ok' => true, 'valides' => $n]);
                }
                flash('ok', $n > 0
                    ? ($n . ' écart' . ($n > 1 ? 's' : '') . ' validé' . ($n > 1 ? 's' : '') . '.')
                    : 'Aucun écart à valider.');
                redirect('planning');
            }

            if ($action === 'suppr_affaire') {
                $affaireId = (int) ($_POST['affaire_id'] ?? 0);
                $st = $pdo->prepare('SELECT id FROM affaires WHERE id = ? AND exercice_id = ?');
                $st->execute([$affaireId, (int) $exercice['id']]);
                if (!$st->fetch()) {
                    if ($ajax) {
                        json_out(['ok' => false, 'erreur' => 'Affaire introuvable.'], 404);
                    }
                    flash('erreur', 'Affaire introuvable.');
                    redirect('planning');
                }
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE factures SET affaire_id = NULL WHERE affaire_id = ?')->execute([$affaireId]);
                    $pdo->prepare('DELETE FROM echeances_facturation WHERE affaire_id = ?')->execute([$affaireId]);
                    $pdo->prepare('DELETE FROM affaires WHERE id = ? AND exercice_id = ?')
                        ->execute([$affaireId, (int) $exercice['id']]);
                    $pdo->commit();
                } catch (PDOException) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($ajax) {
                        json_out(['ok' => false, 'erreur' => 'Suppression impossible.'], 500);
                    }
                    flash('erreur', 'Suppression impossible.');
                    redirect('planning');
                }
                if ($ajax) {
                    json_out(['ok' => true, 'affaire_id' => $affaireId]);
                }
                redirect('planning');
            }

            if ($action === 'maj_affaire') {
                $affaireId = (int) ($_POST['affaire_id'] ?? 0);
                $reference = trim((string) ($_POST['reference'] ?? ''));
                $client = trim((string) ($_POST['client'] ?? ''));
                $contratRaw = str_replace(
                    [' ', ',', "\xc2\xa0", "\xe2\x80\xaf"],
                    ['', '.', '', ''],
                    trim((string) ($_POST['montant_contrat_ht'] ?? ''))
                );
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
                        'SELECT id, montant_ht, statut, COALESCE(ecart_ok, 0) AS ecart_ok
                         FROM echeances_facturation WHERE affaire_id = ? AND annee_mois = ?'
                    );
                    $insEch = $pdo->prepare(
                        'INSERT INTO echeances_facturation (affaire_id, annee_mois, montant_ht, statut, couleur, ecart_ok)
                         VALUES (?,?,?,?,?,0)'
                    );
                    $updEch = $pdo->prepare(
                        'UPDATE echeances_facturation
                         SET montant_ht = ?, statut = ?, couleur = ?, ecart_ok = ?
                         WHERE id = ?'
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
                        // PHP convertit les clés numériques (ex. 202507) en int
                        $anneeMois = (string) $anneeMois;
                        if (!preg_match('/^\d{6}$/', $anneeMois)) {
                            continue;
                        }
                        $raw = str_replace(
                            [' ', ',', "\xc2\xa0", "\xe2\x80\xaf"],
                            ['', '.', '', ''],
                            trim((string) $raw)
                        );
                        if ($raw === '' || !is_numeric($raw) || (float) $raw == 0.0) {
                            $delEch->execute([$affaireId, $anneeMois]);
                            continue;
                        }
                        $montant = round((float) $raw, 2);
                        $statut = (string) ($statutsPost[$anneeMois] ?? $statutsPost[(int) $anneeMois] ?? 'a_facturer');
                        if (!isset($statutsOk[$statut])) {
                            $statut = 'a_facturer';
                        }
                        $couleur = $statutsOk[$statut];
                        $selEch->execute([$affaireId, $anneeMois]);
                        $exist = $selEch->fetch();
                        if ($exist) {
                            // Validation utilisateur = définitive tant que la case reste « payé ».
                            $wasOk = (int) $exist['ecart_ok'] === 1;
                            $ecartOk = ($statut === 'paye' && $wasOk) ? 1 : 0;
                            $updEch->execute([
                                $montant,
                                $statut,
                                $couleur,
                                $ecartOk,
                                (int) $exist['id'],
                            ]);
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
        $objectifInfo = null;
        if ($exercice) {
            $planning = (new PlanningService($pdo))->grille((int) $exercice['id']);
            if ($exercice['objectif_ca_ht'] !== null && $exercice['objectif_ca_ht'] !== '') {
                $totStat = $planning['totaux_statut'] ?? [];
                $factureEnCours = round((float) ($totStat['paye'] ?? 0), 2);
                $restantAFacturer = round(
                    (float) ($totStat['a_facturer'] ?? 0)
                    + (float) ($totStat['facture'] ?? 0)
                    + (float) ($totStat['litige'] ?? 0),
                    2
                );
                $objectif = round((float) $exercice['objectif_ca_ht'], 2);
                // Écart = CA année (objectif) − facturé en cours (bleu) − restant à facturer (V+R+J)
                $ecart = round($objectif - $factureEnCours - $restantAFacturer, 2);
                $objectifInfo = [
                    'objectif' => $objectif,
                    'facture_en_cours' => $factureEnCours,
                    'restant_a_facturer' => $restantAFacturer,
                    'ecart' => $ecart,
                    'atteint' => $ecart <= 0.005,
                ];
            }
        }
        require dirname(__DIR__) . '/templates/planning.php';
        break;

    case 'analytique':
        $exercice = exercice_courant($pdo);
        $analytique = ['mois' => [], 'lignes' => [], 'totaux_mois' => [], 'total_general' => 0.0];
        if ($exercice) {
            $analytique = (new AnalytiqueService($pdo))->grille((int) $exercice['id']);
        }
        require dirname(__DIR__) . '/templates/analytique.php';
        break;

    case 'previsionnel':
        $exercice = exercice_courant($pdo);
        $previsionnel = ['lignes' => [], 'totaux' => [
            'budget_ht' => 0.0,
            'budget_ttc' => 0.0,
            'reel' => 0.0,
            'ecart' => 0.0,
        ]];

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $exercice) {
            csrf_verify();
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'maj_budget') {
                $ajax = wants_json();
                $code = trim((string) ($_POST['categorie_code'] ?? ''));
                $chk = $pdo->prepare('SELECT code FROM categories WHERE code = ?');
                $chk->execute([$code]);
                if (!$chk->fetch()) {
                    if ($ajax) {
                        json_out(['ok' => false, 'erreur' => 'Code TRI inconnu.'], 400);
                    }
                    flash('erreur', 'Code TRI inconnu.');
                    redirect('previsionnel');
                }
                $parse = static function (string $raw): float {
                    $raw = str_replace(
                        [' ', ',', "\xc2\xa0", "\xe2\x80\xaf", '€', '−'],
                        ['', '.', '', '', '', '-'],
                        trim($raw)
                    );
                    if ($raw === '' || !is_numeric($raw)) {
                        return 0.0;
                    }
                    return round((float) $raw, 2);
                };
                $ht = $parse((string) ($_POST['montant_ht'] ?? '0'));
                $tva = $parse((string) ($_POST['montant_tva'] ?? '0'));
                $ttc = $parse((string) ($_POST['montant_ttc'] ?? '0'));
                // Si TTC vide / 0 mais HT ou TVA saisis → recalcul
                if (abs($ttc) < 0.005 && (abs($ht) > 0.005 || abs($tva) > 0.005)) {
                    $ttc = round($ht + $tva, 2);
                }
                $eid = (int) $exercice['id'];
                $pdo->prepare(
                    'INSERT INTO budgets (exercice_id, categorie_code, montant_ht, montant_tva, montant_ttc)
                     VALUES (?,?,?,?,?)
                     ON CONFLICT(exercice_id, categorie_code) DO UPDATE SET
                       montant_ht = excluded.montant_ht,
                       montant_tva = excluded.montant_tva,
                       montant_ttc = excluded.montant_ttc'
                )->execute([$eid, $code, $ht, $tva, $ttc]);

                if ($ajax) {
                    $grille = (new PrevisionnelService($pdo))->grille($eid);
                    $pdo->prepare('UPDATE exercices SET objectif_ca_ht = ? WHERE id = ?')
                        ->execute([(float) ($grille['objectif_ca_ht'] ?? 0), $eid]);
                    $ligne = null;
                    foreach ($grille['lignes'] as $l) {
                        if ($l['code'] === $code) {
                            $ligne = $l;
                            break;
                        }
                    }
                    json_out([
                        'ok' => true,
                        'categorie_code' => $code,
                        'ligne' => $ligne,
                        'totaux' => $grille['totaux'],
                        'synthese' => $grille['synthese'],
                        'marge_pct' => $grille['marge_pct'] ?? 0,
                        'marge_net' => $grille['marge_net'] ?? 0,
                        'objectif_ca_ht' => $grille['objectif_ca_ht'] ?? null,
                    ]);
                }
                redirect('previsionnel');
            }

            if ($action === 'maj_marge') {
                $ajax = wants_json();
                $raw = str_replace(
                    [' ', ',', "\xc2\xa0", "\xe2\x80\xaf", '%', '€'],
                    ['', '.', '', '', '', ''],
                    trim((string) ($_POST['marge_pct'] ?? '0'))
                );
                $marge = ($raw === '' || !is_numeric($raw)) ? 0.0 : round((float) $raw, 4);
                if ($marge < -100) {
                    $marge = -100.0;
                }
                if ($marge > 1000) {
                    $marge = 1000.0;
                }
                $eid = (int) $exercice['id'];
                $pdo->prepare('UPDATE exercices SET marge_pct = ? WHERE id = ?')->execute([$marge, $eid]);
                $grille = (new PrevisionnelService($pdo))->grille($eid);
                $pdo->prepare('UPDATE exercices SET objectif_ca_ht = ? WHERE id = ?')
                    ->execute([(float) ($grille['objectif_ca_ht'] ?? 0), $eid]);
                if ($ajax) {
                    json_out([
                        'ok' => true,
                        'marge_pct' => $grille['marge_pct'] ?? $marge,
                        'marge_net' => $grille['marge_net'] ?? 0,
                        'objectif_ca_ht' => $grille['objectif_ca_ht'] ?? null,
                        'synthese' => $grille['synthese'],
                        'totaux' => $grille['totaux'],
                    ]);
                }
                redirect('previsionnel');
            }
        }

        if ($exercice) {
            $previsionnel = (new PrevisionnelService($pdo))->grille((int) $exercice['id']);
        }
        require dirname(__DIR__) . '/templates/previsionnel.php';
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
