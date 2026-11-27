# Upgrade / Migration Guide

<a id="upgrading-to-20"></a>

## Upgrading to 2.0

Version 2.0 always gives unauthenticated users (guests) a clientId, with no option to turn this off, and requires `@ably/laravel-echo` v2.

**Upgrade `ably/laravel-broadcaster` and `@ably/laravel-echo` together.** Only broadcaster 2.x with Echo 2.x is supported. Mixed versions (broadcaster 2.x with Echo 1.x, or broadcaster 1.x with Echo 2.x) are not supported. For example, with Echo 1.0.x an in-page (SPA) login fails until the page is reloaded, and other open tabs fail with a clientId mismatch (40102) at their next token renewal. Composer cannot check an npm package, so make sure both are deployed in the same release.

<a id="11--20"></a>

### From 1.1 to 2.0

1. Client: run `npm install @ably/laravel-echo@^2`, rebuild your assets, and deploy them in the same release as the server. For client-side changes, see the [Echo UPDATING.md](https://github.com/ably-forks/laravel-echo/blob/HEAD/UPDATING.md#1x--200). <!-- TODO(G3): verify the anchor once Echo 2.0.0 is on its default branch (heading "## 1.x → 2.0.0", slug `1x--200`) -->
2. Server: run `composer require ably/laravel-broadcaster:^2.0`. A `^1.1` constraint never installs 2.0.
3. Remove `ABLY_MAU_ACCOUNT` from `.env` and the `'mau_account' => …` line from the `ably` connection in `config/broadcasting.php`. If you leave them, they are ignored: there is no error and no warning.
4. **Guests no longer get a `null` clientId**, also where the option of step 3 was off. Every guest now gets `<prefix><random>.<tag>`. Check anything that relied on a `null` guest clientId, for example:
    - code that tests the clientId (or the socket id) of a guest;
    - messages sent with `broadcast(...)->toOthers()` from a guest's request, which now carry the guest's clientId (the `toOthers` exclusion itself is unchanged);
    - session contents: on routes with a session, the session now holds `ably_guest_client_id` for every guest (unless you registered a resolver with `resolveGuestClientIdUsing()`).
5. If you set `ABLY_GUEST_ID_PREFIX` (`guest_id_prefix`), keep it. It now always applies.
6. If you registered `AblyBroadcaster::resolveGuestClientIdUsing()`, it is now called for **every** guest. In 1.1 it was only called when the option of step 3 was on. Its id must be stable across calls and namespaced. See [Guest client ids](#guest-client-ids) below.
7. Billing:
    - On an Ably account billed by monthly active users (MAU), each guest id counts as one monthly active user, and a logout starts a fresh id.
    - On accounts billed by peak connections, no effect is expected. <!-- TODO(T1/T2): replace with Ably's confirmed wording -->
8. Restart queue workers, Horizon and Octane after you deploy.

<a id="10x--20"></a>

### From 1.0.x to 2.0

1. Do everything in [From 1.1 to 2.0](#from-11-to-20) except step 3, which does not apply to 1.0.x.
2. Also follow the steps in [1.0.x → 1.1](#10x--11) below, because they apply to anyone coming from 1.0.x, with these exceptions:
    - install `@ably/laravel-echo@^2` instead of `^1.1`;
    - skip the `ABLY_MAU_ACCOUNT` / `mau_account` parts (its step 4 and the `mau_account` line in [New config keys](#new-config-keys)): 2.0 has no such option, and guests always get a client id (on MAU-billed accounts too).

    Those steps cover `ably/pubsub-server`, the new network hosts, the `ABLY_KEY` requirement, `useTokenAuth`, `environment`/`restHost`, renamed `Ably\…` classes and the behaviour changes. Its requirements (PHP 8.1 or later, Laravel 8 or later) also apply to 2.0.
3. Installing and building the client needs Node.js 22 or later (the `engines` field of Echo and `@ably/pubsub-device`) and a bundler that understands ES2022 syntax (for example webpack 5 or Vite). <!-- TODO(G3): confirm against the Echo v2 docs (Echo PHASE-15) -->
4. Guests now get a clientId with no configuration. On a MAU-billed account nothing else is required. To change the prefix, add `'guest_id_prefix' => env('ABLY_GUEST_ID_PREFIX', 'guest:'),` to the `ably` connection in `config/broadcasting.php`, because the package ships no config file. See [Guest client ids](#guest-client-ids).

<a id="guest-client-ids"></a>

### Guest client ids

Every visitor who is not logged in (a guest) gets a clientId. There is nothing to configure, also on Ably accounts billed by monthly active users (MAU).

- The id has the form `<prefix><random>.<tag>`, for example `guest:AAAAAAAAAAAAAAAAAAAAAA.Frvly-6FIwOMDXRwx1ErFw`. `<random>` is 22 characters, the base64url encoding of 16 random bytes. `<tag>` is a 22-character HMAC of `<random>` made with your API key secret, and the broadcaster recognises its own ids by it.
- On routes with a session, the id is stable for the session: the broadcaster keeps it in the session as `ably_guest_client_id`. An auth request from a signed-in user with a broadcast identifier drops the stored id, so the next guest after a logout gets a fresh one.
- A signed-in user whose broadcast identifier (`getAuthIdentifierForBroadcasting()`, or `getAuthIdentifier()`) is `null` gets a clientId exactly like a guest. On routes with a session and no resolver, the user keeps the session's guest id across requests and token renewals.
- On routes without a session (for example `Broadcast::routes(['middleware' => ['api']])`), nothing is stored: the id is reused from the client's token while that token is valid and its tag is correct. Otherwise a new id is generated.
- Guests can only use public channels. Private and presence channels still need a signed-in user.
- On an MAU-billed account, each guest id counts as one monthly active user, and the logout of a user with a broadcast identifier starts a new one.

#### Change the prefix

The prefix defaults to `guest:`. It is cosmetic and not reserved, so a real user id that starts with it is unaffected. The package ships no config file, so set `ABLY_GUEST_ID_PREFIX` and add the line to the `ably` connection in `config/broadcasting.php`. Re-run `php artisan config:cache` if you cache config.

```dotenv
ABLY_GUEST_ID_PREFIX=visitor-
```

```php
        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
            'guest_id_prefix' => env('ABLY_GUEST_ID_PREFIX', 'guest:'),
        ],
```

Changing the prefix, or rotating `ABLY_KEY`, gives each guest a new id at its next token request.

#### Use your own guest ids

To use your own guest id scheme, register a resolver, for example in a service provider's `boot()`. Do not put a closure in the config file, because it breaks `php artisan config:cache`.

```php
\Ably\LaravelBroadcaster\AblyBroadcaster::resolveGuestClientIdUsing(function ($request) {
    // Server-side state only: a random id stored in the session, one per visitor
    if (! $request->session()->has('visitor_id')) {
        $request->session()->put('visitor_id', 'visitor:'.\Illuminate\Support\Str::random(32));
    }

    return $request->session()->get('visitor_id');
});
```

This example needs a session on the auth route.

- **Its id replaces the built-in one entirely.** The resolver receives the request and must return a non-empty id other than `*`. The id is used as-is: `guest_id_prefix` and the tag are not applied, and nothing is stored as `ably_guest_client_id`.
- **It is called for every guest auth request**, including one from a signed-in user whose broadcast identifier is `null`. It is never called for a user with a broadcast identifier.
- **Keep the id stable across calls** for the same visitor, because each token renewal re-checks the identity.
- **Your resolver decides the id after a logout.** The built-in id is dropped from the session when a signed-in user with a broadcast identifier makes an auth request, so the next guest gets a fresh one. A resolver's id gets no such handling, so end or replace it on logout yourself.
- **Never derive the id from client input** such as cookies, headers or request parameters. A client can set those to any value, including another visitor's id.
- **Namespace the id** (for example `visitor:`) so it can never equal a real user id such as `5`, and make it **unique per visitor**, because one id is one monthly active user.

### Things to know in 2.0

- **The `ably` driver is this package's.** If its service provider is not registered, or the broadcaster is resolved before it boots, Laravel uses its built-in Pusher-protocol driver instead, silently on Laravel 13.41 and later. See [Laravel's built-in `ably` driver](#laravel-built-in-ably-driver).
- **`->toOthers()` works as in 1.1.1.** See [Behaviour changes](#behaviour-changes) for how it uses the `X-Socket-ID` header that `@ably/laravel-echo` sends, and when it fails with `BroadcastException`.
- **MAU-billed accounts.** A connection without a clientId is rejected with 40161. With 2.0 this only happens when the token does not come from this broadcaster. Echo v2 logs this hint, after its prefix `@ably/laravel-echo: the Ably connection failed (40161: …).`:
  > Your Ably account requires an identified client on every connection (MAU billing), but this connection has no clientId. Use laravel-broadcaster v2, which always issues guest client ids, or make sure your auth endpoint returns a token with a clientId.
  <!-- TODO(T3): re-check the hint text against the Echo v2 release -->

<a id="staying-on-1x"></a>

### Staying on 1.x

- Composer keeps apps on `^1.1` on 1.x. 1.x gets patch releases from the `1.x` branch.
- 1.x keeps the `ABLY_MAU_ACCOUNT` option, see [MAU-billed Ably accounts](#mau-billed-ably-accounts). Pair it with `@ably/laravel-echo` 1.1.x.

<a id="laravel-built-in-ably-driver"></a>

## Laravel's built-in `ably` driver

This applies to every install, new or upgraded. Laravel also ships its own `ably` broadcast driver. It speaks the Pusher protocol and is used with the stock `laravel-echo` and `pusher-js` (or set up by `php artisan install:broadcasting --ably`). Once this package is installed, package discovery registers `Ably\LaravelBroadcaster\LaravelAblyBroadcasterServiceProvider`, and every connection in `config/broadcasting.php` with `'driver' => 'ably'` uses this package's broadcaster instead.

### How the two drivers differ

| | This package | Laravel's built-in `ably` driver |
|---|---|---|
| Client | `@ably/laravel-echo` | `laravel-echo` + `pusher-js` (Ably Pusher adapter) |
| Auth | Ably token (JWT) with clientId and capabilities | Pusher-style HMAC signature |
| Guests on MAU-billed accounts | guest clientIds | no clientId |
| `Broadcast::userRoutes()` (Pusher user authentication) | not supported | not supported |

`@ably/laravel-echo` does not work with the built-in driver, which refuses its token request (403) because that request has no channel name. This package's channel names use Ably's prefixes (`public:`, `private:`, `presence:`). Laravel's `private-encrypted-` channels are not special-cased, so they publish as `private:encrypted-…`.

### Which connections change

Only connections with `'driver' => 'ably'`. Connections with any other driver are untouched, including the default connection when it is not an `ably` one.

### Move the client in the same deploy

If you already use Laravel's built-in `ably` driver, switch the client to [`@ably/laravel-echo`](https://github.com/ably-forks/laravel-echo) (see [Using Laravel Echo on client-side](README.md#using-laravel-echo-on-client-side)) in the same deploy as this package. A Pusher-protocol client (`laravel-echo` with `pusher-js`) cannot authorize private and presence channels against this broadcaster, and `->toOthers()` fails with a `BroadcastException` because a Pusher socket id is not an `@ably/laravel-echo` socket id; see [Behaviour changes](#behaviour-changes).

### If the provider is not registered

Laravel uses its own built-in `ably` driver instead, which does not work with `@ably/laravel-echo`. This happens when the package is listed in `extra.laravel.dont-discover` in your `composer.json`, when `bootstrap/cache/packages.php` is stale (for example after `composer install --no-scripts`), or when another service provider resolves the broadcaster before this package has booted. On Laravel 13.41 and later it happens silently: Laravel switches to the framework's `Ably2Broadcaster`, which speaks the Pusher protocol, so there is no error, but `@ably/laravel-echo` cannot use it. On earlier versions it fails with an error that mentions `Class "Ably\AblyRest" not found`, unless `ably/ably-php` 1.x is installed, in which case it is silent too. So that no provider resolves the broadcaster too early, define your channels (`Broadcast::channel()`) in `routes/channels.php` (on Laravel 11 and later loaded through `withRouting(channels: …)`) or in your app's own service providers, such as `App\Providers\BroadcastServiceProvider`: both run after package providers have booted.

### How to check

Run `php artisan tinker` and then:

```php
get_class(Broadcast::connection('ably'));
// must return "Ably\LaravelBroadcaster\AblyBroadcaster"
```

If it returns anything else, make sure the package is not in `dont-discover`, then run `php artisan package:discover`.

### `install:broadcasting` on Laravel 11 and later

`php artisan install:broadcasting` publishes the config, creates `routes/channels.php` and registers it in `withRouting` too, but it also writes a Pusher or Reverb client to `resources/js/echo.js` and installs `laravel-echo` and `pusher-js`. If you ran it, replace `resources/js/echo.js` with the Ably Echo setup (see [Using Laravel Echo on client-side](README.md#using-laravel-echo-on-client-side)), and uninstall `laravel-echo` and `pusher-js` if nothing else uses them. Do **not** use `install:broadcasting --ably` (Laravel 12 and later): it sets up Laravel's built-in Pusher-protocol driver and its client.

<a id="10x--110"></a>

## 1.0.x → 1.1

> This section describes the upgrade to 1.1. If you are upgrading to 2.0, start with [Upgrading to 2.0](#upgrading-to-20): the `ABLY_MAU_ACCOUNT` steps below do not apply to 2.0.

Version 1.1 moves from `ably/ably-php` 1.x to [`ably/pubsub-server`](https://github.com/ably/ably-pubsub-php) 2.0, whose clients declare the server side as monthly-active-user (MAU) pricing requires. It is a minor release: the documented `config/broadcasting.php` options keep working unchanged, and every new behaviour is off by default except the fixes listed under [Behaviour changes](#behaviour-changes).

For the client side (`@ably/laravel-echo`), see the [Echo UPDATING.md](https://github.com/ably-forks/laravel-echo/blob/HEAD/UPDATING.md#install-and-the-ably-global). Use `@ably/laravel-echo` 1.1 or later with this release. It is required before you enable `ABLY_MAU_ACCOUNT`, see [Steps](#steps).

### Steps

1. **Network / firewall first:** allow the new hosts, see [Network requirements](#network-requirements).
2. Update: `composer update ably/laravel-broadcaster`. If your `composer.json` pins `1.0.*` or `~1.0.0` (e.g. after the rollback below), run `composer require ably/laravel-broadcaster:^1.1` instead. Add `-W` only if `rybakit/msgpack` 0.10/0.11 is locked (`ably/pubsub-server` currently constrains it to 0.9.x); `composer why-not ably/laravel-broadcaster 1.1.0` shows what blocks the update.
3. Keep `ABLY_KEY` set, see [Requirements](#requirements-and-client-options).
4. Optional: add the new config lines, see [New config keys](#new-config-keys). On an MAU-billed Ably account this is required, see [MAU-billed Ably accounts](#mau-billed-ably-accounts). **Upgrade `@ably/laravel-echo` to 1.1 or later, and rebuild and deploy your assets (step 5), before setting `ABLY_MAU_ACCOUNT=true`.**
5. Client side: run `npm install @ably/laravel-echo@^1.1` and rebuild assets (the `ably` package and `window.Ably` are no longer needed). `broadcast(...)->toOthers()` needs `@ably/laravel-echo` >= 1.0.6, and ably-js <= 1.2.35 is sunset. Installing and building needs Node.js 22 or later, see the [Echo UPDATING.md](https://github.com/ably-forks/laravel-echo/blob/HEAD/UPDATING.md#requirements).
6. If you construct `AblyBroadcaster` yourself, pass a client created with `\Ably\PubSub\Server::createHttpClient()`; any other value throws `InvalidArgumentException`.
7. If you reference `Ably\…` SDK classes (e.g. in a subclass), rename them to `Ably\PubSub\…`. `AblyRest` is the exception: it becomes `Ably\PubSub\PubSubHttpClient`, created with `\Ably\PubSub\Server::createHttpClient()` (see step 6). For example, `buildAblyMessage()` overrides must return `Ably\PubSub\Models\Message`, and `Utils::decodeSocketId()` throws `Ably\PubSub\Exceptions\AblyException` (`broadcast()` still throws `BroadcastException`). Rename only code that uses this package's classes or the new SDK. Code that still uses a retained `ably/ably-php` 1.x client (step 8) keeps the `Ably\…` names, including `Ably\Exceptions\AblyException` in its catch blocks.
8. `ably/ably-php` is no longer installed for you. If you use `Ably\AblyRest` directly:
    - On an MAU-billed account, migrate those calls to `\Ably\PubSub\Server::createHttpClient()`. Ably rejects ably-php 1.x on MAU-enabled accounts.
    - On other accounts, you may instead require `ably/ably-php:^1.1` yourself.
    - Do not bump `ably/ably-php` to 2.x. This package declares a conflict with it.
9. Make sure this package's service provider is registered. Package discovery does it by default; check that the package is not listed in `extra.laravel.dont-discover`. If it is not registered, Laravel's built-in `ably` driver is used instead, and that driver does not work with `@ably/laravel-echo`. See [Laravel's built-in `ably` driver](#laravel-built-in-ably-driver) for what happens on each Laravel version and how to check.
10. If you set `environment` or `restHost` as client options, switch to `endpoint` (`port` and `tlsPort` still work), see the SDK's [UPDATING.md](https://github.com/ably/ably-pubsub-php/blob/main/UPDATING.md).
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
| 40168 | 400 | The SDK does not declare its side, e.g. the `ably` (ably-js) package. Use `@ably/laravel-echo` >= 1.1, which uses `@ably/pubsub-device`. |

Version 1.0.x is rejected on MAU-billed accounts. `ABLY_MAU_ACCOUNT` makes the broadcaster issue a guest clientId to each unauthenticated user. It is required on MAU-billed accounts, and you may enable it on other accounts too.

To serve guests on an MAU-billed account, set `ABLY_MAU_ACCOUNT=true` and add the lines from [New config keys](#new-config-keys). Guests can only use public channels, because private and presence channels need a signed-in user. Each guest counts as one monthly active user on your Ably account.

The broadcaster issues a guest clientId in the form `<prefix><random>.<tag>`:

- `<random>` is 22 characters, the base64url encoding of 16 random bytes.
- `<tag>` is a 22-character signature of `<random>` (an HMAC made with your API key secret). It lets the broadcaster recognise the ids it issued.
- The prefix defaults to `guest:`. Change it with `ABLY_GUEST_ID_PREFIX`. The prefix is only used when `ABLY_MAU_ACCOUNT=true`.

The prefix is cosmetic and **not reserved**. The broadcaster recognises the guest ids it issued by their tag alone, so a real user id that starts with `guest:` is legal and unaffected.

A guest id is stable for the session, so one visitor counts as one MAU.

- The broadcaster keeps the id in the session as `ably_guest_client_id`.
- On routes without a session (such as API routes), the broadcaster reuses the guest id from the client's current token, if that token is still valid and the id's tag is correct. Otherwise it generates a new id.
- A signed-in user's auth request drops the stored guest id. On routes with a session, a guest after logout therefore gets a **fresh id**. On routes without a session, a tab that never received the user's token keeps its unexpired guest id.
- With the built-in guest ids, changing `ABLY_GUEST_ID_PREFIX` or rotating `ABLY_KEY` gives each guest a **new id** at its next token request, whether or not the route has a session.
- Known minor effect: on a brand-new session, two tabs that connect at the same moment can briefly get different guest ids. The tab whose id was replaced switches to the session's id at its next token renewal, with a brief reconnect. One extra MAU is counted.

#### Your own guest id scheme

To use your own scheme, register a resolver, for example in a service provider.

- The resolver receives the request. It must return a non-empty id other than `*`.
- The broadcaster uses the returned id as-is. It adds no prefix or tag.
- Ids from a resolver skip the built-in handling (a fresh id after logout, the tag check), so your app must end or replace them on logout.
- In 1.1 the resolver is only called when `mau_account` is `true`.
- Do not put a closure in the config file, because it breaks `php artisan config:cache`.

```php
  \Ably\LaravelBroadcaster\AblyBroadcaster::resolveGuestClientIdUsing(function ($request) {
      // Server-side state only: a random id stored in the session, one per visitor
      if (! $request->session()->has('visitor_id')) {
          $request->session()->put('visitor_id', 'visitor:'.\Illuminate\Support\Str::random(32));
      }

      return $request->session()->get('visitor_id');
  });
```

The id must be unique per visitor, because one id is one monthly active user. Never take it from client input such as cookies, headers or request parameters, because a client can set those to any value, including another visitor's id. This example needs a session on the route.

#### Login without a page reload

Logging in without reloading the page (for example in a single-page app) moves the connection from one clientId to another. This needs `@ably/laravel-echo` 1.1 or later. With an older Echo, guests connect, but an in-page login breaks the connection until the page is reloaded. See the [Echo UPDATING.md](https://github.com/ably-forks/laravel-echo/blob/HEAD/UPDATING.md#login-user-switch-and-logout-in-the-same-page) for the client-side steps, and turn the flag on only after Echo 1.1 or later is deployed.

#### Logout without a page reload

After a logout without a page reload, the page keeps the old user's identity and its access to the private and presence channels it is already subscribed to. This lasts until its current token expires (up to `token_expiry`, 8 hours by default).

Your app should therefore call `Echo.connect()` (then subscribe to the channels again) or `Echo.disconnect()` as part of logout. That closes the connection immediately, so the old token is no longer used. See the [Echo UPDATING.md](https://github.com/ably-forks/laravel-echo/blob/HEAD/UPDATING.md#logout-without-a-page-reload) for the client-side steps.

If the app does nothing, Echo 1.1 reconnects once as the guest (or with no client ID if `ABLY_MAU_ACCOUNT` is off) at the next token renewal. Public channels keep working, and each private and presence channel reports one 403.

A shorter `ABLY_TOKEN_EXPIRY` shortens this window, at the cost of more token requests to your server: each client renews its token more often, and each renewal also re-checks the private and presence channels in the token, see [Behaviour changes](#behaviour-changes). Ably can also revoke a user's tokens by clientId, but this needs an API key with revocable tokens enabled, which limits every token to at most 1 hour (so `ABLY_TOKEN_EXPIRY` must be 3600 or less); see https://ably.com/docs/auth/revocation.

#### Switching users

When another user signs in on the same page, the connection also moves from one clientId to another. This needs `@ably/laravel-echo` 1.1 or later. The new user does not inherit the previous user's channels, see [Behaviour changes](#behaviour-changes).

#### Other open tabs

Other open tabs switch to the new identity at their next token renewal, which is within `token_expiry`. A tab switches earlier if, after the login, it authorizes a private or presence channel that its token does not cover. With an older Echo, other tabs fail with a clientId mismatch (40102) at their next renewal, and only a page reload recovers them.

#### If the flag is off on an MAU-billed account

If `ABLY_MAU_ACCOUNT` is left off on an MAU-billed account, guests fail with 40161. `@ably/laravel-echo` logs this hint in the browser console. The README it names is the Echo README.

> Your Ably account requires an identified client on every connection (MAU billing), but this guest connected without a clientId. In laravel-broadcaster set ABLY_MAU_ACCOUNT=true (config broadcasting.connections.ably.mau_account) so guests receive a clientId, or only connect Echo for signed-in users. See "Guest users on MAU-billed Ably accounts" in the README.

### Behaviour changes

- **`->toOthers()` uses the `X-Socket-ID` header that `@ably/laravel-echo` sends.** If the header is absent (the request did not come through Echo), nothing is excluded and the sender receives its own event. There is no error. From 1.1.1 the same applies to the socket id that `@ably/laravel-echo` 1.0.6 to 1.1.1 sends before its connection is up (it has no `connectionKey`). Any other value that is not Echo's base64url JSON, such as a Pusher socket id, makes the broadcast fail with a `BroadcastException`. For apps moving from Laravel's built-in driver, see [Laravel's built-in `ably` driver](#laravel-built-in-ably-driver).
- **Channel access resets on identity change (always on).** The broadcaster reuses a token's expiry and channel access only for the same clientId. When the clientId changes (for example on logout, or when another user signs in), the new identity starts with public channels plus the channel being authorized. It does not inherit the previous user's private and presence access. A renewal for the same clientId keeps its channel access.
- **Token renewal issues a fresh expiry.** Before, renewing a connection token (a request with no channel name) for the same user returned the same token again, and Ably kept asking until it disconnected with 40142. Now the renewal gets a fresh expiry (default 28800 s, 8 hours, `ABLY_TOKEN_EXPIRY`). The broadcaster also re-checks each private and presence channel in the old token against `routes/channels.php`, and honours an `ably-capability` that your callback returns. Guests keep public channels only. An expired token cannot be re-checked: its renewal gets public channels only, and Echo re-authorizes private and presence channels as they report 40160.
- **Revocation takes effect at the next renewal.** A channel the callback no longer authorizes is dropped from the renewed token; a token already issued keeps its access until it expires or is renewed. If a channel's authorization callback throws during a renewal, that channel is also left out of the new token. Echo authorizes it again, and if that fails too, `error()` receives the failure. The app can subscribe again.
- **Channels on a non-default guard need that guard on the broadcast route.** A renewal has no channel name, so the broadcaster takes the user from the default guard. If users of your private or presence channels are authenticated only on a non-default guard (a channel defined with `['guards' => ['admin']]`), add that guard to the route, for example `Broadcast::routes(['middleware' => ['web', 'auth:admin']])`. Otherwise each token renewal briefly switches them to a guest, or to no client ID when `ABLY_MAU_ACCOUNT` is off. The next channel authorization switches them back.
- **No cap on re-checked channels.** The broadcaster checks the old token's signature first, so the token lists only channels this broadcaster already granted to that user. A limit would only cause extra 40160 re-authorization requests. Each renewal costs one callback per channel, so consider Laravel's rate limiting on the `/broadcasting/auth` route.
- **Security note on channel names:** the requested channel name is used as it is for the Ably channel permission, after Laravel route matching on the name without its `private:` or `presence:` prefix. Ably treats `*` as a wildcard only as a whole `:`-delimited segment (for example `private:*` or `private:chat.1:*`), which matches every channel under that prefix. A `*` inside a segment (for example `private:chat.*`) is a literal channel name. Laravel's `{param}` placeholders match any characters except `.`, including `*` and `:`. So a route such as `{name}` accepts `private:*`, `chat:{id}` accepts `private:chat:*`, and `chat.{id}` accepts `private:chat.1:*`. On Laravel 8 the match is looser: it turns each placeholder into `*`, which also matches dots, so `chat.{id}` can match `private:chat.1.extra:*`, and the callback receives only `1` as `$id`. Constrain route parameters in your `channels.php` callbacks (for example with `ctype_digit($id)`, or `preg_match('/^\d+$/D', (string) $id)`) and only authorize the channels you intend. On Laravel 8 this is not enough, because the callback never sees the full name: also check `request('channel_name')` when it is set, for example `$name = request('channel_name'); if ($name !== null && ! preg_match('/^private:chat\.\d+$/D', $name)) { return false; }`. A token renewal has no `channel_name` and only re-checks channels already granted, so the check is skipped there. That also means a token issued before you add the check keeps its channels at every renewal; rotate `ABLY_KEY` to invalidate such tokens. Upgrading to Laravel 9 or later removes this case.

### Network requirements

From 1.1 the broadcaster calls `main.realtime.ably.net` and falls back to `main.[a-e].fallback.ably-realtime.com` (1.0.x used `rest.ably.io` and `[a-e].ably-realtime.com`). Allow `*.realtime.ably.net` and `*.fallback.ably-realtime.com` in egress firewalls and proxies. Proxies are configured with `http_proxy` / `https_proxy` in `$_SERVER`, as before.

- If these hosts are blocked, publishing fails with `BroadcastException`. If packets are dropped rather than rejected, each publish can block for about 16 seconds (4 s connect timeout × up to 4 hosts), so check queue job timeouts.
- With `sync_server_time` enabled, the broadcaster requests Ably's server time (`GET /time`) when it is created and its cached value is missing or older than 6 hours (e.g. after a fresh deploy). Apps that use `ably` as the default broadcaster and load `routes/channels.php` create the broadcaster while booting, so blocked egress then fails every request and artisan command, not only auth and broadcasting.
- Browsers: legacy ably-js 1.x still connects to `realtime.ably.io` / `*.ably-realtime.com`, which DNS now points (CNAME) at the new domains. Allowlists that match on resolved names or addresses need the new domains too.

### 2.0

2.0 has been released: it always issues guest client IDs, removes `ABLY_MAU_ACCOUNT`, and requires `@ably/laravel-echo` v2. See [Upgrading to 2.0](#upgrading-to-20).

### Staying on 1.0.x

On PHP 7.2 – 8.0 or Laravel 6 / 7, Composer keeps you on `ably/laravel-broadcaster` 1.0.x. It keeps working on accounts without MAU pricing, but 1.0.x is **rejected on MAU-enabled accounts** and only receives critical fixes, on the `1.0.x` branch, until about October 2027. To roll back, require `ably/laravel-broadcaster:1.0.*` again (and `ably/ably-php:^1.1` if you use it directly) and remove the `mau_account` and `guest_id_prefix` lines, which 1.0.x ignores.

For the client side, see "Rolling back" in the [Echo UPDATING.md](https://github.com/ably-forks/laravel-echo/blob/HEAD/UPDATING.md#rolling-back).
