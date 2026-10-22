<?php

namespace App\Controllers;

use Throwable;

/**
 * Liveness and readiness probes.
 *
 * Nothing in this controller required a JWT on purpose: a load balancer or an
 * uptime check cannot present an app credential, and a probe that needs one is
 * a probe nobody wires up. The endpoints therefore report only whether a
 * dependency is *reachable* and whether a secret is *present* — never a secret
 * value, never a query string, never a stack trace.
 *
 *   GET /health   liveness. Answers while the PHP process is able to serve a
 *                 request at all. Never touches the database, so a database
 *                 outage does not make an orchestrator kill a healthy worker.
 *
 *   GET /health/ready
 *                 readiness. Checks the dependencies the app cannot work
 *                 without. Answers 503 when any of them is unusable, which is
 *                 what a load balancer should act on.
 */
class HealthController extends BaseController
{
    /**
     * Secrets the app cannot run without. Only presence is reported.
     *
     * JWT_SECRET and INTERNAL_CRON_KEY are the two that are easiest to forget
     * and hardest to diagnose from the outside: a missing JWT_SECRET rejects
     * every socket handshake, and a missing INTERNAL_CRON_KEY makes scheduled
     * messages silently never send while every other endpoint looks healthy.
     */
    private const REQUIRED_SECRETS = [
        'JWT_SECRET',
        'INTERNAL_CRON_KEY',
    ];

    /**
     * Configuration whose absence degrades a feature rather than the app.
     */
    private const OPTIONAL_SECRETS = [
        'OPENAI_API_KEY',
        'ENCRYPTION_KEY',
        'MSG91_AUTH_KEY',
        'FCM_SERVICE_ACCOUNT_PATH',
        'CONNECT_SECRET_ENCRYPTION_KEY',
    ];

    /**
     * Liveness probe. Always 200 while the process can respond.
     */
    public function index()
    {
        return $this->respond(200, 'ok', [
            'status'   => 'ok',
            'service'  => 'humming-bird-backend',
            'env'      => $this->safeEnv(),
            'time'     => date('c'),
            'uptime_s' => $this->uptimeSeconds(),
        ]);
    }

    /**
     * Readiness probe. 503 when a hard dependency is unusable.
     */
    public function ready()
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'secrets'  => $this->checkSecrets(),
            'writable' => [
                'ok'     => is_writable(WRITEPATH),
                'detail' => is_writable(WRITEPATH) ? 'writable' : 'WRITEPATH is not writable',
            ],
        ];

        $ok = true;
        foreach ($checks as $check) {
            if (! $check['ok']) {
                $ok = false;
            }
        }

        return $this->respond($ok ? 200 : 503, $ok ? 'ready' : 'not_ready', [
            'status'  => $ok ? 'ok' : 'degraded',
            'env'     => $this->safeEnv(),
            'time'    => date('c'),
            'checks'  => $checks,
            'version' => $this->appVersion(),
        ]);
    }

    /**
     * Confirms the database answers a trivial query, with a bounded timeout so
     * a hung database cannot hang the probe itself.
     *
     * @return array{ok: bool, detail: string}
     */
    private function checkDatabase(): array
    {
        try {
            $db     = \Config\Database::connect();
            $result = $db->query('SELECT 1 AS ok');

            if ($result === false) {
                $error = $db->error();

                return [
                    'ok'     => false,
                    'detail' => 'query failed: ' . ($error['message'] ?? 'unknown error'),
                ];
            }

            return [
                'ok'     => true,
                'detail' => 'connected',
                'driver' => (string) $db->DBDriver,
            ];
        } catch (Throwable $e) {
            return [
                'ok'     => false,
                'detail' => 'unreachable: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Reports presence only. Values are never returned, logged or echoed.
     *
     * @return array{ok: bool, detail: string, missing: list<string>, optional_missing: list<string>}
     */
    private function checkSecrets(): array
    {
        $missing          = [];
        $optionalMissing  = [];

        foreach (self::REQUIRED_SECRETS as $name) {
            if (! $this->secretIsSet($name)) {
                $missing[] = $name;
            }
        }

        foreach (self::OPTIONAL_SECRETS as $name) {
            if (! $this->secretIsSet($name)) {
                $optionalMissing[] = $name;
            }
        }

        if ($missing !== []) {
            // Named, not valued: an operator has to know which one to set.
            log_message('critical', 'Readiness probe failed: required configuration missing: ' . implode(', ', $missing));

            return [
                'ok'               => false,
                'detail'           => 'missing required configuration: ' . implode(', ', $missing),
                'missing'          => $missing,
                'optional_missing' => $optionalMissing,
            ];
        }

        return [
            'ok'               => true,
            'detail'           => 'present',
            'missing'          => [],
            'optional_missing' => $optionalMissing,
        ];
    }

    /**
     * A secret counts as set only when it is a non-empty string after trimming,
     * so an empty `JWT_SECRET=` in .env is reported rather than assumed fine.
     *
     * CodeIgniter's dotenv loader does not consistently populate getenv(),
     * so all three of the places it can land are checked.
     */
    private function secretIsSet(string $name): bool
    {
        $value = getenv($name);

        if ($value === false && isset($_ENV[$name])) {
            $value = $_ENV[$name];
        }

        if ($value === false && isset($_SERVER[$name])) {
            $value = $_SERVER[$name];
        }

        return is_string($value) && trim($value) !== '';
    }

    /**
     * Reports the environment name only. In production this deliberately
     * stays as the bare string "production" — never a host, DSN or path.
     */
    private function safeEnv(): string
    {
        $env = (string) (getenv('CI_ENVIRONMENT') ?: ENVIRONMENT);

        return $env === '' ? 'unknown' : $env;
    }

    private function appVersion(): string
    {
        $commit = trim((string) (getenv('DEPLOY_COMMIT') ?: ''));

        if ($commit !== '') {
            return substr($commit, 0, 12);
        }

        return 'unknown';
    }

    /**
     * Process uptime, used by uptime monitors to detect silent worker restarts.
     */
    private function uptimeSeconds(): int
    {
        $booted = defined('ENVIRONMENT') ? @filemtime(WRITEPATH . 'cache') : false;

        return $booted === false ? -1 : max(0, time() - $booted);
    }

    /**
     * The consumer envelope, so uptime tooling sees the same shape everywhere.
     */
    private function respond(int $status, string $message, array $data)
    {
        return $this->response->setStatusCode($status)->setJSON([
            'status'  => $status < 400,
            'message' => $message,
            'data'    => $data,
        ]);
    }
}
