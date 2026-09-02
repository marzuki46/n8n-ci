<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get('/', 'Home::index');
$routes->get('page/(:segment)', 'PageController::index/$1');

// ---------------------------------------------------------------------
// AUTH (publik)
// ---------------------------------------------------------------------
$routes->group('api', ['filter' => 'cors'], static function ($routes) {
    // Preflight CORS — biarkan filter `cors` yang menangani respons OPTIONS.
    $routes->options('(:any)', static function () {
        return '';
    });

    $routes->post('auth/login', 'Api\AuthController::login', ['filter' => 'throttle']);
    $routes->post('auth/login/(:any)', 'Api\AuthController::login/$1', ['filter' => 'throttle']);
    $routes->get('auth/me', 'Api\AuthController::me');
    $routes->post('auth/logout', 'Api\AuthController::logout');
    $routes->post('auth/select-workspace', 'Api\AuthController::selectWorkspace');
    $routes->get('csrf', 'Api\AuthController::csrf');

    // Google OAuth (SSO ringan) — publik.
    $routes->get('auth/oauth/status', 'Api\AuthController::oauthStatus');
    $routes->get('auth/oauth/google/start', 'Api\AuthController::oauthStart', ['filter' => 'throttle']);
    $routes->get('auth/oauth/google/callback', 'Api\AuthController::oauthCallback');

    // PUBLIK: profil & inquiry landing page (tanpa login; rate-limit ketat).
    $routes->get('public/profile', 'Api\PublicProfileController::profile');
    $routes->get('public/captcha', 'Api\PublicProfileController::captcha', ['filter' => 'ratelimit:30:3600']);
    $routes->post('public/inquiry', 'Api\PublicProfileController::submit', ['filter' => 'ratelimit:5:3600']);

    $routes->group('', ['filter' => 'auth'], static function ($routes) {
        // Preferensi akun (butuh login)
        $routes->get('user/preferences', 'Api\AuthController::preferences');
        $routes->put('user/preferences', 'Api\AuthController::savePreferences');
        // Ganti kredensial akun
        $routes->put('user/password', 'Api\AuthController::changePassword');
        $routes->post('user/email/request-change', 'Api\AuthController::requestEmailChange');
        $routes->get('user/email/verify', 'Api\AuthController::verifyEmailChange');
        // Keamanan: custom login path
        $routes->get('security/login-path', 'Api\AuthController::loginPath');
        $routes->put('security/login-path', 'Api\AuthController::saveLoginPath');
        // SSO: Google OAuth settings (owner)
        $routes->get('security/oauth-google', 'Api\AuthController::oauthSettings');
        $routes->put('security/oauth-google', 'Api\AuthController::saveOauthSettings');
        // Status engine/runtime (Pengaturan)
        $routes->get('system/runtimes', 'Api\SystemController::runtimes');
        // AI Budget guardrail
        $routes->get('system/ai-budget', 'Api\SystemController::aiBudget');
        $routes->put('system/ai-budget', 'Api\SystemController::saveAiBudget');
        // Vector knowledge base browser
        $routes->get('vectors/summary', 'Api\SystemController::vectorSummary');
        $routes->delete('vectors/namespace/(:any)', 'Api\SystemController::vectorDelete/$1');
        // Profil publik + inbox inquiry
        $routes->get('settings/profile', 'Api\InquiryController::showProfile');
        $routes->put('settings/profile', 'Api\InquiryController::saveProfile');
        $routes->get('inquiries', 'Api\InquiryController::index');
        $routes->post('inquiries/(:num)/mark', 'Api\InquiryController::mark/$1');
        $routes->delete('inquiries/(:num)', 'Api\InquiryController::delete/$1');

        $routes->get('nodes', 'Api\NodeController::index');
        $routes->post('nodes/test', 'Api\NodeController::test');

        // Tag workflow
        $routes->get('tags', 'Api\TagController::index');
        $routes->post('tags', 'Api\TagController::create');
        $routes->delete('tags/(:num)', 'Api\TagController::delete/$1');
        $routes->post('workflows/(:num)/tags/(:num)', 'Api\TagController::attach/$1/$2');
        $routes->delete('workflows/(:num)/tags/(:num)', 'Api\TagController::detach/$1/$2');

        // Projek (workspaces)
        $routes->get('projects', 'Api\ProjectController::index');
        $routes->get('projects/(:num)', 'Api\ProjectController::show/$1');
        $routes->post('projects', 'Api\ProjectController::create');
        $routes->put('projects/(:num)', 'Api\ProjectController::update/$1');
        $routes->delete('projects/(:num)', 'Api\ProjectController::delete/$1');

        // Anggota workspace (sharing; owner only)
        $routes->get('roles', 'Api\MemberController::roles');
        $routes->get('projects/(:num)/members', 'Api\MemberController::index/$1');
        $routes->post('projects/(:num)/members', 'Api\MemberController::add/$1');
        $routes->put('projects/(:num)/members/(:num)', 'Api\MemberController::updateRole/$1/$2');
        $routes->delete('projects/(:num)/members/(:num)', 'Api\MemberController::remove/$1/$2');

        // Error alerts (notifikasi workflow gagal)
        $routes->get('alerts', 'Api\AlertController::index');
        $routes->get('workflows/(:num)/alerts', 'Api\AlertController::show/$1');
        $routes->put('workflows/(:num)/alerts', 'Api\AlertController::update/$1');

        // Workflows
        $routes->get('workflows', 'Api\WorkflowController::index');
        $routes->get('workflows/(:num)', 'Api\WorkflowController::show/$1');
        $routes->post('workflows', 'Api\WorkflowController::create');
        $routes->put('workflows/(:num)', 'Api\WorkflowController::update/$1');
        $routes->post('workflows/(:num)/save', 'Api\WorkflowController::save/$1');
        $routes->post('workflows/(:num)/duplicate', 'Api\WorkflowController::duplicate/$1');
        $routes->get('workflows/(:num)/export', 'Api\WorkflowController::export/$1');
        $routes->post('workflows/import', 'Api\WorkflowController::import');
        $routes->delete('workflows/(:num)', 'Api\WorkflowController::delete/$1');

        // Versi workflow (snapshot + restore)
        $routes->get('workflows/(:num)/versions', 'Api\WorkflowController::versions/$1');
        $routes->post('workflows/(:num)/versions/(:num)/restore', 'Api\WorkflowController::restoreVersion/$1/$2');
        // Draft vs Publish
        $routes->post('workflows/(:num)/publish', 'Api\WorkflowController::publish/$1');
        $routes->post('workflows/(:num)/unpublish', 'Api\WorkflowController::unpublish/$1');
        $routes->get('workflows/(:num)/publication', 'Api\WorkflowController::publicationStatus/$1');
        // Replay eksekusi dari node tertentu
        $routes->post('executions/(:num)/replay', 'Api\ExecutionController::replayExecution/$1');
        // Template Gallery
        $routes->get('templates', 'Api\TemplateController::index');
        $routes->post('templates/(:segment)/install', 'Api\TemplateController::install/$1');
        // Webhook Inspector
        $routes->get('webhook-requests', 'Api\WebhookInspectorController::index');
        $routes->get('webhook-requests/(:num)', 'Api\WebhookInspectorController::show/$1');
        $routes->post('webhook-requests/(:num)/replay', 'Api\WebhookInspectorController::replay/$1');

        // Eksekusi
        $routes->post('workflows/(:num)/execute', 'Api\ExecutionController::execute/$1');
        $routes->get('executions', 'Api\ExecutionController::index');
        $routes->get('executions/stats', 'Api\ExecutionController::stats');
        $routes->get('executions/(:num)', 'Api\ExecutionController::show/$1');
        $routes->post('executions/(:num)/stop', 'Api\ExecutionController::stop/$1');
        $routes->post('executions/(:num)/pause', 'Api\ExecutionController::pause/$1');
        $routes->post('executions/(:num)/resume', 'Api\ExecutionController::resume/$1');

        // Jadwal / cron
        $routes->get('schedules', 'Api\ScheduleController::index');
        $routes->get('schedules/status', 'Api\ScheduleController::status');
        $routes->post('schedules', 'Api\ScheduleController::create');
        $routes->put('schedules/(:num)', 'Api\ScheduleController::update/$1');
        $routes->delete('schedules/(:num)', 'Api\ScheduleController::delete/$1');
        $routes->post('schedules/(:num)/run-now', 'Api\ScheduleController::runNow/$1');

        // Credentials
        $routes->get('credentials', 'Api\CredentialController::index');
        $routes->get('credential-types', 'Api\CredentialController::types');
        $routes->post('credentials', 'Api\CredentialController::create');
        $routes->put('credentials/(:num)', 'Api\CredentialController::update/$1');
        $routes->delete('credentials/(:num)', 'Api\CredentialController::delete/$1');

        // API keys (untuk Public API /api/v1)
        $routes->get('api-keys', 'Api\ApiKeyController::index');
        $routes->post('api-keys', 'Api\ApiKeyController::create');
        $routes->post('api-keys/(:num)/revoke', 'Api\ApiKeyController::revoke/$1');
        $routes->delete('api-keys/(:num)', 'Api\ApiKeyController::delete/$1');

        // Dashboard
        $routes->get('dashboard/overview', 'Api\DashboardController::overview');
    });
});

