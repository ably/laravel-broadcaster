# Upgrade / Migration Guide

## 1.0.x → 1.1.0

Version 1.1.0 moves from `ably/ably-php` 1.x to [`ably/pubsub-server`](https://github.com/ably/ably-pubsub-php) 2.0, whose clients declare the server side as monthly-active-user (MAU) pricing requires. It is a minor release: the documented `config/broadcasting.php` options keep working unchanged, and every new behaviour is off by default except the fixes listed under [Behaviour changes](#behaviour-changes).

For the client side (`@ably/laravel-echo`), see the [Echo UPDATING.md](https://github.com/ably-forks/laravel-echo/blob/HEAD/UPDATING.md). `@ably/laravel-echo` 1.1.0 must be published before, or together with, broadcaster 1.1.0.

### Steps

1. **Network / firewall first:** allow the new hosts, see [Network requirements](#network-requirements).
2. Update: `composer update ably/laravel-broadcaster`. Add `-W` only if `rybakit/msgpack` 0.10/0.11 is locked (`ably/pubsub-server` currently constrains it to 0.9.x); `composer why-not ably/laravel-broadcaster 1.1.0` shows what blocks the update.
3. Keep `ABLY_KEY` set, see [Requirements](#requirements-and-client-options).
4. Optional: add the new config lines, see [New config keys](#new-config-keys). On an MAU-billed Ably account this is required, see [MAU-billed Ably accounts](#mau-billed-ably-accounts).
5. Client side: run `npm install @ably/laravel-echo@^1.1` (release pending) and rebuild assets (the `ably` package and `window.Ably` are no longer needed). `broadcast(...)->toOthers()` needs `@ably/laravel-echo` >= 1.0.6, and ably-js <= 1.2.35 is sunset.
6. If you construct `AblyBroadcaster` yourself, pass a client created with `\Ably\PubSub\Server::createHttpClient()`; any other value throws `InvalidArgumentException`.
7. If you reference `Ably\…` SDK classes (e.g. in a subclass), rename them to `Ably\PubSub\…`. For example, `buildAblyMessage()` overrides must return `Ably\PubSub\Models\Message`, and `Utils::decodeSocketId()` throws `Ably\PubSub\Exceptions\AblyException` (`broadcast()` still throws `BroadcastException`).
8. `ably/ably-php` is no longer installed for you. Require `ably/ably-php:^1.1` yourself if you use `Ably\AblyRest` directly. Do not bump `ably/ably-php` to 2.x; this package declares a conflict with it.
9. Make sure this package's service provider is registered (package discovery does it by default). If it is not, Laravel's built-in `ably` driver is used, and that driver needs `Ably\AblyRest`.
10. If you set `environment`, `restHost` or `port` as client options, switch to `endpoint`, see the SDK's [UPDATING.md](https://github.com/ably/ably-pubsub-php/blob/main/UPDATING.md).
11. Restart queue workers, Horizon and Octane after deploying.

### Requirements and client options

- PHP >= 8.1 with the `curl`, `json` and `openssl` extensions, and Laravel >= 8.0. On Laravel 8 this means exactly PHP 8.1, as Laravel 8 does not support PHP >= 8.2.
- `key` (`ABLY_KEY`) is required. A missing or empty `key` fails with Ably error 40103, also when `token`, `authUrl` or `authCallback` is set: the broadcaster signs client tokens with the API key.
- `useTokenAuth` is ignored: the server client always uses basic auth with its API key. Server-side token auth is not supported.
- Basic auth requires TLS, so `tls => false` together with a `key` fails with Ably error 40103.
- Any other key in the `ably` connection config is passed through to `Ably\PubSub\Server::createHttpClient()` as an Ably client option, see the [client options of the SDK](https://github.com/ably/ably-pubsub-php). `agents` is set by the broadcaster; a value you set is overwritten.
- Option values must be serializable for `php artisan config:cache`, so do not use closures.

### New config keys

This package ships no config file, so an app upgrading from 1.0.x does not have the new lines in its `config/broadcasting.php`. **Setting `ABLY_MAU_ACCOUNT` or `ABLY_GUEST_ID_PREFIX` in `.env` has no effect until you add the matching lines to the `ably` connection:**

```php
        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
            'mau_account' => env('ABLY_MAU_ACCOUNT', false),
            'guest_id_prefix' => env('ABLY_GUEST_ID_PREFIX', 'guest:'),
        ],
```

Re-run `php artisan config:cache` if you cache config.

- **Absent keys are safe.** The broadcaster (`src/AblyBroadcaster.php`) reads every key with a default: `mau_account` defaults to `false`, `guest_id_prefix` to `guest:`, `token_expiry` to 28800 s, i.e. 8 hours, and `disable_public_channels` and `sync_server_time` to off. With a 1.0.x config nothing changes: guests keep a `null` clientId and tokens expire after 8 hours (`tests/LegacyConfigDefaultsFeatureTest.php`).
- **Why the package does not read `env()` itself.** Under `php artisan config:cache`, Laravel does not load `.env` at all, so `env()` returns only real system environment variables (see [Laravel: configuration caching](https://laravel.com/docs/configuration#configuration-caching)). Keep `env()` calls inside `config/*.php`.

### MAU-billed Ably accounts

Ably accounts with MAU pricing only accept identified clients, and only SDKs that declare their side (device or server). Connections that do not meet this are rejected after the socket opens, the client goes to `failed` and does not retry:

| Ably error | Status | When it is returned |
|---|---|---|
| 40161 | 401 | A device connection has no clientId (`null`), or the wildcard `*`. This is what an unauthenticated visitor, or a user who has just logged out, gets by default. |
| 40167 | 401 | A server client authenticates with a token that lacks the signed `x-ably-clientType: server` claim. |
| 40168 | 400 | The SDK does not declare its side, e.g. the `ably` (ably-js) package. Use `@ably/laravel-echo` >= 1.1.0, which uses `@ably/pubsub-device`. |

1.0.x is rejected on MAU-billed accounts. `ABLY_MAU_ACCOUNT` issues guest clientIds to unauthenticated users. It is required on MAU-billed accounts, and it may be enabled on other accounts too. To serve guests on an MAU-billed account:

- Set `ABLY_MAU_ACCOUNT=true` and add the lines from [New config keys](#new-config-keys).
- Unauthenticated clients are then issued a guest clientId instead of `null`: `<prefix><random>.<tag>`. `<random>` is 22 characters (the base64url encoding of 16 random bytes), and `<tag>` is a 22 character HMAC of it, keyed with your API key secret.
- The prefix defaults to `guest:` and can be changed with `ABLY_GUEST_ID_PREFIX`. It is cosmetic and **not reserved**: the broadcaster recognises the guest ids it issued by their tag alone, so a real user id that starts with `guest:` is legal and unaffected. The prefix is only used when `ABLY_MAU_ACCOUNT=true`.
- The guest id is stable for the session, so one visitor is one MAU. It is kept in the session as `ably_guest_client_id`. On routes without a session, the guest id in the still-valid token that the client presents is reused if its tag verifies; otherwise a new one is generated.
- A signed-in user's auth request drops the stored guest id, so a guest after logout gets a **fresh id**.
- Changing `ABLY_GUEST_ID_PREFIX` or rotating `ABLY_KEY` gives each guest a **new id** at its next token request, whether or not the route has a session.
- Guests can still only use public channels; private and presence channels require a signed-in user. Each guest counts as a monthly active user on your Ably account.
- To use your own scheme, register a resolver, e.g. in a service provider. It receives the request and must return a non-empty id other than `*`; it is used as returned (no prefix or tag is added). A closure cannot live in the config file, as it breaks `php artisan config:cache`.

```php
  \Ably\LaravelBroadcaster\AblyBroadcaster::resolveGuestClientIdUsing(function ($request) {
      return 'visitor:'.$request->cookie('visitor_id');
  });
```

- Logging in without reloading the page (SPA) switches the connection from one non-null clientId to another, which needs `@ably/laravel-echo` >= 1.1.0 (release pending). Other open tabs switch to the new identity at the latest at their next connection token renewal (within `token_expiry`, 8 hours by default), and earlier after a login if the tab authorizes a private or presence channel. With an older Echo, guests connect, but in-page login breaks, and other open tabs fail with a clientId mismatch (40102) at their next renewal; only a page reload recovers them. See the [Echo UPDATING.md](https://github.com/ably-forks/laravel-echo/blob/HEAD/UPDATING.md) for the client-side steps, so turn the flag on only after Echo 1.1.0 is deployed.
- With `ABLY_MAU_ACCOUNT` left off on an MAU-billed account, guests fail with 40161, and `@ably/laravel-echo` logs this hint in the browser console: "Your Ably account requires an identified client on every connection (MAU billing), but this guest connected without a clientId. In laravel-broadcaster set ABLY_MAU_ACCOUNT=true (config broadcasting.connections.ably.mau_account) so guests receive a clientId, or only connect Echo for signed-in users. See "Guest users on MAU-billed Ably accounts" in the README."

### Behaviour changes

- **Claims reset on identity change (always on).** A presented token's `iat`/`exp`/capability are reused only for the same clientId. When the clientId changes, e.g. on logout or when another user signs in, the new identity starts with the public channel capability plus the channel being authorized, instead of inheriting the previous user's private and presence access. Capabilities are still carried over when renewing a token for the same clientId.
- **Token renewal issues a fresh expiry.** A connection token renewal (no channel name) with a valid same-identity token used to return an identical token, which Ably kept re-requesting until it disconnected with 40142. It now issues a fresh `iat`/`exp` (default 28800 s, 8 hours, `ABLY_TOKEN_EXPIRY`) and re-checks every private/presence channel in the presented token against `routes/channels.php` (`renewedGuardedClaims()`), honouring a returned `ably-capability`. Guests keep public claims only. A token that has already expired cannot be re-checked: its renewal gets public claims only, and Echo re-authorizes private and presence channels as they report 40160.
- **Revocation takes effect at the next renewal.** A channel the callback no longer authorises is dropped from the renewed token; a token already issued keeps its access until it expires or is renewed.
- **No cap on re-checked channels.** The presented token's signature is verified first, so its channels can only be ones this broadcaster already granted to that user, and a cap would only cause extra 40160 re-auth requests. Each renewal costs one callback per channel, so consider Laravel's rate limiting on the `/broadcasting/auth` route.
- **Security note on channel names:** the requested channel name is used verbatim as the Ably capability resource, after Laravel route matching on the name without its `private:` or `presence:` prefix. Ably treats `*` as a wildcard only as a whole `:`-delimited segment (for example `private:*` or `private:chat.1:*`), which matches every channel under that prefix. A `*` inside a segment (for example `private:chat.*`) is a literal channel name. Laravel's `{param}` placeholders match any characters except `.`, including `*` and `:`. So a route such as `{name}` accepts `private:*`, `chat:{id}` accepts `private:chat:*`, and `chat.{id}` accepts `private:chat.1:*`. Constrain route parameters in your `channels.php` callbacks (for example with `ctype_digit($id)`) and only authorise the channels you intend.

### Network requirements

From 1.1 the broadcaster calls `main.realtime.ably.net` and falls back to `main.[a-e].fallback.ably-realtime.com` (1.0.x used `rest.ably.io` and `[a-e].ably-realtime.com`). Allow `*.realtime.ably.net` and `*.fallback.ably-realtime.com` in egress firewalls and proxies. Proxies are configured with `http_proxy` / `https_proxy` in `$_SERVER`, as before.

- If these hosts are blocked, publishing fails with `BroadcastException`. If packets are dropped rather than rejected, each publish can block for about 16 seconds (4 s connect timeout × up to 4 hosts), so check queue job timeouts.
- With `sync_server_time` enabled, the broadcaster requests Ably's server time (`GET /time`) when it is created and its cached value is missing or older than 6 hours (e.g. after a fresh deploy). Apps that use `ably` as the default broadcaster and load `routes/channels.php` create the broadcaster while booting, so blocked egress then fails every request and artisan command, not only auth and broadcasting.
- Browsers: legacy ably-js 1.x still connects to `realtime.ably.io` / `*.ably-realtime.com`, which DNS now points (CNAME) at the new domains. Allowlists that match on resolved names or addresses need the new domains too.

### Deprecations

The default of `ABLY_MAU_ACCOUNT` will change to `true` in 2.0.0, with `@ably/laravel-echo` >= 1.1.0 required. Set it explicitly to keep the current behaviour: `ABLY_MAU_ACCOUNT=false` keeps null guest clientIds, and `ABLY_MAU_ACCOUNT=true` gives guests a random clientId.

### Staying on 1.0.x

On PHP 7.2 – 8.0 or Laravel 6 / 7, Composer keeps you on `ably/laravel-broadcaster` 1.0.x. It keeps working on accounts without MAU pricing, but 1.0.x is **rejected on MAU-enabled accounts** and only receives critical fixes, on the `1.0.x` branch, until about October 2027. To roll back, require `ably/laravel-broadcaster:^1.0` again (and `ably/ably-php:^1.1` if you use it directly) and remove the `mau_account` and `guest_id_prefix` lines, which 1.0.x ignores.
