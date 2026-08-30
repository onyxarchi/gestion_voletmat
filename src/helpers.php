<?php
declare(strict_types=1);

/** @var array<string,mixed>|null */
$GLOBALS['__voletmat_config'] = null;

function app_config(?string $key = null): mixed
{
    if ($GLOBALS['__voletmat_config'] === null) {
        $base = require dirname(__DIR__) . '/config/config.php';
        $localFile = dirname(__DIR__) . '/config/config.local.php';
        $local = is_file($localFile) ? require $localFile : [];
        $GLOBALS['__voletmat_config'] = array_replace_recursive($base, is_array($local) ? $local : []);
    }
    if ($key === null) {
        return $GLOBALS['__voletmat_config'];
    }
    $parts = explode('.', $key);
    $v = $GLOBALS['__voletmat_config'];
    foreach ($parts as $p) {
        if (!is_array($v) || !array_key_exists($p, $v)) {
            return null;
        }
        $v = $v[$p];
    }
    return $v;
}

function e(string|int|float|null $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $page, array $query = [], string $fragment = ''): never
{
    $q = $query ? ('&' . http_build_query($query)) : '';
    $hash = $fragment !== '' ? ('#' . rawurlencode(ltrim($fragment, '#'))) : '';
    header('Location: index.php?page=' . rawurlencode($page) . $q . $hash);
    exit;
}

function flash(string $type, string $message): void
{
    \Voletmat\Auth::startSession();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    \Voletmat\Auth::startSession();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : [];
}

/** Format monétaire FR : 1 234,56 € */
function euro(?float $amount): string
{
    if ($amount === null) {
        return '—';
    }
    return number_format($amount, 2, ',', ' ') . ' €';
}

function wants_json(): bool
{
    if (!empty($_POST['ajax']) || !empty($_GET['ajax'])) {
        return true;
    }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return str_contains($accept, 'application/json');
}

/** @param array<string,mixed> $data */
function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Date ISO → JJ/MM/AAAA */
function date_fr(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return '—';
    }
    $d = date_create($iso);
    return $d ? $d->format('d/m/Y') : e($iso);
}

function csrf_token(): string
{
    \Voletmat\Auth::startSession();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    \Voletmat\Auth::startSession();
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        if (wants_json()) {
            json_out(['ok' => false, 'erreur' => 'Jeton de sécurité invalide.'], 400);
        }
        http_response_code(400);
        exit('Jeton de sécurité invalide.');
    }
}

function page_url(string $page, array $query = []): string
{
    $query = array_merge(['page' => $page], $query);
    return 'index.php?' . http_build_query($query);
}

/** Empreinte doublon : date + libellé + débit + crédit (montants tels quels). */
function empreinte_operation(string $date, string $libelle, ?float $debit, ?float $credit): string
{
    $d = $debit === null ? '' : number_format($debit, 2, '.', '');
    $c = $credit === null ? '' : number_format($credit, 2, '.', '');
    return hash('sha256', $date . '|' . trim($libelle) . '|' . $d . '|' . $c);
}

function annee_mois_from_date(string $iso): string
{
    $d = date_create($iso);
    return $d ? $d->format('Ym') : '';
}

/**
 * Exercice en vigueur à une date (par défaut : aujourd’hui).
 * date_debut ≤ jour ≤ date_fin. Met à jour le flag actif en base.
 *
 * @return array<string,mixed>|null
 */
function exercice_courant(\PDO $pdo, ?string $jour = null): ?array
{
    $jour = $jour ?: date('Y-m-d');
    $st = $pdo->prepare(
        'SELECT * FROM exercices
         WHERE date_debut <= ? AND date_fin >= ?
         ORDER BY date_debut DESC
         LIMIT 1'
    );
    $st->execute([$jour, $jour]);
    $ex = $st->fetch() ?: null;

    if (!$ex) {
        // Hors plage : dernier exercice déjà commencé, sinon le prochain
        $st = $pdo->prepare(
            'SELECT * FROM exercices WHERE date_debut <= ? ORDER BY date_debut DESC LIMIT 1'
        );
        $st->execute([$jour]);
        $ex = $st->fetch() ?: null;
        if (!$ex) {
            $ex = $pdo->query('SELECT * FROM exercices ORDER BY date_debut ASC LIMIT 1')->fetch() ?: null;
        }
    }

    if ($ex) {
        try {
            $pdo->exec('UPDATE exercices SET actif = 0');
            $pdo->prepare('UPDATE exercices SET actif = 1 WHERE id = ?')->execute([(int) $ex['id']]);
            $ex['actif'] = 1;
        } catch (\Throwable) {
            // lecture seule éventuelle : on ignore
        }
    }

    return $ex ?: null;
}
