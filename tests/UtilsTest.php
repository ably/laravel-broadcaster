<?php

namespace Ably\LaravelBroadcaster\Tests;

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
}
