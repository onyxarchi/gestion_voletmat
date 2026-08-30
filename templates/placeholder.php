<?php
declare(strict_types=1);
/** @var string $page */
/** @var string $title */
/** @var string $message */
ob_start();
?>
<h1><?= e($title) ?></h1>
<div class="panel">
  <p><?= e($message) ?></p>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/templates/layout.php';
