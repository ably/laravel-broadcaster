# Changelog

## [v1.1.0](https://github.com/ably/laravel-broadcaster/tree/v1.1.0)

[Full Changelog](https://github.com/ably/laravel-broadcaster/compare/v1.0.8...v1.1.0)

**Ably SDK upgrade and MAU (monthly active user) support**

- Move to `ably/pubsub-server` ^2.0, replacing `ably/ably-php`. Requires PHP 8.1+ and Laravel 8+. [\#71](https://github.com/ably/laravel-broadcaster/pull/71)
- Add `ABLY_MAU_ACCOUNT` (`broadcasting.connections.ably.mau_account`, default `false`): guests get a stable, HMAC-tagged guest clientId instead of `null`, with the prefix set by `ABLY_GUEST_ID_PREFIX` (default `guest:`). [\#72](https://github.com/ably/laravel-broadcaster/pull/72)
- Security fix: a token's `iat`/`exp`/capability are now reused only for the same clientId, so a new identity no longer inherits the previous user's private and presence channel access. [\#72](https://github.com/ably/laravel-broadcaster/pull/72)
- The `ABLY_KEY` API key is now required, and `useTokenAuth` is ignored (the server client always uses basic auth). [\#72](https://github.com/ably/laravel-broadcaster/pull/72)
- In-page login and other open tabs need `@ably/laravel-echo` >= 1.1.0 when `ABLY_MAU_ACCOUNT` is on. Publish Echo 1.1.0 first or together with this release.

**Deprecated:**

- The default of `ABLY_MAU_ACCOUNT` will change to `true` in 2.0.0. Set it explicitly to keep the current behaviour.

See the README "Upgrading to 1.1" section for the upgrade steps.

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
