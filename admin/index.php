<?php
// few things we'll need
require '../common.php';

define('ADMIN_PANEL', true);
define('MYAAC_ADMIN', true);

if (file_exists(BASE . 'config.local.php')) {
  require_once BASE . 'config.local.php';
}

if (file_exists(BASE . 'install') && (!isset($config['installed']) || !$config['installed'])) {
  header('Location: ' . BASE_URL . 'install/');
  throw new RuntimeException(
    'Setup detected that <b>install/</b> directory exists. Please visit <a href="' .
      BASE_URL .
      'install">this</a> url to start MyAAC Installation.<br/>Delete <b>install/</b> directory if you already installed MyAAC.<br/>Remember to REFRESH this page when you\'re done!'
  );
}

$content = '';

// validate page
$page = $_GET['p'] ?? '';
if (empty($page) || preg_match('/[^a-zA-Z0-9_\-]/', $page)) {
  $page = 'dashboard';
}

$page = strtolower($page);
define('PAGE', $page);

require SYSTEM . 'functions.php';
require SYSTEM . 'init.php';

if (config('env') === 'dev') {
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(E_ALL);
}

// event system
require_once SYSTEM . 'hooks.php';
$hooks = new Hooks();
$hooks->load();

require SYSTEM . 'status.php';
require SYSTEM . 'login.php';
require SYSTEM . 'migrate.php';
require ADMIN . 'includes/functions.php';

$twig->addGlobal('config', $config);
$twig->addGlobal('status', $status);

// if we're not logged in - show login box
if (!$logged || !admin()) {
  $page = 'login';
}

// include our page
$file = ADMIN . 'pages/' . $page . '.php';
if (!@file_exists($file)) {
  $page = '404';
  $file = SYSTEM . 'pages/404.php';
}

ob_start();
include $file;

$content .= ob_get_contents();
ob_end_clean();

// Alerta de fallback de download (últimas 24h)
if ($logged && admin()) {
  $fallbackAlertHtml = '';
  $logFile = SYSTEM . 'data/launcher_fallback.jsonl';
  if (@file_exists($logFile)) {
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines) && !empty($lines)) {
      $now = time();
      $recent = 0;
      $latest = [];
      // varre do fim para o começo (eventos mais novos primeiro), limita 50
      foreach (array_slice(array_reverse($lines), 0, 50) as $line) {
        $ev = json_decode($line, true);
        if (!is_array($ev)) continue;
        $ts = isset($ev['ts']) ? strtotime($ev['ts']) : 0;
        if ($ts > ($now - 86400)) {
          $recent++;
        }
        if (count($latest) < 5) {
          $latest[] = $ev;
        }
      }

      if ($recent > 0) {
        // Monta HTML do alerta
        $items = '';
        foreach ($latest as $ev) {
          $v = htmlspecialchars($ev['version'] ?? '', ENT_QUOTES, 'UTF-8');
          $ts = htmlspecialchars($ev['ts'] ?? '', ENT_QUOTES, 'UTF-8');
          $reason = htmlspecialchars($ev['reason'] ?? '', ENT_QUOTES, 'UTF-8');
          $items .= '<li><strong>v' . $v . '</strong> — ' . $ts . (!empty($reason) ? ' — ' . $reason : '') . '</li>';
        }

        $fallbackAlertHtml = '<div class="alert alert-warning" role="alert" style="margin-bottom: 16px;">'
          . '<strong>Downloads por fallback nas últimas 24h:</strong> '
          . $recent
          . '<ul style="margin-top:8px;">'
          . $items
          . '</ul>'
          . '</div>';
      }
    }
  }

  if (!empty($fallbackAlertHtml)) {
    $content = $fallbackAlertHtml . $content;
  }
}

// template
$template_path = 'template/';
require ADMIN . $template_path . 'template.php';
?>

<?php if ($config['pace_load']) { ?>
    <script src="../admin/bootstrap/pace/pace.js"></script>
    <link href="../admin/bootstrap/pace/themes/white/pace-theme-flash.css" rel="stylesheet"/>
<?php } ?>
