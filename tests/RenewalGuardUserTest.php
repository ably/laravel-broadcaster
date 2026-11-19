<?php

namespace Ably\LaravelBroadcaster\Tests;

use Ably\LaravelBroadcaster\AblyBroadcaster;
use Ably\LaravelBroadcaster\Utils;
use Ably\PubSub\PubSubHttpClient;
use Illuminate\Http\Request;
use Mockery as m;

class RenewalGuardUserTest extends TestCase
{
    public $ably;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ably = m::mock(PubSubHttpClient::class, ['abcd:efgh']);
        $this->ably->shouldReceive('time')->zeroOrMoreTimes()->andReturn(time() * 1000);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        m::close();
    }

    public function testRenewalKeepsPrivateAndPresenceChannelsForIntegerUserId()
    {
        $broadcaster = new AblyBroadcaster($this->ably, []);
        $broadcaster->channel('keep', function () {
            return true;
        });
        $broadcaster->channel('room', function () {
            return ['id' => 1];
        });
        $now = time();
        $token = $this->signToken(
            ['public:*' => ['subscribe'], 'private:keep' => ['*'], 'presence:room' => ['*']],
            '42',
            $now - 1000,
            $now + 1000
        );

        $response = $broadcaster->auth($this->requestFor(null, $token, [null => $this->user(42)]));

        self::assertSame(
            '{"public:*":["subscribe","history","channel-metadata"],"private:keep":["*"],"presence:room":["*"]}',
            Utils::parseJwt($response['token'])['payload']['x-ably-capability']
        );
    }

    public function testRenewalKeepsPrivateAndPresenceChannelsForStringUserId()
    {
        $broadcaster = new AblyBroadcaster($this->ably, []);
        $broadcaster->channel('keep', function () {
            return true;
        });
        $broadcaster->channel('room', function () {
            return ['id' => 1];
        });
        $now = time();
        $token = $this->signToken(
            ['public:*' => ['subscribe'], 'private:keep' => ['*'], 'presence:room' => ['*']],
            'user123',
            $now - 1000,
            $now + 1000
        );

        $response = $broadcaster->auth($this->requestFor(null, $token, ['' => $this->user('user123')]));

        self::assertSame(
            '{"public:*":["subscribe","history","channel-metadata"],"private:keep":["*"],"presence:room":["*"]}',
            Utils::parseJwt($response['token'])['payload']['x-ably-capability']
        );
    }

    public function testRenewalStillHonoursCallbackReturnedCapability()
    {
        $broadcaster = new AblyBroadcaster($this->ably, []);
        $broadcaster->channel('custom', function () {
            return ['ably-capability' => ['subscribe', 'history'], 'id' => 1];
        });
        $now = time();
        $token = $this->signToken(['public:*' => ['subscribe'], 'private:custom' => ['*']], '42', $now - 1000, $now + 1000);

        $response = $broadcaster->auth($this->requestFor(null, $token, ['' => $this->user(42)]));

        self::assertSame(
            '{"public:*":["subscribe","history","channel-metadata"],"private:custom":["subscribe","history"]}',
            Utils::parseJwt($response['token'])['payload']['x-ably-capability']
        );
    }

    public function testRenewalDropsGuardedChannelWhenItsGuardResolvesToAnotherUser()
    {
        $broadcaster = $this->guardedBroadcaster();
        $now = time();
        $token = $this->tokenWithAdminChannel('A', $now);

        $request = $this->requestFor(null, $token, [null => $this->user('A'), 'web' => $this->user('A'), 'admin' => $this->user('B')]);
        $response = $broadcaster->auth($request);

        self::assertSame(
            '{"public:*":["subscribe","history","channel-metadata"],"private:keep":["*"]}',
            Utils::parseJwt($response['token'])['payload']['x-ably-capability']
        );
    }

    public function testRenewalKeepsGuardedChannelWhenItsGuardResolvesToTheSameUser()
    {
        $broadcaster = $this->guardedBroadcaster();
        $now = time();
        $token = $this->tokenWithAdminChannel('A', $now);

        $request = $this->requestFor(null, $token, [null => $this->user('A'), 'web' => $this->user('A'), 'admin' => $this->user('A')]);
        $response = $broadcaster->auth($request);

        self::assertSame(
            '{"public:*":["subscribe","history","channel-metadata"],"private:keep":["*"],"private:admin.x":["*"]}',
            Utils::parseJwt($response['token'])['payload']['x-ably-capability']
        );
    }

    public function testRenewalDropsGuardedChannelWhenItsGuardHasNoUser()
    {
        $broadcaster = $this->guardedBroadcaster();
        $now = time();
        $token = $this->tokenWithAdminChannel('A', $now);

        $request = $this->requestFor(null, $token, [null => $this->user('A'), 'web' => $this->user('A'), 'admin' => null]);
        $response = $broadcaster->auth($request);

        self::assertSame(
            '{"public:*":["subscribe","history","channel-metadata"],"private:keep":["*"]}',
            Utils::parseJwt($response['token'])['payload']['x-ably-capability']
        );
    }

    protected function guardedBroadcaster()
    {
        $broadcaster = new AblyBroadcaster($this->ably, []);
        $broadcaster->channel('keep', function () {
            return true;
        });
        $broadcaster->channel('admin.x', function () {
            return true;
        }, ['guards' => ['admin']]);

        return $broadcaster;
    }

    protected function tokenWithAdminChannel($clientId, $now)
    {
        return $this->signToken(
            ['public:*' => ['subscribe'], 'private:keep' => ['*'], 'private:admin.x' => ['*']],
            $clientId,
            $now - 1000,
            $now + 1000
        );
    }

    protected function user($id)
    {
        $user = m::mock('User');
        $user->shouldReceive('getAuthIdentifierForBroadcasting')->andReturn($id);
        $user->shouldReceive('getAuthIdentifier')->andReturn($id);

        return $user;
    }

    /**
     * @param  array  $users  guard name (null = default guard) => user or null
     */
    protected function requestFor($channel, $token, array $users)
    {
        $request = m::mock(Request::class);
        $request->channel_name = $channel;
        $request->token = $token;
        $request->socket_id = 'abcd.1234';
        foreach ($users as $guard => $user) {
            if ($guard === '') {
                $request->shouldReceive('user')->withNoArgs()->andReturn($user);
            } else {
                $request->shouldReceive('user')->with($guard)->andReturn($user);
            }
        }

        return $request;
    }

    protected function signToken($capability, $clientId, $iat, $exp)
    {
        return Utils::generateJwt(
            ['typ' => 'JWT', 'alg' => 'HS256', 'kid' => 'abcd'],
            [
                'iat' => $iat,
                'exp' => $exp,
                'x-ably-clientId' => $clientId,
                'x-ably-capability' => json_encode($capability),
            ],
            'efgh'
        );
    }
}
