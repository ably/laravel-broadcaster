<?php

namespace Ably\LaravelBroadcaster;

use Ably\AblyRest;
use Ably\Exceptions\AblyException;
use Ably\Models\Message as AblyMessage;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AblyBroadcaster extends Broadcaster
{
    const LIB_VERSION = '1.0.6';

    /**
     * The AblyRest SDK instance.
     *
     * @var \Ably\AblyRest
     */
    protected $ably;

    /**
     * Used for setting expiry of issues tokens.
     *
     * @var int|mixed
     * @default 1 hr
     */
    private $tokenExpiry = 3600;

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
     * @param  \Ably\AblyRest  $ably
     * @param  array  $config
     * @return void
     */
    public function __construct(AblyRest $ably, $config)
    {
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
    }

    /**
     * Get the current server time adjusted by the Ably server time difference (if clock difference exists).
     *
     * @return int The current server time in seconds.
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
        $token = $request->token;
        $connectionId = $request->socket_id;
        $userId = null;
        $guardedChannelCapability = ['*']; // guardedChannel is either private or presence channel

        $normalizedChannelName = $this->normalizeChannelName($channelName);
        $user = $this->retrieveUser($request, $normalizedChannelName);
        if ($user) {
            $userId = method_exists($user, 'getAuthIdentifierForBroadcasting')
                ? $user->getAuthIdentifierForBroadcasting()
                : $user->getAuthIdentifier();
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

        try {
            $signedToken = $this->getSignedToken($channelName, $token, $userId, $guardedChannelCapability);
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
     * @param  string  $channelName
     * @param  string  $token
     * @param  string  $clientId
     * @param  string[]  $guardedChannelCapability
     * @return string
     */
    public function getSignedToken($channelName, $token, $clientId, $guardedChannelCapability)
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
        if ($token && Utils::isJwtValid($token, $serverTimeFn, $this->getPrivateToken()) && Utils::isSameUser($token, $clientId)) {
            $payload = Utils::parseJwt($token)['payload'];
            $iat = $payload['iat'];
            $exp = $payload['exp'];
            $channelClaims = json_decode($payload['x-ably-capability'], true);

            // Check if the token is about to expire and renew it if necessary
            // The Laravel Echo client typically initiates token renewal 30 seconds before expiry
            // Spec: RTN22
            if ($exp - $serverTimeFn() <= 30) {
                $iat = $serverTimeFn();
                $exp = $iat + $this->tokenExpiry;
            }
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
            'x-ably-clientId' => $clientId ? strval($clientId) : null,
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
        if ($userId) {
            return 'user-id:'.$userId.' '.$message;
        }

        return $message;
    }
}
