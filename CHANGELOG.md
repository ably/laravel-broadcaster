# Changelog

## [v1.1.0](https://github.com/ably/laravel-broadcaster/tree/v1.1.0)

[Full Changelog](https://github.com/ably/laravel-broadcaster/compare/v1.0.8...v1.1.0)

**Guests and logouts now work on Ably accounts billed by monthly active users (MAU). Token renewal and channel access are safer.**

On an Ably account with MAU pricing, 1.0.x does not work. Ably rejects the requests that the old `ably/ably-php` client makes. It also rejects visitors who are not logged in, because they have no client id. A user who logs out without a page reload ends up with a failed connection. 1.1.0 fixes this. It moves the broadcaster to [`ably/pubsub-server`](https://github.com/ably/ably-pubsub-php) 2.0, which tells Ably that it runs on a server. It can also give each guest a stable client id. This is opt-in and off by default.

The release also fixes two problems that affect every account. A renewed connection token came back unchanged, so the client kept asking for a new one and Ably finally disconnected it with error 40142. And a new identity could keep the previous user's private and presence channel access until that user's token expired.

1.0.x stays available for apps that cannot upgrade yet. It receives critical fixes until about October 2027. It works on accounts without MAU pricing and is rejected on MAU accounts. Composer keeps apps on PHP below 8.1, or on Laravel 6 or 7, on 1.0.x. To stay on it, or to roll back, see [Staying on 1.0.x](UPDATING.md#staying-on-10x).

Please refer to [UPDATING.md](UPDATING.md#10x--11) for the full details and the upgrade steps. Below is an overview of the main changes.

### Before you upgrade

1. **Allow the new Ably hosts** in your egress firewall and proxy: `*.realtime.ably.net` and `*.fallback.ably-realtime.com`. If you do not, publishing fails with a `BroadcastException`. [\#71](https://github.com/ably/laravel-broadcaster/pull/71)
2. **Check your versions.** You need PHP 8.1 or later and Laravel 8 or later. On older versions, Composer keeps you on 1.0.x. [\#71](https://github.com/ably/laravel-broadcaster/pull/71)
3. **Keep `ABLY_KEY` set.** A missing or empty key now fails with Ably error 40103. [\#72](https://github.com/ably/laravel-broadcaster/pull/72)
4. **Upgrade the Echo client, `@ably/laravel-echo`, to 1.1.0, and rebuild and deploy your assets, before enabling `ABLY_MAU_ACCOUNT`.** With the flag on and an older Echo, in-page login and other open tabs fail. See [Steps](UPDATING.md#steps).
5. **On an MAU-billed account**, add the new config lines and set `ABLY_MAU_ACCOUNT=true`, see below. If you do not, guests cannot connect (error 40161).
6. **Update with Composer, then restart.** Run `composer update ably/laravel-broadcaster`. If your `composer.json` pins `1.0.*`, run `composer require ably/laravel-broadcaster:^1.1` instead. After you deploy, restart queue workers, Horizon and Octane. If you construct `AblyBroadcaster` yourself or reference `Ably\…` classes, see steps 6, 7 and 9 in [Steps](UPDATING.md#steps). [\#71](https://github.com/ably/laravel-broadcaster/pull/71)

### MAU-billed accounts and guest client ids

- Visitors who are not logged in can now connect on an MAU-billed account. Set `ABLY_MAU_ACCOUNT=true` (config `mau_account`, default `false`) and each guest gets a stable guest client id instead of `null`. Each guest counts as one monthly active user. Guests can only use public channels. [\#72](https://github.com/ably/laravel-broadcaster/pull/72)
- Add the flag on MAU-billed accounts. On other accounts it is optional. With the flag off, guests keep a `null` client id, as in 1.0.x.
- **Logout without a page reload.** With `ABLY_MAU_ACCOUNT=true` and `@ably/laravel-echo` 1.1.0, the user is moved to a fresh guest id at the next token renewal instead of ending with a failed connection. Without the flag, a logged-out user has no client id and an MAU account rejects them (error 40161). Until the renewal, the page keeps the old user's private and presence access, so call `Echo.connect()` or `Echo.disconnect()` when the user logs out. See [Logout without a page reload](UPDATING.md#logout-without-a-page-reload).
- `ABLY_GUEST_ID_PREFIX` (config `guest_id_prefix`, default `guest:`) sets the visible start of a guest id. A real user id that starts with the prefix is not affected.
- **Add these lines to the `ably` connection in `config/broadcasting.php`.** The package ships no config file, so `ABLY_MAU_ACCOUNT` and `ABLY_GUEST_ID_PREFIX` in `.env` do nothing until you do. Without the lines, your app keeps the 1.0.x behaviour, and a test covers this. See [New config keys](UPDATING.md#new-config-keys). [\#74](https://github.com/ably/laravel-broadcaster/pull/74)
  ```php
  'mau_account' => env('ABLY_MAU_ACCOUNT', false),
  'guest_id_prefix' => env('ABLY_GUEST_ID_PREFIX', 'guest:'),
  ```
- More detail, a way to use your own guest ids, and the Ably error codes are in [MAU-billed Ably accounts](UPDATING.md#mau-billed-ably-accounts).

### Safer channel access

- **Security fix:** a new identity no longer inherits the previous user's private and presence channel access. Before, after a logout or when another user signed in, the new identity could keep that access until the old token expired. Now it starts with public channels plus the channel being authorized. [\#72](https://github.com/ably/laravel-broadcaster/pull/72)
- A renewed token keeps only the private and presence channels that `routes/channels.php` still allows for the user. A channel you revoke is dropped at the next renewal. A forged, expired or malformed token does not keep any channel access. [\#73](https://github.com/ably/laravel-broadcaster/pull/73)
- If your private channels use a guard other than the default one, add that guard to the broadcast route. If you do not, each renewal briefly makes those users guests (or users with no client id when `ABLY_MAU_ACCOUNT` is off). See [Behaviour changes](UPDATING.md#behaviour-changes).
- **Check your channel route patterns.** The requested channel name is used as given for the Ably permission. A route such as `{name}` accepts `private:*`, which grants access to every channel under that prefix. Constrain route parameters in `routes/channels.php`. See [Behaviour changes](UPDATING.md#behaviour-changes). [\#73](https://github.com/ably/laravel-broadcaster/pull/73)

### Fewer auth requests and no forced reconnect

- Connections stay up. A renewed token now gets a fresh expiry. Before, it was identical, so in tests a client sent about 5 auth requests per second for the last 30 seconds of each token. Ably then disconnected it with error 40142, and its private and presence channels had to re-authorize. This was hidden by the old 8-hour default and showed up with shorter expiries. Now there is about one request per renewal. [\#73](https://github.com/ably/laravel-broadcaster/pull/73)
- This needs no change in the Echo client (`@ably/laravel-echo`). Echo 1.0.x and 1.1.0 both benefit.

### Other changes to know about

- `useTokenAuth` is now ignored. The broadcaster always signs in to Ably with `ABLY_KEY`, because on MAU accounts Ably rejects server tokens that are not marked as coming from a server (error 40167). See [Requirements and client options](UPDATING.md#requirements-and-client-options). [\#72](https://github.com/ably/laravel-broadcaster/pull/72)
- If you set the client options `environment` or `restHost`, switch to `endpoint`. The new client does not read the old options. [\#71](https://github.com/ably/laravel-broadcaster/pull/71)
- If you use `Ably\AblyRest` yourself, `ably/ably-php` is no longer installed for you. See step 8 in [Steps](UPDATING.md#steps). [\#71](https://github.com/ably/laravel-broadcaster/pull/71)
- The new hosts are `main.realtime.ably.net`, with fallbacks on `main.[a-e].fallback.ably-realtime.com`. See [Network requirements](UPDATING.md#network-requirements). [\#71](https://github.com/ably/laravel-broadcaster/pull/71)

## [v1.0.8](https://github.com/ably/laravel-broadcaster/tree/v1.0.8)

[Full Changelog](https://github.com/ably/laravel-broadcaster/compare/v1.0.7...v1.0.8)

**Laravel 13 support**

- Add support for laravel 13 [\#68](https://github.com/ably/laravel-broadcaster/pull/68)

## [v1.0.7](https://github.com/ably/laravel-broadcaster/tree/v1.0.7)

[Full Changelog](https://github.com/ably/laravel-broadcaster/compare/v1.0.6...v1.0.7)

**Laravel 12 support**

- Add support for laravel 12 [\#58](https://github.com/ably/laravel-broadcaster/issues/58)

**Merged pull requests:**

- \[ECO-5208\] Support laravel 12 [\#59](https://github.com/ably/laravel-broadcaster/pull/59) ([sacOO7](https://github.com/sacOO7))
- docs\(README\): capability operations section links [\#57](https://github.com/ably/laravel-broadcaster/pull/57) ([guspan-tanadi](https://github.com/guspan-tanadi))

## [v1.0.6](https://github.com/ably/laravel-broadcaster/tree/v1.0.6)

[Full Changelog](https://github.com/ably/laravel-broadcaster/compare/v1.0.5...v1.0.6)

**Fixed bugs:**

- Using -\>toOthers\(\) results in a "Ably error: Malformed message; invalid connectionKey" Same as issue 38 [\#48](https://github.com/ably/laravel-broadcaster/issues/48)
- Issue with client ID when using SPA like inertia [\#45](https://github.com/ably/laravel-broadcaster/issues/45)

**Closed issues:**

- Fix base64 decode  [\#51](https://github.com/ably/laravel-broadcaster/issues/51)
- Monitor channel connection/disconnect [\#47](https://github.com/ably/laravel-broadcaster/issues/47)
- Using -\>toOthers\(\) results in a "Ably error: Malformed message; invalid connectionKey" [\#38](https://github.com/ably/laravel-broadcaster/issues/38)

**Merged pull requests:**

- \[ECO-4977\] Fix/broadcast to others [\#50](https://github.com/ably/laravel-broadcaster/pull/50) ([sacOO7](https://github.com/sacOO7))
- Update README [\#49](https://github.com/ably/laravel-broadcaster/pull/49) ([sacOO7](https://github.com/sacOO7))

## [v1.0.5](https://github.com/ably/laravel-broadcaster/tree/v1.0.5)

[Full Changelog](https://github.com/ably/laravel-broadcaster/compare/v1.0.4...v1.0.5)

**Closed issues:**

- Update Ably Laravel Broadcaster to support Laravel 11 [\#42](https://github.com/ably/laravel-broadcaster/issues/42)
- Update README to use ably-js version \< 2.0 [\#40](https://github.com/ably/laravel-broadcaster/issues/40)

**Merged pull requests:**

- Support laravel 11 [\#43](https://github.com/ably/laravel-broadcaster/pull/43) ([sacOO7](https://github.com/sacOO7))
- Fix readme laravel echo [\#41](https://github.com/ably/laravel-broadcaster/pull/41) ([sacOO7](https://github.com/sacOO7))

## [v1.0.4](https://github.com/ably/laravel-broadcaster/tree/v1.0.4)

[Full Changelog](https://github.com/ably/laravel-broadcaster/compare/v1.0.3...v1.0.4)

**Closed issues:**

- Add missing agent header for laravel version [\#32](https://github.com/ably/laravel-broadcaster/issues/32)
- Need Laravel 10 support [\#29](https://github.com/ably/laravel-broadcaster/issues/29)

**Merged pull requests:**

- Removed non-documented capability key check  [\#34](https://github.com/ably/laravel-broadcaster/pull/34) ([sacOO7](https://github.com/sacOO7))
- Added laravel agent header  [\#33](https://github.com/ably/laravel-broadcaster/pull/33) ([sacOO7](https://github.com/sacOO7))
- Update laravel echo doc [\#31](https://github.com/ably/laravel-broadcaster/pull/31) ([sacOO7](https://github.com/sacOO7))
- Update README ably clientOptions [\#30](https://github.com/ably/laravel-broadcaster/pull/30) ([sacOO7](https://github.com/sacOO7))

## [v1.0.3](https://github.com/ably/laravel-broadcaster/tree/v1.0.3)

[Full Changelog](https://github.com/ably/laravel-broadcaster/compare/v1.0.2...v1.0.3)

**Closed issues:**

- Laravel 10 compatibility  [\#24](https://github.com/ably/laravel-broadcaster/issues/24)

**Merged pull requests:**

- PHP 8.2 Support [\#26](https://github.com/ably/laravel-broadcaster/pull/26) ([AndyTWF](https://github.com/AndyTWF))
- Add Laravel 10 support [\#25](https://github.com/ably/laravel-broadcaster/pull/25) ([koossaayy](https://github.com/koossaayy))
- Fix readme clientOptions [\#23](https://github.com/ably/laravel-broadcaster/pull/23) ([sacOO7](https://github.com/sacOO7))

## [v1.0.2](https://github.com/ably/laravel-broadcaster/tree/1.0.2)

[Full Changelog](https://github.com/ably/laravel-broadcaster/compare/v1.0.1...v1.0.2)

**Implemented enhancements:**

- Improve response time for token-signing [\#15](https://github.com/ably/laravel-broadcaster/pull/15) ([qsdstefan](https://github.com/qsdstefan))
- Add `ABLY_SYNC_SERVER_TIME` time option [\#19](https://github.com/ably/laravel-broadcaster/pull/19) ([sacOO7](https://github.com/sacOO7))

**Merged pull requests:**

- Refactor capability key for a channel [\#21](https://github.com/ably/laravel-broadcaster/pull/21) ([sacOO7](https://github.com/sacOO7))
- Update migration doc [\#20](https://github.com/ably/laravel-broadcaster/pull/20) ([sacOO7](https://github.com/sacOO7))
- Remove "socket" key from message data [\#18](https://github.com/ably/laravel-broadcaster/pull/18) ([kwhohasamullet](https://github.com/kwhohasamullet))


## [v1.0.1](https://github.com/ably/laravel-broadcaster/tree/1.0.1)

[Full Changelog](https://github.com/ably/laravel-broadcaster/compare/v1.0.0...v1.0.1)

**Merged pull requests:**

- Update README with support for Pusher compatible broadcasters [\#10](https://github.com/ably/laravel-broadcaster/pull/10) ([sacOO7](https://github.com/sacOO7))
- Fix ably-echo import in README [\#11](https://github.com/ably/laravel-broadcaster/pull/11) ([sacOO7](https://github.com/sacOO7))
- Ably-Agent header support [\#7](https://github.com/ably/laravel-broadcaster/pull/7) ([sacOO7](https://github.com/sacOO7))

## [v1.0.0](https://github.com/ably/laravel-broadcaster/tree/1.0.0)

**Merged pull requests:**

- Add initial laravel broadcaster for Ably [\#1](https://github.com/ably-forks/laravel-echo/pull/1) ([sacOO7](https://github.com/sacOO7))
