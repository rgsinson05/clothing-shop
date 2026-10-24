<?php
// CLI-only UI regression checks. No database connection, session, or data writes.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI-only.\n");
}

$root = dirname(__DIR__, 2);
$checks = 0;
$failures = 0;
function themeCheck(string $label, bool $pass): void
{
    global $checks, $failures;
    $checks++;
    $failures += $pass ? 0 : 1;
    echo ($pass ? '  ok: ' : 'FAIL: ') . $label . "\n";
}

function themeHead(string $section): string
{
    $ui_section = $section;
    $page_title = 'Theme test';
    ob_start();
    require __DIR__ . '/../../includes/ui.head.php';
    return ob_get_clean();
}

$_SESSION = [];
$adminHead = themeHead('admin');
themeCheck('Admin defaults to dark without JavaScript', str_contains($adminHead, 'data-admin-theme="dark"'));
themeCheck('Theme bootstrap precedes all CSS', strpos($adminHead, 'js/admin-theme.js') < strpos($adminHead, 'css/styles.css'));
themeCheck('Theme bootstrap is parser-blocking', preg_match('/<script src="[^"]*admin-theme\.js"><\/script>/', $adminHead) === 1);
themeCheck('Theme CSS follows shared and notification CSS', strpos($adminHead, 'css/admin-theme.css') > strpos($adminHead, 'css/admin-notifications.css'));
foreach (['store', ''] as $section) {
    $head = themeHead($section);
    themeCheck('Store/default head does not opt in to admin theme: ' . $section, !str_contains($head, 'admin-theme'));
    themeCheck('Store/default browser color is unchanged: ' . $section, str_contains($head, 'content="#f5f4f1"'));
}

// Only the unread-count query is stubbed; the actual header and bell render.
class ThemeCountStatement extends PDOStatement
{
    public function fetchColumn(int $column = 0): mixed { return 12; }
}
class ThemeCountPDO extends PDO
{
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($query !== 'SELECT COUNT(*) FROM admin_order_notifications WHERE read_at IS NULL') {
            throw new RuntimeException('Unexpected query in UI-only test');
        }
        return new ThemeCountStatement();
    }
}
$pdo = new ThemeCountPDO();
$_SESSION = ['admin_id' => 7, 'admin_name' => 'Theme Test'];
$_SERVER['SCRIPT_NAME'] = '/admin/index.php';
$ui_section = 'admin';
ob_start();
require $root . '/includes/ui.header.php';
$header = ob_get_clean();
themeCheck('Exactly one shared theme toggle', substr_count($header, 'data-admin-theme-toggle') === 1);
themeCheck('Toggle is non-submitting and accessible', preg_match('/<button[^>]*type="button"[^>]*data-admin-theme-toggle[^>]*aria-label="Dark mode"[^>]*aria-pressed="true"[^>]*hidden>/', $header) === 1);
themeCheck('Toggle and existing bell share the utility group', preg_match('/admin-topbar__utilities[\s\S]*data-admin-theme-toggle[\s\S]*admin-notifications__bell/', $header) === 1);
themeCheck('Existing unread badge remains intact', str_contains($header, 'aria-label="Notifications, 12 unread"') && str_contains($header, '>9+</span>'));
themeCheck('Existing notification endpoint and dropdown remain intact', str_contains($header, 'data-endpoint="api/notifications.php"') && str_contains($header, 'id="admin-notification-panel"'));
themeCheck('Existing active navigation remains intact', str_contains($header, 'href="index.php" aria-current="page"'));

$renderedPages = ['index', 'products', 'product-create', 'product-edit', 'bundle-create', 'bundles', 'legacy-assign', 'labels', 'scan', 'sales', 'orders', 'order-view', 'notifications', 'pickups', 'order-verify', 'pickup-verify'];
foreach ($renderedPages as $page) {
    $source = file_get_contents($root . '/admin/' . $page . '.php');
    themeCheck($page . ' opts into shared admin head/header', preg_match('/\$ui_section\s*=\s*\'admin\'/', $source) === 1 && str_contains($source, '/ui.head.php') && str_contains($source, '/ui.header.php'));
}
$login = file_get_contents($root . '/admin/login.php');
themeCheck('Standalone login uses the same theme assets and toggle', str_contains($login, 'data-admin-theme="dark"') && str_contains($login, 'js/admin-theme.js') && str_contains($login, 'css/admin-theme.css') && str_contains($login, '/ui.admin-theme-toggle.php'));

$css = file_get_contents($root . '/css/admin-theme.css');
themeCheck('Dark tokens do not apply to print media', preg_match('/@media screen\s*\{\s*\[data-admin-theme="dark"\]\s*\{/', $css) === 1);
themeCheck('No blanket important overrides or image filters', !str_contains($css, '!important') && preg_match('/(?:^|[;{\s])filter\s*:/', $css) === 0);
themeCheck('Receipt and barcode light islands are explicit', str_contains($css, ':is(.receipt, .label-card, .leg-barcode)'));

preg_match_all('/--(admin-[a-z-]+):\s*(#[0-9a-f]{3,6})\s*;/i', $css, $matches, PREG_SET_ORDER);
$palette = [];
foreach ($matches as $match) $palette[$match[1]] = $match[2];
function themeLuminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    $rgb = array_map(function ($channel) {
        $value = hexdec($channel) / 255;
        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }, str_split($hex, 2));
    return $rgb[0] * 0.2126 + $rgb[1] * 0.7152 + $rgb[2] * 0.0722;
}
function themeContrast(string $a, string $b): float
{
    $a = themeLuminance($a);
    $b = themeLuminance($b);
    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}
foreach (['text', 'text-soft', 'text-muted'] as $text) {
    foreach (['bg', 'surface', 'surface-alt', 'input'] as $surface) {
        $ratio = themeContrast($palette['admin-' . $text], $palette['admin-' . $surface]);
        themeCheck("{$text} / {$surface} contrast >= 4.5 (" . round($ratio, 2) . ')', $ratio >= 4.5);
    }
}
foreach (['success', 'warning', 'danger', 'info', 'purple'] as $tone) {
    $ratio = themeContrast($palette['admin-' . $tone], $palette['admin-' . $tone . '-bg']);
    themeCheck("{$tone} badge contrast >= 4.5 (" . round($ratio, 2) . ')', $ratio >= 4.5);
}
foreach (['action', 'action-hover', 'danger-action'] as $tone) {
    $ratio = themeContrast('#fff', $palette['admin-' . $tone]);
    themeCheck("{$tone} button contrast >= 4.5 (" . round($ratio, 2) . ')', $ratio >= 4.5);
}

echo "\n{$checks} checks, {$failures} failures. No database connected.\n";
exit($failures === 0 ? 0 : 1);
