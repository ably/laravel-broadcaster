<?php

namespace Ably\LaravelBroadcaster;

use Ably\Exceptions\AblyException;

class Utils
{
    // JWT related PHP utility functions
    /**
     * @param  string  $jwt
     * @return array
     */
    public static function parseJwt($jwt)
    {
        $tokenParts = explode('.', $jwt);
        $header = json_decode(self::base64url_decode($tokenParts[0]), true);
        $payload = json_decode(self::base64url_decode($tokenParts[1]), true);

        return ['header' => $header, 'payload' => $payload];
    }

    /**
     * @param  array  $headers
     * @param  array  $payload
     * @return string
     */
    public static function generateJwt($headers, $payload, $key)
    {
        $encodedHeaders = self::base64url_encode(json_encode($headers));
        $encodedPayload = self::base64url_encode(json_encode($payload));

        $signature = hash_hmac('SHA256', "$encodedHeaders.$encodedPayload", $key, true);
        $encodedSignature = self::base64url_encode($signature);

        return "$encodedHeaders.$encodedPayload.$encodedSignature";
    }

    /**
     * @param  string  $jwt
     * @param  mixed  $timeFn
     * @return bool
     */
    public static function isJwtValid($jwt, $timeFn, $key)
    {
        // split the jwt
        $tokenParts = explode('.', $jwt);
        $header = $tokenParts[0];
        $payload = $tokenParts[1];
        $tokenSignature = $tokenParts[2];

        // check the expiration time - note this will cause an error if there is no 'exp' claim in the jwt
        $expiration = json_decode(self::base64url_decode($payload))->exp;
        $isTokenExpired = $expiration <= $timeFn();

        // build a signature based on the header and payload using the secret
        $signature = hash_hmac('SHA256', $header.'.'.$payload, $key, true);
        $isSignatureValid = self::base64url_encode($signature) === $tokenSignature;

        return $isSignatureValid && ! $isTokenExpired;
    }

    /**
     * https://www.php.net/manual/en/function.base64-encode.php#127544
     */
    public static function base64url_encode($str): string
    {
        return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
    }

    /**
     * https://www.php.net/manual/en/function.base64-encode.php#127544
     */
    public static function base64url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }

    const SOCKET_ID_ERROR = "please make sure to send base64 url encoded json with "
    ."'connectionKey' and 'clientId' as keys. 'clientId' is null if connection is not identified";

    /**
     * @throws AblyException
     */
    public static function decodeSocketId($socketId) {
        $socketIdObject = null;
        if ($socketId) {
            $socketIdObject = json_decode(self::base64url_decode($socketId));
            if (!$socketIdObject) {
                throw new AblyException("SocketId decoding failed, ".self::SOCKET_ID_ERROR);
            }
            if (!isset($socketIdObject->connectionKey)) {
                throw new AblyException("ConnectionKey is not set, ".self::SOCKET_ID_ERROR);
            }
            if (!property_exists($socketIdObject, 'clientId')) {
                throw new AblyException("ClientId is missing, ".self::SOCKET_ID_ERROR);
            }
        }
        return $socketIdObject;
    }
}
