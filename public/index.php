<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/SyncController.php';
require dirname(__DIR__) . '/src/RenphoController.php';
require dirname(__DIR__) . '/src/WebAuth.php';
require dirname(__DIR__) . '/src/AnalyticsController.php';
require dirname(__DIR__) . '/src/GoogleHealthController.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// The dashboard contains personal health and location data. Keep every dynamic
// response out of search indexes without preventing direct tools from reading it.
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');

function dashboardEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dashboardActivityName(int $type): string
{
    return match ($type) {
        4, 5 => 'Vélo',
        21 => 'Randonnée',
        33, 34 => 'Course',
        48, 49 => 'Natation',
        53 => 'Marche',
        default => 'Entraînement',
    };
}

function renderDashboardSnapshot(array $summary): string
{
    $totals = $summary['totals'];
    $distance = number_format(((float) $totals['distance_m']) / 1000, 1, ',', ' ');
    $hours = number_format(((float) $totals['duration_s']) / 3600, 1, ',', ' ');
    $ascent = number_format((float) $totals['ascent_m'], 0, ',', ' ');
    $recent = '';
    foreach ($summary['recent'] as $activity) {
        $name = dashboardActivityName((int) $activity['exercise_type']);
        $title = $activity['title'] ?: $name;
        $date = (new DateTimeImmutable($activity['start_at']))->format('d/m/Y H:i');
        $activityDistance = number_format(((float) $activity['distance_m']) / 1000, 2, ',', ' ');
        $recent .= '<article class="activity-row"><span class="activity-type">⌖</span><div class="activity-main"><strong>'
            . dashboardEscape($title) . '</strong><small>' . dashboardEscape($name) . ' · ' . $date
            . ' · trace GPS</small></div><div class="metric distance"><small>Distance</small><strong>'
            . $activityDistance . ' km</strong></div></article>';
    }
    $json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<section class="hero"><div class="hero-copy"><p class="eyebrow">TABLEAU DE BORD HEALTH CONNECT</p>'
        . '<h1>Pizid Motion.<br><span>' . (int) $totals['activities'] . ' sorties GPS.</span></h1>'
        . '<p>Synthèse serveur des ' . (int) $summary['period_days'] . ' derniers jours. L’interface interactive complète se charge dans les navigateurs compatibles.</p></div></section>'
        . '<section class="stats-grid">'
        . '<article class="stat-card"><div class="stat-top">Distance</div><strong>' . $distance . ' <small>km</small></strong></article>'
        . '<article class="stat-card"><div class="stat-top">Temps actif</div><strong>' . $hours . ' <small>h</small></strong></article>'
        . '<article class="stat-card"><div class="stat-top">Dénivelé</div><strong>' . $ascent . ' <small>m</small></strong></article>'
        . '<article class="stat-card"><div class="stat-top">Sorties GPS</div><strong>' . (int) $totals['activities'] . '</strong></article></section>'
        . '<div class="section-head"><div><h3>Dernières sorties GPS</h3><p class="muted">Données Health Connect rendues directement par le serveur</p></div></div>'
        . '<div class="activity-list">' . $recent . '</div>'
        . '<p class="muted machine-readable">Données JSON : <a href="/api/v1/dashboard/summary?days=90">API de synthèse Pizid Motion</a></p>'
        . '<script id="initial-summary" type="application/json">' . $json . '</script>';
}

