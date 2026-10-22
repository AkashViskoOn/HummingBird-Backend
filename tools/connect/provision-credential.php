<?php

/**
 * Humming Bird Connect — provision a tenant + API credential.
 *
 * Creates (or reuses) a connect_tenants row and a vault-encrypted API
 * credential connected to it. The plaintext secret is printed ONCE and is
 * never stored or logged. Run this ON THE SERVER (or wherever .env points at
 * RDS): ENVIRONMENT is forced to 'production' here so the default DB group is
 * the real MySQL DB, never the SQLite `tests` group.
 *
 * Usage (run from humming-bird-backend/):
 *   php tools/connect/provision-credential.php \
 *     --tenant-slug=remark-test \
 *     --tenant-name="Remark (test)" \
 *     --env=test \
 *     --scopes=connect:test,users:write,messages:send,messages:read,messages:status \
 *     --name="Remark test key"
 *
 * Scopes available (Config\Connect): connect:test, users:read, users:write,
 * messages:send, messages:read, messages:status, webhooks:manage.
 * NOTE: the auth/test probe endpoint requires the connect:test scope.
 */

use App\Models\Connect\ConnectApiCredentialModel;
use App\Models\Connect\ConnectTenantModel;
use App\Services\Connect\ConnectCredentialService;

if (strpos(PHP_SAPI, 'cgi') === 0) {
    exit("The cli tool is not supported when running php-cgi. It needs php-cli to function!\n");
}

error_reporting(-1);
ini_set('display_errors', '1');

define('FCPATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once SYSTEMPATH . 'Config/DotEnv.php';
(new CodeIgniter\Config\DotEnv(ROOTPATH))->load();

// Provisioning must always hit the real database (default group), never the
// SQLite `tests` group that ENVIRONMENT=testing selects.
define('ENVIRONMENT', 'production');

$app = Config\Services::codeigniter();
$app->initialize();
Config\Services::reset();

function arg(string $name): ?string
{
    foreach (($_SERVER['argv'] ?? []) as $argv) {
        if (strpos($argv, '--' . $name . '=') === 0) {
            return substr($argv, strlen($name) + 3);
        }
    }
    return null;
}

$slug  = arg('tenant-slug') ?? 'remark';
$name  = arg('tenant-name') ?? 'Remark';
$tenv  = strtolower(arg('env') ?? 'prod') === 'test' ? 'test' : 'production';
$creds = ($scopes = arg('scopes')) ? array_values(array_filter(array_map('trim', explode(',', $scopes)))) : ['connect:test'];

$db = Config\Database::connect(Config\Database::class::$defaultGroup ?? 'default');
$tenantModel = new ConnectTenantModel($db);
$credModel   = new ConnectApiCredentialModel($db);

$tenant = $tenantModel->where('slug', $slug)->first();
if (! $tenant) {
    $tenantId = $tenantModel->insert([
        'uuid'         => bin2hex(random_bytes(16)),
        'name'         => $name,
        'slug'         => $slug,
        'status'       => 'active',
        'environment'  => $tenv,
        'contact_name' => arg('contact-name'),
        'contact_email'=> arg('contact-email'),
    ]);
    if (! $tenantId) {
        fwrite(STDERR, 'Could not create tenant: ' . print_r($tenantModel->errors(), true) . "\n");
        exit(1);
    }
    $existing = null;
} else {
    $existing = $tenant;
}

$tenantId ??= (int) $tenant['id'];

$svc = new ConnectCredentialService();
$out = $svc->createCredential((int) $tenantId, $tenv, $creds, arg('name'));

echo "\n========================================\n";
echo '  Tenant    : ' . $slug . ' (id=' . $tenantId . ', env=' . $tenv . ")\n";
echo '  API Key   : ' . $out['api_key'] . "\n";
echo '  API Secret: ' . $out['api_secret'] . "\n";
echo '  Scopes    : ' . implode(', ', $creds) . "\n";
echo '  Credential: ' . $out['credential_id'] . "\n";
echo '----------------------------------------\n';
echo "  SECRET SHOWN ONCE — store it now. It cannot be recovered.\n";
echo "  Send this EXACT single curl to verify round-trip auth:\n\n";
$method = 'GET';
$path   = '/api/v1/connect/auth/test';
$ts     = time();
$rid    = 'req_' . bin2hex(random_bytes(8));
$sha    = hash('sha256', '');
$canon  = $method . "\n" . $path . "\n" . $ts . "\n" . $rid . "\n" . $sha;
$sig    = hash_hmac('sha256', $canon, $out['api_secret']);
printf(
    'curl -sS -H "X-HummingBird-Key: %s" -H "X-HummingBird-Timestamp: %s" -H "X-HummingBird-Request-Id: %s" -H "X-HummingBird-Signature: %s" %s%s',
    $out['api_key'],
    $ts,
    $rid,
    $sig,
    'https://secureapi.myhummingbird.net',
    $path
);
echo "\n========================================\n\n";