// ---------------------------------------------------------------------
// PUBLIC API /api/v1 (X-API-Key / Bearer, tanpa CSRF)
// ---------------------------------------------------------------------
$routes->group('api/v1', ['filter' => ['cors', 'ratelimit:120:60', 'apikey']], static function ($routes) {
    $routes->options('(:any)', static function () {
        return '';
    });

    $routes->get('workflows', 'Api\ApiV1Controller::workflows');
    $routes->get('executions', 'Api\ApiV1Controller::executions');
    $routes->get('executions/(:num)', 'Api\ApiV1Controller::show/$1');
    $routes->post('executions', 'Api\ApiV1Controller::createExecution');

    // Paket 3 — Content AI untuk plugin WordPress (rate-limit longgar karena bulk).
    $routes->get('wp/status', 'Api\WpContentController::status');
    $routes->post('wp/generate', 'Api\WpContentController::generate');
    $routes->post('wp/continue', 'Api\WpContentController::continueContent');

    // MCP Server (Model Context Protocol) — workflow sebagai tools untuk AI eksternal.
    $routes->post('mcp', 'Api\McpController::handle');
});

// ---------------------------------------------------------------------
// WEBHOOK (publik, tanpa auth)
// ---------------------------------------------------------------------
$routes->group('webhook', ['filter' => ['cors', 'ratelimit:60:60']], static function ($routes) {
    $routes->options('(:any)', static function () {
        return '';
    });

    $routes->get('(:any)', 'Api\WebhookController::handle/$1');
    $routes->post('(:any)', 'Api\WebhookController::handle/$1');
    $routes->put('(:any)', 'Api\WebhookController::handle/$1');
    $routes->patch('(:any)', 'Api\WebhookController::handle/$1');
    $routes->delete('(:any)', 'Api\WebhookController::handle/$1');
});

// SPA catch-all: setiap path non-API non-asset → serve index.html (hash routing).
$routes->get('(:any)', static function (string $segment) {
    $apiPrefixes = ['api', 'assets', 'favicon.ico'];
    foreach ($apiPrefixes as $prefix) {
        if (strpos($segment, $prefix) === 0) {
            return; // biarkan CodeIgniter handle / 404
        }
    }
    $spaFile = FCPATH . 'index.html';
    if (is_file($spaFile)) {
        $response = \Config\Services::response();
        return $response
            ->setContentType('text/html')
            ->setBody((string) file_get_contents($spaFile));
    }
    // Dev mode: index.html belum di-build
    return view('errors/html/404', [
        'title'   => 'Development Mode',
        'message' => 'SPA belum di-build. Jalankan <code>npm run build</code> di folder <code>frontend/</code> lalu copy <code>dist/</code> ke <code>backend/public/</code>.',
    ]);
});
