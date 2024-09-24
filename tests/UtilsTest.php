<?php

namespace Ably\LaravelBroadcaster\Tests;

use Ably\Exceptions\AblyException;
use Ably\LaravelBroadcaster\Utils;

class UtilsTest extends TestCase
{
    public function testGenerateAndValidateToken()
    {
        $headers = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = ['sub' => '1234567890', 'name' => 'John Doe', 'admin' => true, 'exp' => (time() + 60)];
        $jwtToken = Utils::generateJwt($headers, $payload, 'efgh');

        $parsedJwt = Utils::parseJwt($jwtToken);
        self::assertEquals('HS256', $parsedJwt['header']['alg']);
        self::assertEquals('JWT', $parsedJwt['header']['typ']);

        self::assertEquals('1234567890', $parsedJwt['payload']['sub']);
        self::assertEquals('John Doe', $parsedJwt['payload']['name']);
        self::assertEquals(true, $parsedJwt['payload']['admin']);

        $timeFn = function () {
            return time();
        };
        $jwtIsValid = Utils::isJwtValid($jwtToken, $timeFn, 'efgh');
        self::assertTrue($jwtIsValid);
    }

    /**
     * @throws AblyException
     */
    public function testDecodingSocketId() {
        $socketId = new \stdClass();
        $socketId->connectionKey = 'key';
        $socketId->clientId = null;
        $socketIdObject = Utils::decodeSocketId(Utils::base64url_encode(json_encode($socketId)));
        self::assertEquals('key', $socketIdObject->connectionKey);
        self::assertNull($socketIdObject->clientId);

        $socketId = new \stdClass();
        $socketId->connectionKey = 'key';
        $socketId->clientId = 'id';
        $socketIdObject = Utils::decodeSocketId(Utils::base64url_encode(json_encode($socketId)));
        self::assertEquals('key', $socketIdObject->connectionKey);
        self::assertEquals('id', $socketIdObject->clientId);
    }

    public function testExceptionOnDecodingInvalidSocketId()
    {
        self::expectException(AblyException::class);
        self::expectExceptionMessage("SocketId decoding failed, ".Utils::SOCKET_ID_ERROR);
        Utils::decodeSocketId("invalid_socket_id");
    }

    public function testExceptionOnMissingClientIdInSocketId()
    {
        $socketId = new \stdClass();
        $socketId->connectionKey = 'key';

        self::expectException(AblyException::class);
        self::expectExceptionMessage("ClientId is missing, ".Utils::SOCKET_ID_ERROR);
        Utils::decodeSocketId(Utils::base64url_encode(json_encode($socketId)));
    }

    public function testExceptionOnMissingConnectionKeyInSocketId()
    {
        $socketId = new \stdClass();
        $socketId->clientId = 'id';

        self::expectException(AblyException::class);
        self::expectExceptionMessage("ConnectionKey is not set, ".Utils::SOCKET_ID_ERROR);
        Utils::decodeSocketId(Utils::base64url_encode(json_encode($socketId)));
    }
}
