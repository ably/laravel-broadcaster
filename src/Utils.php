<?php

namespace Ably\LaravelBroadcaster;

use Ably\PubSub\Exceptions\AblyException;

class Utils
{
    // JWT related PHP utility functions
    /**
     * @param  string  $jwt
     * @return array
     */
    public static function parseJwt($jwt): array
    {
        $tokenParts = explode('.', $jwt);
        $header = json_decode(self::base64urlDecode($tokenParts[0]), true);
        $payload = json_decode(self::base64urlDecode($tokenParts[1]), true);

        return ['header' => $header, 'payload' => $payload];
    }

    /**
     * @param  array  $headers
     * @param  array  $payload
     * @return string
     */
    public static function generateJwt($headers, $payload, $key): string
    {
        $encodedHeaders = self::base64urlEncode(json_encode($headers));
        $encodedPayload = self::base64urlEncode(json_encode($payload));

        $signature = hash_hmac('SHA256', "$encodedHeaders.$encodedPayload", $key, true);
        $encodedSignature = self::base64urlEncode($signature);

        return "$encodedHeaders.$encodedPayload.$encodedSignature";
    }

    /**
     * Checks the signature first and only then reads the claims. Never throws: anything that is not a well formed,
     * correctly signed and unexpired HS256-style token (three parts, `alg` HS256, array header and payload, numeric `exp`) is invalid.
     *
     * @param  string  $jwt
     * @param  mixed  $timeFn
     * @return bool
     */
    public static function isJwtValid($jwt, $timeFn, $key): bool
    {
        try {
            if (! is_string($jwt)) {
                return false;
            }
            $tokenParts = explode('.', $jwt);
            if (count($tokenParts) !== 3) {
                return false;
            }
            [$header, $payload, $tokenSignature] = $tokenParts;

            // build a signature based on the header and payload using the secret, before trusting any claim
            $signature = hash_hmac('SHA256', $header.'.'.$payload, $key, true);
            if (! hash_equals(self::base64urlEncode($signature), $tokenSignature)) {
                return false;
            }

            $decodedHeader = json_decode((string) base64_decode(strtr($header, '-_', '+/'), true), true);
            $decodedPayload = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
            if (! is_array($decodedHeader) || ! is_array($decodedPayload)) {
                return false;
            }
            if (($decodedHeader['alg'] ?? null) !== 'HS256') { // the only algorithm this package signs with
                return false;
            }
            if (! isset($decodedPayload['exp']) || ! is_numeric($decodedPayload['exp'])) {
                return false;
            }

            return $decodedPayload['exp'] > $timeFn();
        } catch (\Throwable $_) {
            return false;
        }
    }

    /**
     * https://www.php.net/manual/en/function.base64-encode.php#127544
     */
    public static function base64urlEncode($str): string
    {
        return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
    }

    /**
     * https://www.php.net/manual/en/function.base64-encode.php#127544
     */
    public static function base64urlDecode($data): string
    {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }

    const SOCKET_ID_ERROR = "please make sure to send base64 url encoded json with "
    ."'connectionKey' and 'clientId' as keys. 'clientId' is null if connection is not identified";

    /**
     * @return object
     * @throws AblyException
     */
    public static function decodeSocketId($socketId): ?object
    {
        $socketIdObject = null;
        if ($socketId) {
            $socketIdJsonString = self::base64urlDecode($socketId);
            if (!$socketIdJsonString) {
                throw new AblyException("Base64 decoding failed, ".self::SOCKET_ID_ERROR);
            }
            $socketIdObject = json_decode($socketIdJsonString);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new AblyException("JSON decoding failed: " . json_last_error_msg() . ", " . self::SOCKET_ID_ERROR);
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
