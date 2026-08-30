<?php
declare(strict_types=1);
/** @var string $title */
/** @var string $content */
/** @var string $page */
$user = \Voletmat\Auth::user();
$flashes = take_flashes();
$nav = [
    'accueil' => 'CA',
    'planning' => 'Planning facturation',
    'factures' => 'Facturation',
    'previsionnel' => 'Prévisionnel',
    'banque' => 'Banque',
    'analytique' => 'Analytique',
    'exercices' => 'Exercices',
    'export' => 'Export',
];
$exerciceHeader = null;
$exerciceHeaderLabel = '';
if ($user) {
    try {
        $exerciceHeader = exercice_courant(\Voletmat\Database::pdo());
    } catch (\Throwable) {
        $exerciceHeader = null;
    }
    if ($exerciceHeader) {
        $moisFr = [
            1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
            5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
            9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
        ];
        $d0 = date_create((string) ($exerciceHeader['date_debut'] ?? ''));
        $d1 = date_create((string) ($exerciceHeader['date_fin'] ?? ''));
        $code = (string) ($exerciceHeader['code'] ?? '');
        if ($d0 && $d1 && $code !== '') {
            $a = ($moisFr[(int) $d0->format('n')] ?? '') . ' ' . $d0->format('Y');
            $b = ($moisFr[(int) $d1->format('n')] ?? '') . ' ' . $d1->format('Y');
            $b = mb_strtoupper(mb_substr($b, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($b, 1, null, 'UTF-8');
            $exerciceHeaderLabel = 'Exercice ' . $code . ' : ' . $a . ' - ' . $b;
        }
    }
}
$bodyClass = match ($page ?? '') {
    'login' => 'page-login',
    'planning' => 'page-planning',
    'analytique' => 'page-analytique',
    default => '',
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> — <?= e((string) app_config('app_name')) ?></title>
  <link rel="icon" href="assets/img/logo-voletmat.png" type="image/png">
  <?php
    $cssPath = dirname(__DIR__) . '/public/assets/css/app.css';
    $cssVer = is_file($cssPath) ? (string) filemtime($cssPath) : '1';
  ?>
  <link rel="stylesheet" href="assets/css/app.css?v=<?= e($cssVer) ?>">
</head>
<body class="<?= e($bodyClass) ?>">
<?php if ($user): ?>
<header class="app-header">
  <a class="brand" href="<?= e(page_url('accueil')) ?>">
    <img class="brand-logo" src="assets/img/logo-voletmat.png" alt="Vol&amp;Mat Architecture">
    <span class="brand-text">
      <span class="name">Vol&amp;Mat</span>
      <span class="tag">Gestion</span>
    </span>
  </a>
  <nav class="nav">
    <?php foreach ($nav as $key => $label): ?>
      <a href="<?= e(page_url($key)) ?>" class="<?= $page === $key ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <?php if ($exerciceHeaderLabel !== ''): ?>
      <span class="nav-exercice" title="<?= e($exerciceHeaderLabel) ?>"><?= e($exerciceHeaderLabel) ?></span>
    <?php endif; ?>
  </nav>
  <div class="user-meta">
    <?= e($user['nom'] ?: $user['login']) ?>
    · <a href="<?= e(page_url('logout')) ?>">Déconnexion</a>
  </div>
</header>
<?php endif; ?>

<main class="wrap">
  <?php foreach ($flashes as $f): ?>
    <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>

<?php if (!empty($pageScripts) && is_array($pageScripts)): ?>
  <?php foreach ($pageScripts as $src): ?>
    <script src="<?= e((string) $src) ?>" defer></script>
  <?php endforeach; ?>
<?php endif; ?>
<?php if (!empty($pageInlineScript) && is_string($pageInlineScript)): ?>
  <script><?= $pageInlineScript ?></script>
<?php endif; ?>
</body>
</html>
