<?php

namespace Ably\LaravelBroadcaster\Tests;

use Ably\LaravelBroadcaster\AblyBroadcaster;
use Ably\LaravelBroadcaster\Utils;
use Ably\PubSub\Http;
use Ably\PubSub\PubSubHttpClient;
use Ably\PubSub\Utils\Miscellaneous;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Mockery as m;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AblyBroadcasterTest extends TestCase
{
    /**
     * @var \Ably\LaravelBroadcaster\AblyBroadcaster
     */
    public $broadcaster;

    public $ably;

    public $guardedChannelCapability = ['*'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ably = m::mock(PubSubHttpClient::class, ['abcd:efgh']);

        $this->ably->shouldReceive('time')
                   ->zeroOrMoreTimes()
                   ->andReturn(time() * 1000); // TODO - make this call at runtime

        $this->broadcaster = m::mock(AblyBroadcaster::class, [$this->ably, []])->makePartial();
    }

    protected function tearDown(): void
    {
        AblyBroadcaster::resolveGuestClientIdUsing(null);

        parent::tearDown();

        m::close();
    }

    public function testAuthCallValidAuthenticationResponseWithPrivateChannelWhenCallbackReturnTrue()
    {
        $this->broadcaster->channel('test', function () {
            return true;
        });

        $this->broadcaster->shouldReceive('validAuthenticationResponse')
                          ->once();

        $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('private:test', null)
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPrivateChannelWhenCallbackReturnFalse()
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function () {
            return false;
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('private:test', null)
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPrivateChannelWhenRequestUserNotFound()
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function () {
            return true;
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithoutUserForChannel('private:test', null)
        );
    }

    public function testAuthCallValidAuthenticationResponseWithPresenceChannelWhenCallbackReturnAnArray()
    {
        $returnData = [1, 2, 3, 4];
        $this->broadcaster->channel('test', function () use ($returnData) {
            return $returnData;
        });

        $this->broadcaster->shouldReceive('validAuthenticationResponse')
                          ->once();

        $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('presence:test', null)
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPresenceChannelWhenCallbackReturnNull()
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function () {
            //
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('presence:test', null)
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPresenceChannelWhenRequestUserNotFound()
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function () {
            return [1, 2, 3, 4];
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithoutUserForChannel('private:test', null)
        );
    }

    public function testShouldGetSignedToken()
    {
        $token = $this->broadcaster->getSignedToken(null, null, 'user123', $this->guardedChannelCapability);
        $parsedToken = Utils::parseJwt($token);
        $header = $parsedToken['header'];
        $payload = $parsedToken['payload'];

        self::assertEquals('JWT', $header['typ']);
        self::assertEquals('HS256', $header['alg']);
        self::assertEquals('abcd', $header['kid']);

        $expectedCapability = '{"public:*":["subscribe","history","channel-metadata"]}';
        self::assertEquals($expectedCapability, $payload['x-ably-capability']);
        self::assertEquals('user123', $payload['x-ably-clientId']);

        self::assertEquals('integer', gettype($payload['iat']));
        self::assertEquals('integer', gettype($payload['exp']));
    }

    public function testShouldGetSignedTokenForGivenChannel()
    {
        $token = $this->broadcaster->getSignedToken('private:channel', null, 'user123', $this->guardedChannelCapability);
        $parsedToken = Utils::parseJwt($token);
        $header = $parsedToken['header'];
        $payload = $parsedToken['payload'];

        self::assertEquals('JWT', $header['typ']);
        self::assertEquals('HS256', $header['alg']);
        self::assertEquals('abcd', $header['kid']);

        $expectedCapability = '{"public:*":["subscribe","history","channel-metadata"],"private:channel":["*"]}';
        self::assertEquals($expectedCapability, $payload['x-ably-capability']);
        self::assertEquals('user123', $payload['x-ably-clientId']);

        self::assertEquals('integer', gettype($payload['iat']));
        self::assertEquals('integer', gettype($payload['exp']));
    }

    public function testShouldHaveUpgradedCapabilitiesForValidTokenOfSameIdentity()
    {
        $token = $this->broadcaster->getSignedToken('private:channel', null, 'user123', $this->guardedChannelCapability);

        $parsedToken = Utils::parseJwt($token);
        $payload = $parsedToken['payload'];
        self::assertEquals('integer', gettype($payload['iat']));
        self::assertEquals('integer', gettype($payload['exp']));
        $iat = $payload['iat'];
        $exp = $payload['exp'];

        $token = $this->broadcaster->getSignedToken('private:channel2', $token, 'user123', $this->guardedChannelCapability);
        $parsedToken = Utils::parseJwt($token);
        $payload = $parsedToken['payload'];
        $expectedCapability = '{"public:*":["subscribe","history","channel-metadata"],"private:channel":["*"],"private:channel2":["*"]}';
        self::assertEquals('user123', $payload['x-ably-clientId']);
        self::assertEquals($expectedCapability, $payload['x-ably-capability']);
        self::assertEquals($iat, $payload['iat']);
        self::assertEquals($exp, $payload['exp']);
    }

    public function testShouldResetClaimsForValidTokenOfDifferentIdentity()
    {
        $now = time();
        $token = $this->signToken(['public:*' => ['subscribe'], 'private:channel' => ['*']], 'user123', $now - 1000, $now + 1000);

        $token = $this->broadcaster->getSignedToken('private:channel3', $token, 'user98', $this->guardedChannelCapability);
        $payload = Utils::parseJwt($token)['payload'];
        $expectedCapability = '{"public:*":["subscribe","history","channel-metadata"],"private:channel3":["*"]}';
        self::assertEquals('user98', $payload['x-ably-clientId']);
        self::assertEquals($expectedCapability, $payload['x-ably-capability']);
        self::assertGreaterThanOrEqual($now, $payload['iat']);
        self::assertEquals($payload['iat'] + 28800, $payload['exp']);
    }

    public function testShouldResetClaimsWhenIdentityGoesFromNullToUser()
    {
        $now = time();
        $token = $this->signToken(['public:*' => ['subscribe'], 'private:secret' => ['*']], null, $now - 1000, $now + 1000);

        $token = $this->broadcaster->getSignedToken(null, $token, 'user123', $this->guardedChannelCapability);
        $payload = Utils::parseJwt($token)['payload'];
        self::assertEquals('user123', $payload['x-ably-clientId']);
        self::assertEquals('{"public:*":["subscribe","history","channel-metadata"]}', $payload['x-ably-capability']);
        self::assertGreaterThanOrEqual($now, $payload['iat']);
    }

    public function testShouldResetClaimsWhenIdentityGoesFromUserToGuest()
    {
        $now = time();
        $token = $this->signToken(['public:*' => ['subscribe'], 'private:secret' => ['*']], 'user123', $now - 1000, $now + 1000);

        $token = $this->broadcaster->getSignedToken(null, $token, null, $this->guardedChannelCapability);
        $payload = Utils::parseJwt($token)['payload'];
        self::assertNull($payload['x-ably-clientId']);
        self::assertEquals('{"public:*":["subscribe","history","channel-metadata"]}', $payload['x-ably-capability']);
        self::assertGreaterThanOrEqual($now, $payload['iat']);
    }

    public function testShouldReuseClaimsWhenIdentityStaysNull()
    {
        $now = time();
        $token = $this->signToken(['public:*' => ['subscribe']], null, $now - 1000, $now + 1000);

        $token = $this->broadcaster->getSignedToken(null, $token, null, $this->guardedChannelCapability);
        $payload = Utils::parseJwt($token)['payload'];
        self::assertEquals('{"public:*":["subscribe"]}', $payload['x-ably-capability']);
        self::assertEquals($now - 1000, $payload['iat']);
        self::assertEquals($now + 1000, $payload['exp']);
    }

    public function testAuthSignedToken()
    {
        $this->broadcaster->channel('test1', function () {
            return true;
        });
        $this->broadcaster->channel('test2', function () {
            return true;
        });
        $this->broadcaster->shouldReceive('validAuthenticationResponse')
                          ->times(2)
                          ->andReturn(true, ['userid' => 'user1234', 'info' => 'Hello there']);

        $prevResponse = $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('private:test1', null)
        );
        self::assertEquals('string', gettype($prevResponse['token']));
        $expectedToken = $this->broadcaster->getSignedToken('private:test1', null, 42, $this->guardedChannelCapability);
        self::assertEquals($expectedToken, $prevResponse['token']);
        self::assertTrue(Utils::isJwtValid($expectedToken, function () {
            return time();
        }, 'efgh'));

        $response = $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('presence:test2', $prevResponse['token'])
        );

        self::assertEquals('string', gettype($response['token']));
        $expectedToken = $this->broadcaster->getSignedToken('presence:test2', $prevResponse['token'], 42, $this->guardedChannelCapability);
        self::assertEquals($expectedToken, $response['token']);
        self::assertTrue(Utils::isJwtValid($expectedToken, function () {
            return time();
        }, 'efgh'));

        self::assertEquals('array', gettype($response['info']));
        self::assertEquals(['userid' => 'user1234', 'info' => 'Hello there'], $response['info']);
    }

    public function testCustomChannelCapability()
    {
        $this->broadcaster->channel('test1', function () {
            return true;
        });

        $this->broadcaster->shouldReceive('validAuthenticationResponse')
                          ->times(1)
                          ->andReturn(['userid' => 'user1234', 'info' => 'Hello there', 'ably-capability' => ['publish', 'subscribe', 'presence']]);

        $response = $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('private:test1', null)
        );
        self::assertEquals('string', gettype($response['token']));
        $expectedToken = $this->broadcaster->getSignedToken('private:test1', null, 42, ['publish', 'subscribe', 'presence']);
        self::assertEquals($expectedToken, $response['token']);
        self::assertTrue(Utils::isJwtValid($expectedToken, function () {
            return time();
        }, 'efgh'));

        self::assertEquals('array', gettype($response['info']));
        self::assertEquals(['userid' => 'user1234', 'info' => 'Hello there'], $response['info']);
    }

    public function testShouldFormatChannels()
    {
        $result = $this->broadcaster->formatChannels(['private-hello']);
        self::assertEquals('private:hello', $result[0]);

        $result = $this->broadcaster->formatChannels(['presence-hello']);
        self::assertEquals('presence:hello', $result[0]);

        $result = $this->broadcaster->formatChannels(['hello']);
        self::assertEquals('public:hello', $result[0]);
    }

    /**
     * `guest:` + 22 random characters + `.` + a 22 character HMAC tag.
     */
    const GUEST_ID_PATTERN = '/^guest:[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{22}$/';

    public function testGuestClientIdIsOffByDefault()
    {
        $response = $this->broadcaster->auth($this->getMockRequestWithoutUserForChannel('public:test', null));

        $payload = Utils::parseJwt($response['token'])['payload'];
        self::assertNull($payload['x-ably-clientId']);
    }

    public function testGuestClientIdOffDoesNotTouchSession()
    {
        // The mock request has no hasSession() expectation, so touching the session would fail the test
        $request = $this->getMockRequestWithUserForChannel('public:test', null, 'guest:abc');

        $response = $this->broadcaster->auth($request);

        self::assertEquals('guest:abc', Utils::parseJwt($response['token'])['payload']['x-ably-clientId']);
    }

    public function testGuestClientIdIsStablePerSession()
    {
        $broadcaster = $this->guestBroadcaster();
        $session = $this->newSession();

        $first = $this->guestClientIdFrom($broadcaster->auth($this->getMockRequestWithSession('public:test', null, null, $session)));
        $second = $this->guestClientIdFrom($broadcaster->auth($this->getMockRequestWithSession('public:other', null, null, $session)));

        self::assertMatchesRegularExpression(self::GUEST_ID_PATTERN, $first);
        self::assertSame($first, $second);
        self::assertSame($first, $session->get('ably_guest_client_id'));

        $otherSession = $this->guestClientIdFrom($broadcaster->auth($this->getMockRequestWithSession('public:test', null, null, $this->newSession())));
        self::assertNotSame($first, $otherSession);
    }

    public function testGuestClientIdIsReusedFromPresentedGuestTokenWithoutSession()
    {
        $broadcaster = $this->guestBroadcaster();

        $firstResponse = $broadcaster->auth($this->getMockRequestWithSession('public:test', null, null, null));
        $first = $this->guestClientIdFrom($firstResponse);
        self::assertMatchesRegularExpression(self::GUEST_ID_PATTERN, $first);

        $second = $this->guestClientIdFrom(
            $broadcaster->auth($this->getMockRequestWithSession('public:test', $firstResponse['token'], null, null))
        );
        self::assertSame($first, $second);

        $third = $this->guestClientIdFrom($broadcaster->auth($this->getMockRequestWithSession('public:test', null, null, null)));
        self::assertNotSame($first, $third);
    }

    public function testGuestClientIdIsNotReusedFromExpiredOrForgedGuestToken()
    {
        $broadcaster = $this->guestBroadcaster();
        $now = time();
        $expired = $this->signToken(['public:*' => ['subscribe']], 'guest:expired', $now - 2000, $now - 1000);
        $forged = $this->signToken(['public:*' => ['subscribe']], 'guest:forged', $now - 1000, $now + 1000, 'wrong');

        // validly signed, but the tag does not match the random part
        $tampered = $this->signToken(['public:*' => ['subscribe']], 'guest:'.str_repeat('a', 22).'.'.str_repeat('b', 22), $now - 1000, $now + 1000);

        foreach ([$expired, $forged, $tampered] as $token) {
            $presented = Utils::parseJwt($token)['payload']['x-ably-clientId'];
            $clientId = $this->guestClientIdFrom($broadcaster->auth($this->getMockRequestWithSession('public:test', $token, null, null)));
            self::assertMatchesRegularExpression(self::GUEST_ID_PATTERN, $clientId);
            self::assertNotSame($presented, $clientId);
        }
    }

    public function testGuestClientIdIsNotTakenFromPresentedUserToken()
    {
        $broadcaster = $this->guestBroadcaster();
        $userToken = $broadcaster->getSignedToken('private:secret', null, 'user123', $this->guardedChannelCapability);

        $response = $broadcaster->auth($this->getMockRequestWithSession('public:test', $userToken, null, null));

        $payload = Utils::parseJwt($response['token'])['payload'];
        self::assertMatchesRegularExpression(self::GUEST_ID_PATTERN, $payload['x-ably-clientId']);
        // user to guest does not carry the user's private capability across
        self::assertEquals('{"public:*":["subscribe","history","channel-metadata"]}', $payload['x-ably-capability']);
    }

    public function testAuthenticatedRequestClearsGuestClientIdSoLogoutGetsANewOne()
    {
        $broadcaster = $this->guestBroadcaster();
        $broadcaster->channel('test', function () {
            return true;
        });
        $session = $this->newSession();

        $guestId = $this->guestClientIdFrom($broadcaster->auth($this->getMockRequestWithSession('public:test', null, null, $session)));
        self::assertTrue($session->has('ably_guest_client_id'));

        $response = $broadcaster->auth($this->getMockRequestWithSession('private:test', null, $this->mockUser(42), $session));
        self::assertEquals('42', Utils::parseJwt($response['token'])['payload']['x-ably-clientId']);
        self::assertFalse($session->has('ably_guest_client_id'));

        $afterLogout = $this->guestClientIdFrom($broadcaster->auth($this->getMockRequestWithSession('public:test', null, null, $session)));
        self::assertMatchesRegularExpression(self::GUEST_ID_PATTERN, $afterLogout);
        self::assertNotSame($guestId, $afterLogout);
    }

    public function testGuestClientIdResolverOverridesTheBuiltInId()
    {
        $broadcaster = $this->guestBroadcaster();
        $request = $this->getMockRequestWithSession('public:test', null, null, $this->newSession());
        $received = null;
        AblyBroadcaster::resolveGuestClientIdUsing(function ($req) use (&$received) {
            $received = $req;

            return 'tenant-guest-1';
        });

        self::assertSame('tenant-guest-1', $this->guestClientIdFrom($broadcaster->auth($request)));
        self::assertSame($request, $received);

        AblyBroadcaster::resolveGuestClientIdUsing(null);
        self::assertStringStartsWith('guest:', $this->guestClientIdFrom($broadcaster->auth($request)));
    }

    public function testGuestClientIdResolverMustNotReturnEmptyOrWildcard()
    {
        $broadcaster = $this->guestBroadcaster();

        foreach (['', '*', null] as $resolved) {
            AblyBroadcaster::resolveGuestClientIdUsing(function () use ($resolved) {
                return $resolved;
            });
            try {
                $broadcaster->auth($this->getMockRequestWithSession('public:test', null, null, null));
                self::fail('expected UnexpectedValueException');
            } catch (\UnexpectedValueException $e) {
                self::assertStringContainsString('resolveGuestClientIdUsing', $e->getMessage());
            }
        }
    }

    public function testGuestClientIdIsNeverNullOrWildcard()
    {
        $broadcaster = $this->guestBroadcaster();

        for ($i = 0; $i < 5; $i++) {
            $clientId = $this->guestClientIdFrom($broadcaster->auth($this->getMockRequestWithSession('public:test', null, null, null)));
            self::assertNotNull($clientId);
            self::assertNotSame('*', $clientId);
            self::assertNotSame('', $clientId);
        }
    }

    public function testUserIdWithGuestPrefixIsSignedAsIs()
    {
        $broadcaster = $this->guestBroadcaster();

        $response = $broadcaster->auth($this->getMockRequestWithSession('public:test', null, $this->mockUser('guest:1'), $this->newSession()));

        self::assertEquals('guest:1', $this->guestClientIdFrom($response));
    }

    public function testUserIdWithGuestPrefixIsNotReusedAsGuestIdAfterLogout()
    {
        $broadcaster = $this->guestBroadcaster();
        $userToken = $broadcaster->getSignedToken(null, null, 'guest:abc', $this->guardedChannelCapability);

        // a stateless request after logout presents the user's still-valid token: its id has no valid tag
        $clientId = $this->guestClientIdFrom($broadcaster->auth($this->getMockRequestWithSession('public:test', $userToken, null, null)));

        self::assertMatchesRegularExpression(self::GUEST_ID_PATTERN, $clientId);
        self::assertNotSame('guest:abc', $clientId);
    }

    public function testGuestIdPrefixIsConfigurable()
    {
        foreach (['visitor-' => '/^visitor-[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{22}$/', '' => '/^[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{22}$/'] as $prefix => $pattern) {
            $broadcaster = m::mock(AblyBroadcaster::class, [$this->ably, ['mau_account' => true, 'guest_id_prefix' => $prefix]])->makePartial();

            $firstResponse = $broadcaster->auth($this->getMockRequestWithSession('public:test', null, null, null));
            $first = $this->guestClientIdFrom($firstResponse);
            self::assertMatchesRegularExpression($pattern, $first);

            // the tagged id is recognised and reused on a stateless request, whatever the prefix
            $second = $this->guestClientIdFrom($broadcaster->auth($this->getMockRequestWithSession('public:test', $firstResponse['token'], null, null)));
            self::assertSame($first, $second);
        }
    }

    public function testGuestIdIssuedUnderAnotherPrefixIsNotReused()
    {
        $visit = m::mock(AblyBroadcaster::class, [$this->ably, ['mau_account' => true, 'guest_id_prefix' => 'visit:']])->makePartial();
        $visitResponse = $visit->auth($this->getMockRequestWithSession('public:test', null, null, null));
        $visitId = $this->guestClientIdFrom($visitResponse);
        self::assertStringStartsWith('visit:', $visitId);

        // same key, so the tag verifies, but the prefix differs: a session-less request gets a new id
        $clientId = $this->guestClientIdFrom($this->guestBroadcaster()->auth($this->getMockRequestWithSession('public:test', $visitResponse['token'], null, null)));

        self::assertMatchesRegularExpression(self::GUEST_ID_PATTERN, $clientId);
        self::assertNotSame($visitId, $clientId);
    }

    public function testGuestIdTagMatchesAFixedVector()
    {
        // HMAC-SHA256('efgh', 'ably-guest:' . random), first 16 bytes, base64url without padding, computed independently
        $id = 'guest:AAAAAAAAAAAAAAAAAAAAAA.Frvly-6FIwOMDXRwx1ErFw';
        $now = time();
        $token = $this->signToken(['public:*' => ['subscribe']], $id, $now - 10, $now + 1000);

        $clientId = $this->guestClientIdFrom($this->guestBroadcaster()->auth($this->getMockRequestWithSession('public:test', $token, null, null)));

        self::assertSame($id, $clientId);
    }

    public function testGuestIdPrefixIsNotUsedWhenMauAccountIsOff()
    {
        $broadcaster = m::mock(AblyBroadcaster::class, [$this->ably, ['guest_id_prefix' => 'visitor-']])->makePartial();

        $response = $broadcaster->auth($this->getMockRequestWithoutUserForChannel('public:test', null));

        self::assertNull($this->guestClientIdFrom($response));
    }

    public function testGuestStillGetsAccessDeniedOnGuardedChannelsWhenGuestClientIdIsOn()
    {
        $broadcaster = $this->guestBroadcaster();
        $broadcaster->channel('test', function () {
            return true;
        });

        foreach (['private:test', 'presence:test'] as $channel) {
            try {
                $broadcaster->auth($this->getMockRequestWithSession($channel, null, null, $this->newSession()));
                self::fail('expected AccessDeniedHttpException');
            } catch (AccessDeniedHttpException $e) {
                self::assertStringStartsWith('User not authenticated', $e->getMessage());
            }
        }
    }

    /**
     * @return \Ably\LaravelBroadcaster\AblyBroadcaster
     */
    protected function guestBroadcaster()
    {
        return m::mock(AblyBroadcaster::class, [$this->ably, ['mau_account' => true]])->makePartial();
    }

    protected function newSession()
    {
        return new Store('test', new ArraySessionHandler(10));
    }

    protected function guestClientIdFrom($response)
    {
        return Utils::parseJwt($response['token'])['payload']['x-ably-clientId'];
    }

    protected function mockUser($id)
    {
        $user = m::mock('User');
        $user->shouldReceive('getAuthIdentifierForBroadcasting')->andReturn($id);
        $user->shouldReceive('getAuthIdentifier')->andReturn($id);

        return $user;
    }

    /**
     * @param  string  $channel
     * @param  string|null  $token
     * @param  object|null  $user
     * @param  \Illuminate\Session\Store|null  $session  null means the request has no session
     * @return \Illuminate\Http\Request
     */
    protected function getMockRequestWithSession($channel, $token, $user, $session)
    {
        $request = m::mock(Request::class);
        $request->channel_name = $channel;
        $request->token = $token;
        $request->socket_id = 'abcd.1234';

        $request->shouldReceive('user')->andReturn($user);
        $request->shouldReceive('hasSession')->andReturn($session !== null);
        $request->shouldReceive('session')->andReturn($session);

        return $request;
    }

    /**
     * Signs a token with the test key, with full control over its claims.
     *
     * @param  array  $capability
     * @param  string|null  $clientId
     * @param  int  $iat
     * @param  int  $exp
     * @param  string  $secret
     * @return string
     */
    protected function signToken($capability, $clientId, $iat, $exp, $secret = 'efgh')
    {
        return Utils::generateJwt(
            ['typ' => 'JWT', 'alg' => 'HS256', 'kid' => 'abcd'],
            [
                'iat' => $iat,
                'exp' => $exp,
                'x-ably-clientId' => $clientId,
                'x-ably-capability' => json_encode($capability),
            ],
            $secret
        );
    }

    /**
     * @param  string  $channel
     * @return \Illuminate\Http\Request
     */
    protected function getMockRequestWithUserForChannel($channel, $token, $userId = 42)
    {
        $request = m::mock(Request::class);
        $request->channel_name = $channel;
        $request->token = $token;
        $request->socket_id = 'abcd.1234';

        $request->shouldReceive('input')
                ->with('callback', false)
                ->andReturn(false);

        $user = m::mock('User');
        $user->shouldReceive('getAuthIdentifierForBroadcasting')
             ->andReturn($userId);
        $user->shouldReceive('getAuthIdentifier')
             ->andReturn($userId);

        $request->shouldReceive('user')
                ->andReturn($user);

        return $request;
    }

    /**
     * @param  string  $channel
     * @return \Illuminate\Http\Request
     */
    protected function getMockRequestWithoutUserForChannel($channel, $token)
    {
        $request = m::mock(Request::class);
        $request->channel_name = $channel;
        $request->token = $token;
        $request->socket_id = 'abcd.1234';

        $request->shouldReceive('user')
                ->andReturn(null);

        return $request;
    }

    public function testLaravelAblyAgentHeader()
    {
        $ably = (new AblyFactory())->make([
            'key' => 'abcd:efgh',
            'httpClass' => 'Ably\LaravelBroadcaster\Tests\HttpMock',
        ]);
        $ably->time();
        $expectedLaravelHeader = 'ably-pubsub-php/'.\Ably\PubSub\Defaults::LIB_VERSION.' '.'php/'.Miscellaneous::getNumeric(phpversion()).' laravel-broadcaster/'.AblyBroadcaster::LIB_VERSION.' '.\Ably\PubSub\Server::SERVER_AGENT_IDENTIFIER;
        $this->assertcontains( 'Ably-Agent: '.$expectedLaravelHeader, $ably->http->lastHeaders, 'Expected Laravel broadcaster header in HTTP request '.json_encode($ably->http->lastHeaders));
    }

    public function testPublishPayloadShouldNotIncludeSocketKey()
    {
        $ably = (new AblyFactory())->make([
            'key' => 'abcd:efgh',
            'httpClass' => 'Ably\LaravelBroadcaster\Tests\HttpMock',
        ]);
        $broadcaster = m::mock(AblyBroadcasterExposed::class, [$ably, []])->makePartial();

        $socketIdObject = new \stdClass();
        $socketIdObject->connectionKey = 'foo';
        $socketIdObject->clientId = 'sacOO7';
        $payload = [
            'foo' => 'bar',
            'socket' => Utils::base64urlEncode(json_encode($socketIdObject))
        ];

        $broadcaster->broadcast(["channel1", "channel2"], 'testEvent', $payload);

        self::assertCount(2, $broadcaster->payloads);
        foreach ($broadcaster->payloads as $payload) {
            self::assertArrayNotHasKey('socket', $payload);
        }
    }

    public function testBuildMessageBasedOnSocketIdObject()
    {
        $broadcaster = m::mock(AblyBroadcasterExposed::class, [$this->ably, []])->makePartial();
        $payload = [
            'foo' => 'bar',
            'chat' => 'hello there'
        ];
        $message = $broadcaster->buildAblyMessage('testEvent', $payload);
        self::assertEquals('testEvent', $message->name);
        self::assertEquals($payload, $message->data);
        self::assertNull($message->connectionKey);
        self::assertNull($message->clientId);

        $socketIdObject = new \stdClass();
        $socketIdObject->connectionKey = 'foo';
        $socketIdObject->clientId = 'sacOO7';

        $message = $broadcaster->buildAblyMessage('testEvent', $payload, $socketIdObject);
        self::assertEquals('testEvent', $message->name);
        self::assertEquals($payload, $message->data);
        self::assertEquals('foo', $message->connectionKey);
        self::assertEquals('sacOO7', $message->clientId);
    }
}

class AblyBroadcasterExposed extends AblyBroadcaster
{
    public $payloads = [];
    public function buildAblyMessage($event, $payload = [], $socketIdObject = null)
    {
        $this->payloads[] = $payload;
        return parent::buildAblyMessage($event, $payload, $socketIdObject);
    }
}

class HttpMock extends Http
{
    public $lastUrl;
    public $lastHeaders;

    public function request($method, $url, $headers = array(), $params = array())
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;

        // mock response to /time
        return array(
            'headers' => "HTTP/1.1 200 OK\n",
            'body' => array(round(microtime(true) * 1000 ), 0),
        );
    }
}
