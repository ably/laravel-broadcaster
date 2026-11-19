<?php

namespace Ably\LaravelBroadcaster\Tests;

use Ably\LaravelBroadcaster\Utils;
use Ably\PubSub\Exceptions\AblyException;

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
    public function testDecodeSocketId() {
        $socketIdObject = Utils::decodeSocketId(null);
        self::assertNull($socketIdObject);

        $originalSocketIdObj = new \stdClass();
        $originalSocketIdObj->connectionKey = 'key';
        $originalSocketIdObj->clientId = null;
        $socketIdObject = Utils::decodeSocketId(Utils::base64urlEncode(json_encode($originalSocketIdObj)));
        self::assertEquals('key', $socketIdObject->connectionKey);
        self::assertNull($socketIdObject->clientId);

        $originalSocketIdObj = new \stdClass();
        $originalSocketIdObj->connectionKey = 'key';
        $originalSocketIdObj->clientId = 'id';
        $socketIdObject = Utils::decodeSocketId(Utils::base64urlEncode(json_encode($originalSocketIdObj)));
        self::assertEquals('key', $socketIdObject->connectionKey);
        self::assertEquals('id', $socketIdObject->clientId);
    }

    public function testExceptionOnDecodingInvalidSocketId()
    {
        self::expectException(AblyException::class);
        self::expectExceptionMessage("Base64 decoding failed, ".Utils::SOCKET_ID_ERROR);
        Utils::decodeSocketId("invalid_socket_id");
    }

    public function testExceptionOnMissingClientIdInSocketId()
    {
        $socketIdObject = new \stdClass();
        $socketIdObject->connectionKey = 'key';

        self::expectException(AblyException::class);
        self::expectExceptionMessage("ClientId is missing, ".Utils::SOCKET_ID_ERROR);
        Utils::decodeSocketId(Utils::base64urlEncode(json_encode($socketIdObject)));
    }

    public function testExceptionOnMissingConnectionKeyInSocketId()
    {
        $socketIdObject = new \stdClass();
        $socketIdObject->clientId = 'id';

        self::expectException(AblyException::class);
        self::expectExceptionMessage("ConnectionKey is not set, ".Utils::SOCKET_ID_ERROR);
        Utils::decodeSocketId(Utils::base64urlEncode(json_encode($socketIdObject)));
    }

    public function testIsJwtValidReturnsFalseForMalformedInput()
    {
        $timeFn = function () {
            return time();
        };
        $enc = function ($v) {
            return Utils::base64urlEncode(json_encode($v));
        };
        $sign = function ($h, $p) {
            return Utils::base64urlEncode(hash_hmac('SHA256', "$h.$p", 'efgh', true));
        };
        $valid = ['alg' => 'HS256'];
        $cases = [
            'empty' => '',
            'one part' => 'abc',
            'two parts' => 'a.b',
            'three garbage parts' => 'a.b.c',
            'four parts' => 'a.b.c.d',
            'array' => ['x'],
            'null' => null,
        ];
        foreach ([
            'payload not an array' => [$enc($valid), $enc('str')],
            'payload not json' => [$enc($valid), Utils::base64urlEncode('not json')],
            'header not an array' => [$enc('str'), $enc(['exp' => time() + 60])],
            'missing exp' => [$enc($valid), $enc(['sub' => 'x'])],
            'non numeric exp' => [$enc($valid), $enc(['exp' => 'soon'])],
            'array exp' => [$enc($valid), $enc(['exp' => [1]])],
        ] as $name => [$h, $p]) {
            $cases[$name] = "$h.$p.".$sign($h, $p); // correctly signed, but claims are unusable
        }

        foreach ($cases as $name => $jwt) {
            self::assertFalse(Utils::isJwtValid($jwt, $timeFn, 'efgh'), $name);
        }
    }

    public function testIsJwtValidReturnsFalseForWrongSignatureOrExpiry()
    {
        $timeFn = function () {
            return time();
        };
        $headers = ['alg' => 'HS256', 'typ' => 'JWT'];

        $jwt = Utils::generateJwt($headers, ['exp' => time() + 60], 'efgh');
        self::assertTrue(Utils::isJwtValid($jwt, $timeFn, 'efgh'));
        self::assertFalse(Utils::isJwtValid($jwt, $timeFn, 'other'));
        self::assertFalse(Utils::isJwtValid($jwt.'x', $timeFn, 'efgh'));
        self::assertFalse(Utils::isJwtValid(Utils::generateJwt($headers, ['exp' => time() - 1], 'efgh'), $timeFn, 'efgh'));
    }

    public function testIsJwtValidRejectsNonHs256AlgEvenWhenSignedWithTheKey()
    {
        $timeFn = function () {
            return time();
        };
        foreach (['none', 'RS256', null] as $alg) {
            $headers = $alg === null ? ['typ' => 'JWT'] : ['alg' => $alg, 'typ' => 'JWT'];
            $jwt = Utils::generateJwt($headers, ['exp' => time() + 60], 'efgh');
            self::assertFalse(Utils::isJwtValid($jwt, $timeFn, 'efgh'), (string) $alg);
        }
    }
}