try {
    if (in_array($method, ['GET', 'HEAD'], true) && $path === '/robots.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo "User-agent: *\nAllow: /\n";
        exit;
    }

    if (in_array($method, ['GET', 'HEAD'], true) && $path === '/') {
        header('Location: /dashboard', true, 302);
        exit;
    }

    if (in_array($method, ['GET', 'HEAD'], true) && in_array($path, ['/dashboard', '/dashboard/'], true)) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; img-src 'self' data: https://server.arcgisonline.com; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        $document = file_get_contents(__DIR__ . '/dashboard.html');
        if ($method === 'GET' && !WebAuth::enabled()) {
            $document = preg_replace('/<!-- AUTH_FORM_START -->.*?<!-- AUTH_FORM_END -->/s', '', $document);
            $document = str_replace('class="app-shell hidden"', 'class="app-shell"', $document);
            $loading = '<div id="content" class="content"><div class="loading-card"><span class="loader"></span>Analyse de tes données…</div></div>';
            $snapshot = '<div id="content" class="content">' . renderDashboardSnapshot((new AnalyticsController(db()))->summaryData(90)) . '</div>';
            $document = str_replace($loading, $snapshot, $document);
        }
        echo $document;
        exit;
    }

    if ($method === 'GET' && $path === '/api/v1/health') {
        jsonResponse([
            'service' => 'Health Connect',
            'status' => 'ready',
            'api_version' => 'v1',
            'php' => PHP_VERSION,
        ]);
    }

    if ($method === 'GET' && in_array($path, ['/api/v1', '/api/v1/'], true)) {
        jsonResponse([
            'service' => 'Pizid Motion API',
            'api_version' => 'v1',
            'documentation' => '/api/v1/openapi.yaml',
            'health_data' => '/api/v1/dashboard/health?days=90',
            'health_metrics' => '/api/v1/dashboard/health/catalog',
            'activities' => '/api/v1/dashboard/activities?limit=200',
        ]);
    }

    if ($method === 'GET' && $path === '/api/v1/openapi.yaml') {
        header('Content-Type: application/yaml; charset=utf-8');
        header('Cache-Control: no-store');
        echo file_get_contents(dirname(__DIR__) . '/docs/openapi.yaml');
        exit;
    }

    if ($method === 'GET' && $path === '/api/v1/web/session') WebAuth::status();
    if ($method === 'POST' && $path === '/api/v1/web/login') WebAuth::login();
    if ($method === 'POST' && $path === '/api/v1/web/logout') WebAuth::logout();

    if ($method === 'GET' && $path === '/api/v1/google-health/callback') {
        (new GoogleHealthController(db()))->callback();
    }

    if ($method === 'GET' && str_starts_with($path, '/api/v1/dashboard/')) {
        WebAuth::requireLogin();
        $analytics = new AnalyticsController(db());
        if ($path === '/api/v1/dashboard/summary') $analytics->summary();
        if ($path === '/api/v1/dashboard/health') $analytics->health();
        if ($path === '/api/v1/dashboard/health/catalog') $analytics->healthCatalog();
        if (preg_match('#^/api/v1/dashboard/health/([a-z][a-z0-9_]{0,63})$#', $path, $matches)) $analytics->healthIndicator($matches[1]);
        if ($path === '/api/v1/dashboard/activities') $analytics->activities();
        if ($path === '/api/v1/dashboard/compare') $analytics->compare();
        if (preg_match('#^/api/v1/dashboard/activities/(\d+)/cadence$#', $path, $matches)) $analytics->activityCadence($matches[1]);
        if (preg_match('#^/api/v1/dashboard/activities/(\d+)$#', $path, $matches)) $analytics->activity($matches[1]);
        throw new ApiException(404, 'Endpoint not found');
    }

    $pdo = db();
    $device = authenticatedDevice($pdo);
    $controller = new SyncController($pdo);

    if ($method === 'GET' && $path === '/api/v1/sync/status') {
        $controller->status($device);
    }
    if ($method === 'POST' && $path === '/api/v1/sync/batches') {
        $controller->ingest($device);
    }
    if ($method === 'POST' && $path === '/api/v1/sync/renpho') {
        (new RenphoController($pdo))->ingest($device);
    }

    throw new ApiException(404, 'Endpoint not found');
} catch (ApiException $exception) {
    jsonResponse(['error' => $exception->getMessage()], $exception->status);
} catch (Throwable $exception) {
    error_log($exception->__toString());
    jsonResponse(['error' => 'Internal server error'], 500);
}
