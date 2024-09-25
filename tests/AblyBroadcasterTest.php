<?php

namespace Ably\LaravelBroadcaster\Tests;

use Ably\AblyRest;
use Ably\Http;
use Ably\LaravelBroadcaster\AblyBroadcaster;
use Ably\LaravelBroadcaster\Utils;
use Ably\Utils\Miscellaneous;
use Illuminate\Http\Request;
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

        $this->ably = m::mock(AblyRest::class, ['abcd:efgh']);

        $this->ably->shouldReceive('time')
                   ->zeroOrMoreTimes()
                   ->andReturn(time() * 1000); // TODO - make this call at runtime

        $this->broadcaster = m::mock(AblyBroadcaster::class, [$this->ably, []])->makePartial();
    }

    protected function tearDown(): void
    {
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

    public function testShouldHaveUpgradedCapabilitiesForValidToken()
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

        $token = $this->broadcaster->getSignedToken('private:channel3', $token, 'user98', $this->guardedChannelCapability);
        $parsedToken = Utils::parseJwt($token);
        $payload = $parsedToken['payload'];
        $expectedCapability = '{"public:*":["subscribe","history","channel-metadata"],"private:channel":["*"],"private:channel2":["*"],"private:channel3":["*"]}';
        self::assertEquals('user98', $payload['x-ably-clientId']);
        self::assertEquals($expectedCapability, $payload['x-ably-capability']);
        self::assertEquals($iat, $payload['iat']);
        self::assertEquals($exp, $payload['exp']);
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
     * @param  string  $channel
     * @return \Illuminate\Http\Request
     */
    protected function getMockRequestWithUserForChannel($channel, $token)
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
             ->andReturn(42);
        $user->shouldReceive('getAuthIdentifier')
             ->andReturn(42);

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
        $laravelVersion = Miscellaneous::getNumeric(app()->version());
        $expectedLaravelHeader = 'ably-php/'.\Ably\Defaults::LIB_VERSION.' '.'php/'.Miscellaneous::getNumeric(phpversion()).' laravel-broadcaster/'.AblyBroadcaster::LIB_VERSION.' laravel/'.$laravelVersion;
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
