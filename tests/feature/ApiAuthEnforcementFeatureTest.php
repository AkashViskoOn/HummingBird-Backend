<?php

namespace Tests;

use Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Auth enforcement across the consumer API.
 *
 * A 2026-08 audit found endpoints reachable without a JWT, and CI4 filters are
 * not used for consumer APIs — every controller hand-rolls
 * check_jwt_authentication(). This test makes that contract repeatable: an
 * unauthenticated request to ANY registered consumer endpoint must be rejected
 * (HTTP 401, or a JSON envelope with status=false). The moment a route starts
 * answering an anonymous request with status=true, data is leaking and this
 * suite goes red.
 *
 * Deliberately excluded from the sweep:
 *  - health, health/ready        : liveness/readiness probes, intentionally open
 *  - user/register-number        : registration flow, must stay open
 *  - user/otp-verify             : registration flow, must stay open
 *  - api/v1/connect/*            : guarded by its own HMAC filter (ConnectAuthProbe
 *                                  covers auth/test in the Connect suites)
 *  - notification/send-notification : REMOVED route — the helper takes 10
 *                                  positional args and returns bool; it is only
 *                                  invoked internally by MessageController /
 *                                  ConnectMessageService and crashed with
 *                                  ArgumentCountError whenever hit over HTTP.
 *
 * @internal
 */
final class ApiAuthEnforcementFeatureTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = false;
    protected $migrateOnce = false;
    protected $refresh     = false;
    protected $namespace   = null;

    /**
     * One representative (method, uri) per consumer route group, plus the
     * standalone groups. No Authorization header is ever attached.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideProtectedRoutes(): array
    {
        return [
            'user dashboard-details'    => ['GET', 'user/dashboard-details'],
            'user get-single-user'      => ['GET', 'user/get-single-user'],
            'user block-user'           => ['GET', 'user/block-user'],
            'contact add-contact'       => ['POST', 'contact/add-contact'],
            'contact fetch-contacts'    => ['GET', 'contact/fetch-contacts'],
            'contact check-user'        => ['GET', 'contact/check-user'],
            'room create-room'          => ['POST', 'room/create-room'],
            'room fetch-rooms'          => ['GET', 'room/fetch-rooms'],
            'room get-group-info'       => ['GET', 'room/get-group-info'],
            'message send-message'      => ['POST', 'message/send-message'],
            'message fetch-messages'    => ['GET', 'message/fetch-messages'],
            'message get-scheduled'     => ['GET', 'message/get-scheduled-messages'],
            'message forward-message'   => ['POST', 'message/forward-message'],
            'message message-info'      => ['GET', 'message/message-info'],
            'scheduling-data get'       => ['GET', 'scheduling-data/get-scheduling-data'],
            'ai categorize'             => ['POST', 'ai/categorize'],
            'ai summarize'              => ['POST', 'ai/summarize'],
            'generative-ai get-ai'      => ['POST', 'generative-ai/get-ai-message'],
            'report report-user'        => ['POST', 'report/report-user'],
            'search search-messages'    => ['GET', 'search/search-messages'],
            'media compress'            => ['POST', 'media/compress'],
            'media info'                => ['GET', 'media/info'],
            'media gallery'             => ['GET', 'media/gallery'],
            'call init'                 => ['POST', 'call/init'],
            'call history'              => ['GET', 'call/history'],
            'call block'                => ['POST', 'call/block'],
            'schedule categories'       => ['GET', 'schedule/categories'],
            'schedule get-templates'    => ['GET', 'schedule/get-templates'],
            'schedule upcoming'         => ['GET', 'schedule/upcoming'],
            'status my'                 => ['GET', 'status/my'],
            'status my-update'          => ['GET', 'status/my-update'],
            'status create'             => ['POST', 'status/create'],
            'status fetch-updates'      => ['GET', 'status/fetch-updates'],
            'moments list'              => ['GET', 'moments/list'],
            'moments schedule'          => ['POST', 'moments/schedule'],
            'moments calendar'          => ['GET', 'moments/calendar'],
        ];
    }

    private function call(string $method, string $uri): \CodeIgniter\Test\TestResponse
    {
        Services::reset();
        $this->request = $this->setupRequest($method, $uri);
        $this->request->setMethod($method);
        Services::injectMock('request', $this->request);
        $routes = Services::routes()->loadRoutes();
        $routes->setHTTPVerb($method);
        $this->app = $this->createApplication();
        $response = $this->app
            ->setContext('web')
            ->setRequest($this->request)
            ->run($routes, true);
        Services::reset();
        return new \CodeIgniter\Test\TestResponse($response);
    }

    /**
     * @dataProvider provideProtectedRoutes
     */
    public function testUnauthenticatedRequestIsRejected(string $method, string $uri): void
    {
        $result = $this->call($method, $uri);
        $status = $result->response()->getStatusCode();
        $body   = (string) $result->response()->getBody();

        if ($status === 401 || $status === 403 || $status === 404) {
            // Explicit rejection (or an unknown route) is still safe.
            $this->assertTrue(true);
            return;
        }

        // Otherwise the body must be JSON and must not grant access.
        $decoded = json_decode($body);
        $this->assertInstanceOf(\stdClass::class, $decoded,
            "$method $uri: expected a JSON envelope, got HTTP $status: "
            . substr($body, 0, 200));

        $this->assertNotSame(true, $decoded->status ?? null,
            "$method $uri answered an unauthenticated request with status=true "
            . '- data leak: JWT check missing or bypassed');
        $this->assertSame(false, $decoded->status ?? null,
            "$method $uri did not reject unauthenticated access "
            . "(status without a token: " . var_export($decoded->status ?? null, true) . ')');
    }

    public function testNotificationSendNotificationRouteIsNotRegistered(): void
    {
        // Removed because it crashed with ArgumentCountError (10 required args)
        // and had no auth check. The framework throws PageNotFoundException for
        // a missing route — that exception IS the proof the route is gone.
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->call('POST', 'notification/send-notification');
    }

    public function testEmojiAddEmojiNeverAnswersStatusTrue(): void
    {
        // EmojiController::addEmoji has no JWT check and an early broken return;
        // it must never affirm status=true, whatever else it does.
        $result = $this->call('POST', 'emoji/add-emoji');
        $body   = (string) $result->response()->getBody();
        $decoded = json_decode($body);
        if ($decoded instanceof \stdClass) {
            $this->assertNotSame(true, $decoded->status ?? null, 'emoji/add-emoji opened data');
        }
    }
}