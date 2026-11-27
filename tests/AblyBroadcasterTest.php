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

    public function testShouldResetClaimsWhenIdentityGoesFromUserToNoClientId()
    {
        $now = time();
        $token = $this->signToken(['public:*' => ['subscribe'], 'private:secret' => ['*']], 'user123', $now - 1000, $now + 1000);

        $token = $this->broadcaster->getSignedToken(null, $token, null, $this->guardedChannelCapability);
        $payload = Utils::parseJwt($token)['payload'];
        self::assertNull($payload['x-ably-clientId']);
        self::assertEquals('{"public:*":["subscribe","history","channel-metadata"]}', $payload['x-ably-capability']);
        self::assertGreaterThanOrEqual($now, $payload['iat']);
    }

    public function testShouldRefreshExpiryWhenIdentityStaysNullOnRenewal()
    {
        $now = time();
        $token = $this->signToken(['public:*' => ['subscribe']], null, $now - 1000, $now + 1000);

        $token = $this->broadcaster->getSignedToken(null, $token, null, $this->guardedChannelCapability, []);
        $payload = Utils::parseJwt($token)['payload'];
        self::assertEquals('{"public:*":["subscribe","history","channel-metadata"]}', $payload['x-ably-capability']);
        self::assertGreaterThanOrEqual($now, $payload['iat']);
        self::assertEquals($payload['iat'] + 28800, $payload['exp']);
    }

    public function testRenewalIssuesFreshIatAndExp()
    {
        $now = time();
        $token = $this->signToken(['public:*' => ['subscribe']], 'user123', $now - 1000, $now + 1000);

        $response = $this->broadcaster->auth($this->getMockRequestWithUserForChannel(null, $token, 'user123'));
        $payload = Utils::parseJwt($response['token'])['payload'];
        self::assertGreaterThanOrEqual($now, $payload['iat']);
        self::assertGreaterThan($now + 1000, $payload['exp']);
        self::assertEquals($payload['iat'] + 28800, $payload['exp']);
        self::assertEquals('user123', $payload['x-ably-clientId']);
    }

    public function testRenewalKeepsAuthorisedGuardedChannelsAndDropsRevokedOnes()
    {
        $this->broadcaster->channel('keep', function () {
            return true;
        });
        $this->broadcaster->channel('room', function () {
            return ['id' => 1, 'name' => 'info is not kept'];
        });
        $this->broadcaster->channel('revoked', function () {
            return false;
        });
        $this->broadcaster->channel('boom', function () {
            throw new \RuntimeException('callback failure');
        });
        $now = time();
        $token = $this->signToken([
            'public:*' => ['subscribe'],
            'private:keep' => ['*'],
            'presence:room' => ['*'],
            'private:revoked' => ['*'],
            'private:boom' => ['*'],
        ], 'user123', $now - 1000, $now + 1000);

        $response = $this->broadcaster->auth($this->getMockRequestWithUserForChannel(null, $token, 'user123'));
        $payload = Utils::parseJwt($response['token'])['payload'];
        self::assertEquals(
            '{"public:*":["subscribe","history","channel-metadata"],"private:keep":["*"],"presence:room":["*"]}',
            $payload['x-ably-capability']
        );
        self::assertGreaterThan($now + 1000, $payload['exp']);
        self::assertArrayNotHasKey('info', $response);
    }

    public function testRenewalHonoursCallbackReturnedCapability()
    {
        $this->broadcaster->channel('custom', function () {
            return ['ably-capability' => ['subscribe', 'history'], 'id' => 1];
        });
        $now = time();
        $token = $this->signToken(['public:*' => ['subscribe'], 'private:custom' => ['*']], 'user123', $now - 1000, $now + 1000);

        $response = $this->broadcaster->auth($this->getMockRequestWithUserForChannel(null, $token, 'user123'));
        $payload = Utils::parseJwt($response['token'])['payload'];
        self::assertEquals(
            '{"public:*":["subscribe","history","channel-metadata"],"private:custom":["subscribe","history"]}',
            $payload['x-ably-capability']
        );
    }

    public function testGuestRenewalRefreshesPublicOnlyClaims()
    {
        $broadcaster = $this->guestBroadcaster();
        $session = $this->newSession();
        $now = time();
        $guestId = $this->guestClientIdFrom($broadcaster->auth($this->getMockRequestWithSession(null, null, null, $session)));
        $token = $this->signToken(['public:*' => ['subscribe'], 'private:secret' => ['*']], $guestId, $now - 1000, $now + 1000);

        $response = $broadcaster->auth($this->getMockRequestWithSession(null, $token, null, $session));
        $payload = Utils::parseJwt($response['token'])['payload'];
        self::assertEquals($guestId, $payload['x-ably-clientId']);
        self::assertEquals('{"public:*":["subscribe","history","channel-metadata"]}', $payload['x-ably-capability']);
        self::assertGreaterThan($now + 1000, $payload['exp']);
    }

    public function testRenewalForDifferentClientIdResetsClaims()
    {
        $this->broadcaster->channel('keep', function () {
            return true;
        });
        $now = time();
        $token = $this->signToken(['public:*' => ['subscribe'], 'private:keep' => ['*']], 'user123', $now - 1000, $now + 1000);

        $response = $this->broadcaster->auth($this->getMockRequestWithUserForChannel(null, $token, 'user98'));
        $payload = Utils::parseJwt($response['token'])['payload'];
        self::assertEquals('user98', $payload['x-ably-clientId']);
        self::assertEquals('{"public:*":["subscribe","history","channel-metadata"]}', $payload['x-ably-capability']);
    }

    public function testRenewalWithForgedSignatureResetsClaims()
    {
        $this->broadcaster->channel('keep', function () {
            return true;
        });
        $now = time();
        $token = $this->signToken(['public:*' => ['subscribe'], 'private:keep' => ['*']], 'user123', $now - 1000, $now + 1000, 'wrong-secret');

        $response = $this->broadcaster->auth($this->getMockRequestWithUserForChannel(null, $token, 'user123'));
        self::assertEquals($this->publicOnlyCapability(), Utils::parseJwt($response['token'])['payload']['x-ably-capability']);
    }

    public function testRenewalWithAlgNoneHeaderResetsClaims()
    {
        $this->broadcaster->channel('keep', function () {
            return true;
        });
        $now = time();
        $claims = [
            'iat' => $now - 1000,
            'exp' => $now + 1000,
            'x-ably-clientId' => 'user123',
            'x-ably-capability' => json_encode(['private:keep' => ['*']]),
        ];
        $unsigned = Utils::base64urlEncode(json_encode(['typ' => 'JWT', 'alg' => 'none'])).'.'.Utils::base64urlEncode(json_encode($claims)).'.';

        $response = $this->broadcaster->auth($this->getMockRequestWithUserForChannel(null, $unsigned, 'user123'));
        self::assertEquals($this->publicOnlyCapability(), Utils::parseJwt($response['token'])['payload']['x-ably-capability']);
    }

    public function testRenewalWithExpiredTokenResetsClaims()
    {
        $this->broadcaster->channel('keep', function () {
            return true;
        });
        $now = time();
        $token = $this->signToken(['public:*' => ['subscribe'], 'private:keep' => ['*']], 'user123', $now - 2000, $now - 1000);

        $response = $this->broadcaster->auth($this->getMockRequestWithUserForChannel(null, $token, 'user123'));
        $payload = Utils::parseJwt($response['token'])['payload'];
        self::assertEquals($this->publicOnlyCapability(), $payload['x-ably-capability']);
        self::assertGreaterThan($now, $payload['exp']);
    }

    public function testRenewalWithNonHs256AlgHeaderResetsClaims()
    {
        $this->broadcaster->channel('keep', function () {
            return true;
        });
        $now = time();
        $claims = [
            'iat' => $now - 1000,
            'exp' => $now + 1000,
            'x-ably-clientId' => 'user123',
            'x-ably-capability' => json_encode(['public:*' => ['subscribe'], 'private:keep' => ['*']]),
        ];
        foreach (['none', 'RS256'] as $alg) {
            // correctly HMAC-signed with our key, but not the algorithm this package issues
            $token = Utils::generateJwt(['typ' => 'JWT', 'alg' => $alg, 'kid' => 'abcd'], $claims, 'efgh');
            $response = $this->broadcaster->auth($this->getMockRequestWithUserForChannel(null, $token, 'user123'));
            self::assertEquals($this->publicOnlyCapability(), Utils::parseJwt($response['token'])['payload']['x-ably-capability'], $alg);
        }
    }

    public function testRenewalDropsEscalatedPresentedCapability()
    {
        // `private:secret` has no callback, `private:*` and `*` do not match any channel pattern
        $this->broadcaster->channel('mine', function () {
            return false;
        });
        $now = time();
        $token = $this->signToken([
            'public:*' => ['publish'],
            'private:secret' => ['publish'],
            'private:*' => ['publish'],
            'private:mine' => ['publish'],
            '*' => ['publish'],
        ], 'user123', $now - 1000, $now + 1000);

        $response = $this->broadcaster->auth($this->getMockRequestWithUserForChannel(null, $token, 'user123'));
        self::assertEquals($this->publicOnlyCapability(), Utils::parseJwt($response['token'])['payload']['x-ably-capability']);
    }

    public function testRenewalIgnoresPresentedOperations()
    {
        $this->broadcaster->channel('a', function () {
            return true;
        });
        $now = time();
        $token = $this->signToken(['public:*' => ['subscribe'], 'private:a' => ['publish']], 'user123', $now - 1000, $now + 1000);

        $response = $this->broadcaster->auth($this->getMockRequestWithUserForChannel(null, $token, 'user123'));
        $capability = json_decode(Utils::parseJwt($response['token'])['payload']['x-ably-capability'], true);
        self::assertEquals(['*'], $capability['private:a']);
    }

    public function testRenewalDropsNonGuardedInjectedKeys()
    {
        $now = time();
        $token = $this->signToken(['public:*' => ['publish'], 'foo' => ['publish']], 'user123', $now - 1000, $now + 1000);

        $response = $this->broadcaster->auth($this->getMockRequestWithUserForChannel(null, $token, 'user123'));
        self::assertEquals($this->publicOnlyCapability(), Utils::parseJwt($response['token'])['payload']['x-ably-capability']);
    }

    public function testRenewalCallbackReturningFalseOrNullDropsChannel()
    {
        $this->broadcaster->channel('no', function () {
            return false;
        });
        $this->broadcaster->channel('nothing', function () {
            return null;
        });
        $now = time();
        $token = $this->signToken(['public:*' => ['subscribe'], 'private:no' => ['*'], 'presence:nothing' => ['*']], 'user123', $now - 1000, $now + 1000);

        $response = $this->broadcaster->auth($this->getMockRequestWithUserForChannel(null, $token, 'user123'));
        self::assertEquals($this->publicOnlyCapability(), Utils::parseJwt($response['token'])['payload']['x-ably-capability']);
    }

    public function testRenewalCallbackThrowingDropsChannel()
    {
        $this->broadcaster->channel('exception', function () {
            throw new \RuntimeException('callback failure');
        });
        $this->broadcaster->channel('error', function () {
            throw new \Error('callback error');
        });
        $this->broadcaster->channel('keep', function () {
            return true;
        });
        $now = time();
        $token = $this->signToken([
            'public:*' => ['subscribe'],
            'private:exception' => ['*'],
            'private:error' => ['*'],
            'private:keep' => ['*'],
        ], 'user123', $now - 1000, $now + 1000);

        $response = $this->broadcaster->auth($this->getMockRequestWithUserForChannel(null, $token, 'user123'));
        $capability = json_decode(Utils::parseJwt($response['token'])['payload']['x-ably-capability'], true);
        self::assertEquals(['public:*', 'private:keep'], array_keys($capability));
    }

    public function testRenewalMalformedTokenDoesNotError()
    {
        foreach (['abc', 'a.b', 'a.b.c', 'a.b.c.d', '..', Utils::base64urlEncode('[]').'.'.Utils::base64urlEncode('[]').'.x'] as $malformed) {
            $response = $this->broadcaster->auth($this->getMockRequestWithUserForChannel(null, $malformed, 'user123'));
            $payload = Utils::parseJwt($response['token'])['payload'];
            self::assertEquals($this->publicOnlyCapability(), $payload['x-ably-capability'], $malformed);
            self::assertEquals('user123', $payload['x-ably-clientId']);
        }
    }

    public function testRenewalNonStringTokenDoesNotError()
    {
        foreach ([['x'], ['a' => ['b']], 123, true] as $nonString) {
            $response = $this->broadcaster->auth($this->getMockRequestWithUserForChannel(null, $nonString, 'user123'));
            $payload = Utils::parseJwt($response['token'])['payload'];
            self::assertEquals($this->publicOnlyCapability(), $payload['x-ably-capability']);
        }
    }

    public function testRenewalKeepsZeroIdForGuestAndUser()
    {
        $now = time();
        $broadcaster = $this->broadcaster;
        AblyBroadcaster::resolveGuestClientIdUsing(function () {
            return '0';
        });
        $token = $this->signToken(['public:*' => ['subscribe']], '0', $now - 1000, $now + 1000);
        $response = $broadcaster->auth($this->getMockRequestWithSession(null, $token, null, $this->newSession()));
        $payload = Utils::parseJwt($response['token'])['payload'];
        self::assertSame('0', $payload['x-ably-clientId']);
        self::assertGreaterThan($now + 1000, $payload['exp']);

        $this->broadcaster->channel('keep', function () {
            return true;
        });
        foreach ([0, '0'] as $id) {
            $token = $this->signToken(['public:*' => ['subscribe'], 'private:keep' => ['*']], '0', $now - 1000, $now + 1000);
            $response = $this->broadcaster->auth($this->getMockRequestWithUserForChannel(null, $token, $id));
            $payload = Utils::parseJwt($response['token'])['payload'];
            self::assertSame('0', $payload['x-ably-clientId']);
            self::assertGreaterThan($now + 1000, $payload['exp']);
            self::assertEquals(['public:*', 'private:keep'], array_keys(json_decode($payload['x-ably-capability'], true)));
        }
    }

    public function testLegacyTokenWithoutClientIdRenewsAsTheNewIdentity()
    {
        // A token issued by 1.x to a guest has no clientId. After the upgrade its renewal switches to the new identity:
        // a guest id or the user id, a fresh expiry, and none of the presented private or presence claims.
        $this->broadcaster->channel('keep', function () {
            return true;
        });
        $now = time();
        $legacy = $this->signToken(['public:*' => ['subscribe'], 'private:keep' => ['*']], null, $now - 1000, $now + 1000);

        foreach ([null, $this->mockUser(42)] as $user) {
            foreach ([$this->newSession(), null] as $session) {
                $response = $this->broadcaster->auth($this->getMockRequestWithSession(null, $legacy, $user, $session));
                $payload = Utils::parseJwt($response['token'])['payload'];
                if ($user) {
                    self::assertSame('42', $payload['x-ably-clientId']);
                } else {
                    self::assertMatchesRegularExpression(self::GUEST_ID_PATTERN, $payload['x-ably-clientId']);
                }
                self::assertEquals($this->publicOnlyCapability(), $payload['x-ably-capability']);
                self::assertGreaterThan($now + 1000, $payload['exp']);
            }
        }
    }

    public function testSignedTokenWithNonStringCapabilityIsHandled()
    {
        $now = time();
        $token = Utils::generateJwt(
            ['typ' => 'JWT', 'alg' => 'HS256', 'kid' => 'abcd'],
            ['iat' => $now - 1000, 'exp' => $now + 1000, 'x-ably-clientId' => 'user123', 'x-ably-capability' => ['private:secret' => ['*']]],
            'efgh'
        );

        foreach ([null, 'public:test'] as $channel) { // renewal and reuse paths
            $response = $this->broadcaster->auth($this->getMockRequestWithUserForChannel($channel, $token, 'user123'));
            $payload = Utils::parseJwt($response['token'])['payload'];
            self::assertEquals($this->publicOnlyCapability(), $payload['x-ably-capability']);
            self::assertEquals('user123', $payload['x-ably-clientId']);
        }
    }

    protected function publicOnlyCapability()
    {
        return '{"public:*":["subscribe","history","channel-metadata"]}';
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

    public function testNonStringChannelNameIsDenied()
    {
        foreach ([['private:test'], ['public:test'], [], 42] as $channelName) {
            foreach ([$this->getMockRequestWithUserForChannel($channelName, null), $this->getMockRequestWithoutUserForChannel($channelName, null)] as $request) {
                try {
                    $this->broadcaster->auth($request);
                    self::fail('expected AccessDeniedHttpException');
                } catch (AccessDeniedHttpException $e) {
                    self::assertStringStartsWith('Invalid channel name', $e->getMessage());
                }
            }
        }
    }

    public function testNonStringSocketIdIsIgnoredInErrorMessages()
    {
        $request = $this->getMockRequestWithoutUserForChannel('private:test', null);
        $request->socket_id = ['abcd.1234'];

        try {
            $this->broadcaster->auth($request);
            self::fail('expected AccessDeniedHttpException');
        } catch (AccessDeniedHttpException $e) {
            self::assertSame('User not authenticated, channel-name:private:test ably-connection-id:', $e->getMessage());
        }
    }

    public function testChannelPrefixChecksAreNullSafe()
    {
        // A token renewal has no channel name. Throw on any PHP notice or deprecation, e.g. str_starts_with(null) on Laravel <= 10
        set_error_handler(function ($severity, $message) {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            self::assertFalse($this->broadcaster->isPrivateChannel(null));
            self::assertFalse($this->broadcaster->isPresenceChannel(null));
            self::assertFalse($this->broadcaster->isGuardedChannel(null));
        } finally {
            restore_error_handler();
        }
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

    public function testGuestClientIdIsAlwaysOn()
    {
        $response = $this->broadcaster->auth($this->getMockRequestWithoutUserForChannel('public:test', null));

        $payload = Utils::parseJwt($response['token'])['payload'];
        self::assertMatchesRegularExpression(self::GUEST_ID_PATTERN, $payload['x-ably-clientId']);
    }

    public function testAuthenticatedRequestWithoutSessionDoesNotTouchSession()
    {
        // The mock request has no session() expectation, so touching the session would fail the test
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
        $broadcaster = $this->broadcaster;
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

    public function testGuestClientIdResolverMayReturnZeroString()
    {
        $broadcaster = $this->broadcaster;
        AblyBroadcaster::resolveGuestClientIdUsing(function () {
            return '0';
        });

        self::assertSame('0', $this->guestClientIdFrom($broadcaster->auth($this->getMockRequestWithSession('public:test', null, null, $this->newSession()))));
    }

    public function testUserIdZeroIsSignedAsZeroString()
    {
        $broadcaster = $this->guestBroadcaster();
        $broadcaster->channel('test', function () {
            return true;
        });

        foreach ([0, '0'] as $id) {
            $response = $broadcaster->auth($this->getMockRequestWithSession('private:test', null, $this->mockUser($id), $this->newSession()));
            self::assertSame('0', Utils::parseJwt($response['token'])['payload']['x-ably-clientId']);
        }
    }

    public function testGuestClientIdResolverMustNotReturnEmptyOrWildcard()
    {
        $broadcaster = $this->broadcaster;

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

    public function testGuestClientIdResolverIsCalledOnStatelessRoute()
    {
        $calls = 0;
        AblyBroadcaster::resolveGuestClientIdUsing(function () use (&$calls) {
            $calls++;

            return 'api-guest-7';
        });

        // no session: the resolver still decides the id, and the built-in token reuse is not used
        $first = $this->broadcaster->auth($this->getMockRequestWithSession('public:test', null, null, null));
        $second = $this->broadcaster->auth($this->getMockRequestWithSession('public:test', $first['token'], null, null));

        self::assertSame('api-guest-7', $this->guestClientIdFrom($first));
        self::assertSame('api-guest-7', $this->guestClientIdFrom($second));
        self::assertSame(2, $calls);
    }

    public function testGuestClientIdResolverIsNeverCalledForAuthenticatedUser()
    {
        AblyBroadcaster::resolveGuestClientIdUsing(function () {
            self::fail('the guest resolver must not be called for a signed-in user');
        });
        $this->broadcaster->channel('test', function () {
            return true;
        });
        $now = time();
        $token = $this->signToken(['public:*' => ['subscribe'], 'private:test' => ['*']], '42', $now - 1000, $now + 1000);

        // public channel, private channel and a connection token renewal (no channel name)
        foreach (['public:test', 'private:test', null] as $channel) {
            $response = $this->broadcaster->auth($this->getMockRequestWithSession($channel, $token, $this->mockUser(42), $this->newSession()));
            self::assertSame('42', $this->guestClientIdFrom($response));
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
            $broadcaster = m::mock(AblyBroadcaster::class, [$this->ably, ['guest_id_prefix' => $prefix]])->makePartial();

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
        $visit = m::mock(AblyBroadcaster::class, [$this->ably, ['guest_id_prefix' => 'visit:']])->makePartial();
        $visitResponse = $visit->auth($this->getMockRequestWithSession('public:test', null, null, null));
        $visitId = $this->guestClientIdFrom($visitResponse);
        self::assertStringStartsWith('visit:', $visitId);

        // same key, so the tag verifies, but the prefix differs: a session-less request gets a new id
        $clientId = $this->guestClientIdFrom($this->guestBroadcaster()->auth($this->getMockRequestWithSession('public:test', $visitResponse['token'], null, null)));

        self::assertMatchesRegularExpression(self::GUEST_ID_PATTERN, $clientId);
        self::assertNotSame($visitId, $clientId);
    }

    public function testGuestIdInSessionIssuedUnderAnotherPrefixIsReplaced()
    {
        $guest = $this->guestBroadcaster();
        $session = $this->newSession();
        $oldId = $this->guestClientIdFrom($guest->auth($this->getMockRequestWithSession('public:test', null, null, $session)));
        self::assertStringStartsWith('guest:', $oldId);

        $visit = m::mock(AblyBroadcaster::class, [$this->ably, ['guest_id_prefix' => 'visit:']])->makePartial();
        $newId = $this->guestClientIdFrom($visit->auth($this->getMockRequestWithSession('public:test', null, null, $session)));

        self::assertStringStartsWith('visit:', $newId);
        self::assertSame($newId, $session->get('ably_guest_client_id'));
        self::assertSame($newId, $this->guestClientIdFrom($visit->auth($this->getMockRequestWithSession('public:other', null, null, $session))));
    }

    public function testGuestIdInSessionSignedWithAnotherKeyIsReplaced()
    {
        $random = str_repeat('a', 22);
        $tag = Utils::base64urlEncode(substr(hash_hmac('sha256', 'ably-guest:'.$random, 'rotated', true), 0, 16));
        $session = $this->newSession();
        $session->put('ably_guest_client_id', 'guest:'.$random.'.'.$tag);

        $clientId = $this->guestClientIdFrom($this->guestBroadcaster()->auth($this->getMockRequestWithSession('public:test', null, null, $session)));

        self::assertMatchesRegularExpression(self::GUEST_ID_PATTERN, $clientId);
        self::assertNotSame('guest:'.$random.'.'.$tag, $clientId);
        self::assertSame($clientId, $session->get('ably_guest_client_id'));
    }

    public function testGuestIdInSessionThatTheBroadcasterDidNotIssueIsReplaced()
    {
        foreach (['user:1', 'guest:abc', 'garbage', 'guest:'.str_repeat('a', 22).'.'.str_repeat('b', 22)] as $stored) {
            $session = $this->newSession();
            $session->put('ably_guest_client_id', $stored);

            $clientId = $this->guestClientIdFrom($this->guestBroadcaster()->auth($this->getMockRequestWithSession('public:test', null, null, $session)));

            self::assertMatchesRegularExpression(self::GUEST_ID_PATTERN, $clientId);
            self::assertNotSame($stored, $clientId);
            self::assertSame($clientId, $session->get('ably_guest_client_id'));
        }
    }

    public function testValidGuestIdInSessionIsReusedUnchanged()
    {
        $broadcaster = $this->guestBroadcaster();
        $session = $this->newSession();
        $first = $this->guestClientIdFrom($broadcaster->auth($this->getMockRequestWithSession('public:test', null, null, $session)));

        // a fresh broadcaster with the same config accepts the id it would have issued
        $second = $this->guestClientIdFrom($this->guestBroadcaster()->auth($this->getMockRequestWithSession('public:test', null, null, $session)));

        self::assertSame($first, $second);
        self::assertSame($first, $session->get('ably_guest_client_id'));
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

    public function testGuestIdPrefixAppliesWithoutAnyOtherGuestConfig()
    {
        $broadcaster = m::mock(AblyBroadcaster::class, [$this->ably, ['guest_id_prefix' => 'visitor-']])->makePartial();

        $response = $broadcaster->auth($this->getMockRequestWithoutUserForChannel('public:test', null));

        self::assertMatchesRegularExpression('/^visitor-[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{22}$/', $this->guestClientIdFrom($response));
    }

    public function testGuestStillGetsAccessDeniedOnGuardedChannels()
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
     * A fresh broadcaster with the default config (guest ids are always on).
     *
     * @return \Ably\LaravelBroadcaster\AblyBroadcaster
     */
    protected function guestBroadcaster()
    {
        return m::mock(AblyBroadcaster::class, [$this->ably, []])->makePartial();
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
        $request->shouldReceive('hasSession')->andReturn(false);

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
        $request->shouldReceive('hasSession')->andReturn(false);

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

    public function testSocketIdWithoutConnectionKeyMeansNoExclusion()
    {
        $ably = (new AblyFactory())->make([
            'key' => 'abcd:efgh',
            'httpClass' => 'Ably\LaravelBroadcaster\Tests\HttpMock',
        ]);
        $broadcaster = m::mock(AblyBroadcasterExposed::class, [$ably, []])->makePartial();

        // {"clientId":null} and {"clientId":"user-1"}: sent by Echo before its connection is up
        foreach (['eyJjbGllbnRJZCI6bnVsbH0', 'eyJjbGllbnRJZCI6InVzZXItMSJ9'] as $socketId) {
            $broadcaster->broadcast(['channel1'], 'testEvent', ['foo' => 'bar', 'socket' => $socketId]);
        }
        self::assertCount(2, $broadcaster->payloads);
        self::assertSame([null, null], $broadcaster->socketIdObjects);

        // anything else without a connectionKey is still malformed
        foreach (['not a socket id', 'aGVsbG8', Utils::base64urlEncode('{}'), Utils::base64urlEncode('[1]'), Utils::base64urlEncode('{"connectionKey":"k"}')] as $socketId) {
            try {
                $broadcaster->broadcast(['channel1'], 'testEvent', ['foo' => 'bar', 'socket' => $socketId]);
                self::fail('expected BroadcastException for socket id '.$socketId);
            } catch (\Illuminate\Broadcasting\BroadcastException $e) {
                self::assertStringStartsWith('Ably error: ', $e->getMessage());
            }
        }
        self::assertCount(2, $broadcaster->payloads);
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
    public $socketIdObjects = [];
    public function buildAblyMessage($event, $payload = [], $socketIdObject = null)
    {
        $this->payloads[] = $payload;
        $this->socketIdObjects[] = $socketIdObject;
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
