<?php

namespace Ably\LaravelBroadcaster;

use Ably\PubSub\Exceptions\AblyException;
use Ably\PubSub\Models\Message as AblyMessage;
use Ably\PubSub\PubSubHttpClient;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AblyBroadcaster extends Broadcaster
{
    const LIB_VERSION = '1.1.0';

    /**
     * The Ably Pub/Sub HTTP (REST) client, created with \Ably\PubSub\Server::createHttpClient().
     *
     * @var \Ably\PubSub\PubSubHttpClient
     */
    protected $ably;

    /**
     * Used for setting expiry of issues tokens.
     *
     * @var int|mixed
     * @default 8 hr
     */
    private $tokenExpiry = 28800;

    /**
     * Config `mau_account` (ABLY_MAU_ACCOUNT). When true, unauthenticated clients get a random guest clientId and
     * logout switches to a fresh one. Some Ably accounts reject a device connection without
     * a clientId (Ably error 40161). Other accounts may enable it too.
     *
     * @var bool
     */
    private $shouldGenerateRandomGuestClientId = false;

    /**
     * Default cosmetic prefix of guest clientIds (config `guest_id_prefix`, ABLY_GUEST_ID_PREFIX). It is not reserved:
     * the broadcaster recognises its own guest ids by their HMAC tag, so a user id with the same prefix is unaffected.
     */
    const GUEST_CLIENT_ID_PREFIX = 'guest:';

    /**
     * Prefix of guest clientIds, used only when `mau_account` is true.
     *
     * @var string
     */
    private $guestClientIdPrefix = self::GUEST_CLIENT_ID_PREFIX;

    /**
     * Session key under which the guest clientId is kept.
     */
    const GUEST_CLIENT_ID_SESSION_KEY = 'ably_guest_client_id';

    /**
     * App-provided guest clientId resolver, see resolveGuestClientIdUsing().
     *
     * @var callable|null
     */
    protected static $guestClientIdResolver;

    /**
     * Public channel capabilities. By default, all public channels are given subscribe, history and channel-metadata access.
     * Set as per https://ably.com/docs/core-features/authentication#capability-operations.
     *
     * @var array
     */
    private $publicChannelsClaims = [
        'public:*' => ['subscribe', 'history', 'channel-metadata'],
    ];

    /**
     * Used for storing the difference in seconds between system time and Ably server time
     *
     * @var int
     */
    private $serverTimeDiff = 0;

    /**
     * Create a new broadcaster instance.
     *
     * @param  \Ably\PubSub\PubSubHttpClient  $ably  Build it with \Ably\PubSub\Server::createHttpClient()
     * @param  array  $config
     * @return void
     *
     * @throws \InvalidArgumentException if $ably is not a \Ably\PubSub\PubSubHttpClient
     */
    public function __construct($ably, $config)
    {
        if (! $ably instanceof PubSubHttpClient) {
            throw new \InvalidArgumentException(sprintf(
                'AblyBroadcaster expects an %s created with Ably\PubSub\Server::createHttpClient(), %s given. '
                .'See "1.0.x → 1.1.0" in UPDATING.md (https://github.com/ably/laravel-broadcaster/blob/HEAD/UPDATING.md).',
                PubSubHttpClient::class,
                get_debug_type($ably)
            ));
        }

        $this->ably = $ably;

        if (array_key_exists('sync_server_time', $config) && $config['sync_server_time']) {
            // Local file cache is preferred to avoid sharing serverTimeDiff across different servers
            $this->serverTimeDiff = Cache::store('file')->remember('ably_server_time_diff', 6 * 3600, function() {
                return time() - round($this->ably->time() / 1000);
            });
        }
        if (array_key_exists('disable_public_channels', $config) && $config['disable_public_channels']) {
            $this->publicChannelsClaims = ['public:*' => ['channel-metadata']];
        }
        if (array_key_exists('token_expiry', $config)) {
            $this->tokenExpiry = $config['token_expiry'];
        }
        // The package ships no config file: apps add these keys to the `ably` connection in config/broadcasting.php
        $this->shouldGenerateRandomGuestClientId = (bool) ($config['mau_account'] ?? false);
        if (isset($config['guest_id_prefix']) && is_string($config['guest_id_prefix'])) {
            $this->guestClientIdPrefix = $config['guest_id_prefix'];
        }
    }

    /**
     * @return int
     */
    private function getServerTime()
    {
        return time() - $this->serverTimeDiff;
    }

    /**
     * Get the public token value from the Ably key.
     *
     * @return mixed
     */
    protected function getPublicToken()
    {
        return Str::before($this->ably->options->key, ':');
    }

    /**
     * Get the private token value from the Ably key.
     *
     * @return mixed
     */
    protected function getPrivateToken()
    {
        return Str::after($this->ably->options->key, ':');
    }

    /**
     * Authenticate the incoming request for a given channel.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     *
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
     */
    public function auth($request)
    {
        $channelName = $request->channel_name;
        $connectionId = is_string($request->socket_id) ? $request->socket_id : null;
        if ($channelName !== null && ! is_string($channelName)) { // e.g. `channel_name[]=x`
            throw new AccessDeniedHttpException('Invalid channel name, ably-connection-id:'.$connectionId);
        }
        $token = $request->token;
        if (! is_string($token)) {
            $token = null; // e.g. `token[]=x`: treated as no token
        }
        $userId = null;
        $guardedChannelCapability = ['*']; // guardedChannel is either private or presence channel

        $normalizedChannelName = $this->normalizeChannelName($channelName);
        $user = $this->retrieveUser($request, $normalizedChannelName);
        if ($user) {
            $userId = method_exists($user, 'getAuthIdentifierForBroadcasting')
                ? $user->getAuthIdentifierForBroadcasting()
                : $user->getAuthIdentifier();
            if ($this->shouldGenerateRandomGuestClientId && $request->hasSession()) {
                // A signed-in user ends the guest identity, so the next guest (e.g. after logout) gets a fresh id
                $request->session()->forget(self::GUEST_CLIENT_ID_SESSION_KEY);
            }
        }
        if ($this->isGuardedChannel($channelName)) {
            if (! $user) {
                throw new AccessDeniedHttpException('User not authenticated, '.$this->stringify($channelName, $connectionId));
            }
            try {
                $userChannelMetaData = parent::verifyUserCanAccessChannel($request, $normalizedChannelName);
                if (is_array($userChannelMetaData) && array_key_exists('ably-capability', $userChannelMetaData)) {
                    $guardedChannelCapability = $userChannelMetaData['ably-capability'];
                    unset($userChannelMetaData['ably-capability']);
                }
            } catch (\Exception $e) {
                throw new AccessDeniedHttpException('Access denied, '.$this->stringify($channelName, $connectionId, $userId), $e);
            }
        }

        if ($userId === null && $this->shouldGenerateRandomGuestClientId) {
            $userId = $this->resolveGuestClientId($request, $token);
        }

        $renewedChannelClaims = null;
        if (! $channelName) {
            try {
                $renewedChannelClaims = $this->renewedGuardedClaims($request, $token, $userId, $user !== null);
            } catch (\Throwable $_) { // no stack trace (it holds the signing secret as an argument): renew with public claims only
                $renewedChannelClaims = [];
            }
        }

        try {
            $signedToken = $this->getSignedToken($channelName, $token, $userId, $guardedChannelCapability, $renewedChannelClaims);
        } catch (\Exception $_) { // excluding exception to avoid exposing private key
            throw new AccessDeniedHttpException('malformed token, '.$this->stringify($channelName, $connectionId, $userId));
        }

        $response = ['token' => $signedToken];
        if (isset($userChannelMetaData) && is_array($userChannelMetaData) && count($userChannelMetaData) > 0) {
            $response['info'] = $userChannelMetaData;
        }

        return $response;
    }

    /**
     * Registers a callback that provides the clientId of unauthenticated clients, for apps that need their own scheme.
     * It receives the request and replaces the built-in session based id. Pass null to remove it.
     * A callable is kept out of config on purpose, as closures cannot be cached by `config:cache`.
     *
     * @param  callable|null  $resolver
     * @return void
     */
    public static function resolveGuestClientIdUsing(?callable $resolver)
    {
        static::$guestClientIdResolver = $resolver;
    }

    /**
     * Returns the clientId for an unauthenticated client, which is stable per session or per token (one visitor keeps one guest id).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string|null  $token  The token presented by the client, if any
     * @return string
     *
     * @throws \UnexpectedValueException if the app's resolver returns an empty or wildcard clientId
     */
    protected function resolveGuestClientId($request, $token)
    {
        if (static::$guestClientIdResolver) {
            $clientId = call_user_func(static::$guestClientIdResolver, $request);
            $clientId = is_scalar($clientId) ? (string) $clientId : '';
            if ($clientId === '' || $clientId === '*') {
                throw new \UnexpectedValueException(
                    'The resolver registered with AblyBroadcaster::resolveGuestClientIdUsing() must return a non-empty clientId other than "*".'
                );
            }

            return $clientId;
        }

        if ($request->hasSession()) {
            // get/put rather than remember, as remember is not available on the session of every supported Laravel version
            $clientId = $request->session()->get(self::GUEST_CLIENT_ID_SESSION_KEY);
            // Reuse the stored id only if this broadcaster issued it under the current prefix and key, so a changed prefix or a rotated key applies at the next token request
            if (! is_string($clientId) || ! $this->isOwnGuestClientId($clientId)) {
                $clientId = $this->newGuestClientId();
                $request->session()->put(self::GUEST_CLIENT_ID_SESSION_KEY, $clientId);
            }

            return $clientId;
        }

        // Stateless route: keep the guest id the page already holds
        try {
            if ($token && is_string($token) && Utils::isJwtValid($token, function () {
                return $this->getServerTime();
            }, $this->getPrivateToken())) {
                $clientId = Utils::parseJwt($token)['payload']['x-ably-clientId'] ?? null;
                if (is_string($clientId) && $this->isOwnGuestClientId($clientId)) {
                    return $clientId;
                }
            }
        } catch (\Throwable $_) { // a malformed token is simply not reused
        }

        return $this->newGuestClientId();
    }

    /**
     * @return string the prefix, 22 characters of base64url encoded random bytes (128 bits), a dot and a 22 character tag
     */
    protected function newGuestClientId()
    {
        $random = Utils::base64urlEncode(random_bytes(16));

        return $this->guestClientIdPrefix.$random.'.'.$this->guestClientIdTag($random);
    }

    /**
     * HMAC-SHA256 of the random part, keyed with the API key secret and truncated to 16 bytes (base64url, 22 characters).
     *
     * @param  string  $random
     * @return string
     */
    protected function guestClientIdTag($random)
    {
        return Utils::base64urlEncode(substr(hash_hmac('sha256', 'ably-guest:'.$random, $this->getPrivateToken(), true), 0, 16));
    }

    /**
     * Whether the clientId is a guest id issued by this broadcaster, recognised by its tag rather than its prefix.
     *
     * @param  string  $clientId
     * @return bool
     */
    protected function isOwnGuestClientId($clientId)
    {
        $prefix = $this->guestClientIdPrefix;
        if (strncmp($clientId, $prefix, strlen($prefix)) !== 0) {
            return false;
        }
        if (! preg_match('/^([A-Za-z0-9_-]{22})\.([A-Za-z0-9_-]{22})$/D', substr($clientId, strlen($prefix)), $matches)) {
            return false;
        }

        return hash_equals($this->guestClientIdTag($matches[1]), $matches[2]);
    }

    /**
     * Return the valid authentication response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $result
     * @return mixed
     */
    public function validAuthenticationResponse($request, $result)
    {
        return $result;
    }

    /**
     * Broadcast the given event.
     *
     * @param array $channels
     * @param string $event
     * @param array $payload
     * @return void
     *
     * @throws \Illuminate\Broadcasting\BroadcastException
     * @throws \Exception
     */
    public function broadcast($channels, $event, $payload = [])
    {
        $socketId = Arr::pull($payload, 'socket');
        try {
            $socketIdObject = Utils::decodeSocketId($socketId);
            foreach ($this->formatChannels($channels) as $channel) {
                $this->ably->channels->get($channel)->publish(
                    $this->buildAblyMessage($event, $payload, $socketIdObject)
                );
            }
        } catch (AblyException $e) {
            throw new BroadcastException(
                sprintf('Ably error: %s', $e->getMessage())
            );
        }
    }

    /**
     * On a connection token renewal (no channel name), returns the private and presence claims of the presented token
     * that the channel callbacks still authorise for the current user, or null when the presented token is not a valid
     * token of the same identity (the claims are then reset). Denied channels and channels whose callback throws are dropped.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string|null  $token
     * @param  string|int|null  $clientId
     * @param  bool  $hasUser
     * @return array|null
     */
    protected function renewedGuardedClaims($request, $token, $clientId, $hasUser)
    {
        $serverTimeFn = function () {
            return $this->getServerTime();
        };
        if (! $token || ! Utils::isJwtValid($token, $serverTimeFn, $this->getPrivateToken())) {
            return null;
        }
        $payload = Utils::parseJwt($token)['payload'];
        if (($payload['x-ably-clientId'] ?? null) !== ($clientId !== null && $clientId !== '' ? (string) $clientId : null)) {
            return null;
        }
        $renewed = [];
        $presented = json_decode($payload['x-ably-capability'] ?? '', true);
        if (! $hasUser || ! is_array($presented)) {
            return $renewed;
        }
        foreach (array_keys($presented) as $channel) {
            if (! $this->isGuardedChannel($channel)) {
                continue;
            }
            try {
                $metaData = parent::verifyUserCanAccessChannel($request, $this->normalizeChannelName($channel));
            } catch (\Throwable $_) {
                continue; // no longer authorised, or the callback failed: fail closed
            }
            $renewed[$channel] = is_array($metaData) && array_key_exists('ably-capability', $metaData)
                ? $metaData['ably-capability']
                : ['*'];
        }

        return $renewed;
    }

    /**
     * @param  string  $channelName
     * @param  string  $token
     * @param  string  $clientId
     * @param  string[]  $guardedChannelCapability
     * @param  array|null  $renewedChannelClaims  Re-authorised private and presence claims of a connection token renewal.
     *                                            When set, the token gets a fresh iat and exp.
     * @return string
     */
    public function getSignedToken($channelName, $token, $clientId, $guardedChannelCapability, $renewedChannelClaims = null)
    {
        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256',
            'kid' => $this->getPublicToken(),
        ];
        // Set capabilities for public channel as per https://ably.com/docs/core-features/authentication#capability-operations
        $channelClaims = $this->publicChannelsClaims;
        $serverTimeFn = function () {
            return $this->getServerTime();
        };
        $signedClientId = $clientId !== null && $clientId !== '' ? (string) $clientId : null;
        // Claims are reused only when the presented token belongs to the identity being signed now (null matches null),
        // otherwise a logout or a user switch would inherit the previous identity's private and presence capabilities.
        if ($renewedChannelClaims !== null) {
            $iat = $serverTimeFn();
            $exp = $iat + $this->tokenExpiry;
            $channelClaims = array_merge($channelClaims, $renewedChannelClaims);
        } elseif ($token && Utils::isJwtValid($token, $serverTimeFn, $this->getPrivateToken())
            && (Utils::parseJwt($token)['payload']['x-ably-clientId'] ?? null) === $signedClientId) {
            $payload = Utils::parseJwt($token)['payload'];
            $iat = $payload['iat'];
            $exp = $payload['exp'];
            $reused = is_string($payload['x-ably-capability'] ?? null) ? json_decode($payload['x-ably-capability'], true) : null;
            $channelClaims = is_array($reused) ? $reused : $channelClaims;
        } else {
            $iat = $serverTimeFn();
            $exp = $iat + $this->tokenExpiry;
        }
        if ($channelName && $this->isGuardedChannel($channelName)) {
            $channelClaims[$channelName] = $guardedChannelCapability;
        }
        $claims = [
            'iat' => $iat,
            'exp' => $exp,
            'x-ably-clientId' => $signedClientId,
            'x-ably-capability' => json_encode($channelClaims),
        ];

        return Utils::generateJwt($header, $claims, $this->getPrivateToken());
    }

    /**
     * Remove prefix from channel name.
     *
     * @param  string  $channel
     * @return string
     */
    public function normalizeChannelName($channel)
    {
        if ($channel) {
            if ($this->isPrivateChannel($channel)) {
                return Str::replaceFirst('private:', '', $channel);
            }
            if ($this->isPresenceChannel($channel)) {
                return Str::replaceFirst('presence:', '', $channel);
            }

            return Str::replaceFirst('public:', '', $channel);
        }

        return $channel;
    }

    /**
     * Checks if channel is a private channel.
     *
     * @param  string  $channel
     * @return bool
     */
    public function isPrivateChannel($channel)
    {
        return Str::startsWith($channel, 'private:');
    }

    /**
     * Checks if channel is a presence channel.
     *
     * @param  string  $channel
     * @return bool
     */
    public function isPresenceChannel($channel)
    {
        return Str::startsWith($channel, 'presence:');
    }

    /**
     * Checks if channel needs authentication.
     *
     * @param  string  $channel
     * @return bool
     */
    public function isGuardedChannel($channel)
    {
        return $this->isPrivateChannel($channel) || $this->isPresenceChannel($channel);
    }

    /**
     * Format the channel array into an array of strings.
     *
     * @param  array  $channels
     * @return array
     */
    public function formatChannels($channels)
    {
        return array_map(function ($channel) {
            $channel = (string) $channel;

            if (Str::startsWith($channel, ['private-', 'presence-'])) {
                return Str::startsWith($channel, 'private-')
                    ? Str::replaceFirst('private-', 'private:', $channel)
                    : Str::replaceFirst('presence-', 'presence:', $channel);
            }

            return 'public:'.$channel;
        }, $channels);
    }

    /**
     * Build an Ably message object for broadcasting.
     *
     * @param string $event
     * @param array $payload
     * @param object $socketIdObject
     * @return AblyMessage
     */
    protected function buildAblyMessage($event, $payload = [], $socketIdObject = null)
    {
        $message = tap(new AblyMessage, function ($message) use ($event, $payload) {
            $message->name = $event;
            $message->data = $payload;
        });

        if ($socketIdObject) {
            $message->connectionKey = $socketIdObject->connectionKey;
            $message->clientId = $socketIdObject->clientId;
        }
        return $message;
    }

    /**
     * @param  string  $channelName
     * @param  string  $connectionId
     * @param  string|null  $userId
     * @return string
     */
    protected function stringify($channelName, $connectionId, $userId = null)
    {
        $message = 'channel-name:'.$channelName.' ably-connection-id:'.$connectionId;
        if ($userId !== null && $userId !== '') {
            return 'user-id:'.$userId.' '.$message;
        }

        return $message;
    }
}
