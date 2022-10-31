# Ably Broadcaster for Laravel

[![Latest Stable Version](https://poser.pugx.org/ably/laravel-broadcaster/v/stable)](https://packagist.org/packages/ably/laravel-broadcaster)
[![Total Downloads](https://poser.pugx.org/ably/laravel-broadcaster/downloads)](https://packagist.org/packages/ably/laravel-broadcaster)
[![License](https://poser.pugx.org/ably/laravel-broadcaster/license)](https://packagist.org/packages/ably/laravel-broadcaster)

_[Ably](https://ably.com) is the platform that powers synchronized digital experiences in realtime. Whether attending an event in a virtual venue, receiving realtime financial information, or monitoring live car performance data – consumers simply expect realtime digital experiences as standard. Ably provides a suite of APIs to build, extend, and deliver powerful digital experiences in realtime for more than 250 million devices across 80 countries each month. Organizations like Bloomberg, HubSpot, Verizon, and Hopin depend on Ably’s platform to offload the growing complexity of business-critical realtime data synchronization at global scale. For more information, see the [Ably documentation](https://ably.com/docs)._

This implements ably broadcaster as a independent service provider library for [Laravel](https://laravel.com/) using [ably-php](https://github.com/ably/ably-php). This library works with the [ably-js](https://github.com/ably/ably-js) based [ably-laravel-echo](https://github.com/ably-forks/echo) client framework with enhanced features. This project is the successor to the [pusher-client based ably broadcaster](https://laravel.com/docs/9.x/broadcasting#client-ably).

## Features
- Native ably-js support.
- Low latency for client-events.
- Disable public channels.
- Update channel permissions for a user.
- Update token expirty.
- Fully compatible with [pusher/pusher-based](https://laravel.com/docs/9.x/broadcasting#client-ably) broadcasters, see [migrating section](#migrating-from-old-ablybroadcaster)

## Bug Fixes
- Fixes [broadcasting events to others](https://faqs.ably.com/why-isnt-the-broadcast-only-to-others-functionality-working-in-laravel-with-the-ably-broadcaster).
- Fixes intermittent presence leave issue for channel members.

## Requirements
1. PHP version >= 7.2.0
2. Laravel version >= 6.0.0

## Installation

You can install the package via composer

```
composer require ably/laravel-broadcaster
```

## Setup

1. Update `.env` file, set `BROADCAST_DRIVER` as `ably` and specify `ABLY_KEY`.
```dotenv
BROADCAST_DRIVER=ably
ABLY_KEY=ROOT_API_KEY_COPIED_FROM_ABLY_WEB_DASHBOARD
```

2. Uncomment `BroadcastServiceProvider` in `config/app.php`
<pre>
        /*
         * Application Service Providers...
         */
        App\Providers\AppServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
        <b>App\Providers\BroadcastServiceProvider::class,</b>
        App\Providers\EventServiceProvider::class,
        App\Providers\RouteServiceProvider::class,
</pre>

4. If running Laravel 8 or older, edit `config/broadcasting.php`, add `ably` section to the `connections` array
```php
        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY')
        ],
```

Finally, you are ready to install and configure [Ably Laravel Echo](https://github.com/ably-forks/echo/), which will receive the broadcast events on the client-side.

## Using Laravel Echo on client-side

[Ably Laravel Echo](https://github.com/ably-forks/echo/) is a JavaScript library that makes it painless to subscribe to channels and listen for events broadcast by your server-side broadcasting driver. Ably is maintaining a fork of the official laravel-echo module which allows you to use the official [ably-js SDK](https://github.com/ably/ably-js). In this example, we will also install the official ably package:
```
npm install --save-dev @ably/laravel-echo ably
```

Once Echo is installed, you are ready to create a fresh Echo instance in your applications JavaScript. A great place to do this is at the bottom of the `resources/js/bootstrap.js` file that is included with the Laravel framework. By default, an example Echo configuration is already included in this file; however, the default configuration in the `bootstrap.js` file is intended for Pusher. You may copy the configuration below to transition your configuration to Ably.

```js
import Echo from 'laravel-echo';
import * as Ably from 'ably';

window.Ably = Ably;
window.Echo = new Echo({
    broadcaster: 'ably',
});

window.Echo.connector.ably.connection.on(stateChange => {
    if (stateChange.current === 'connected') {
        console.log('connected to ably server');
    }
});
```
You can set custom [clientOptions](https://ably.com/docs/api/realtime-sdk?lang=javascript#client-options) when creating an `Echo` instance.

```
    broadcaster: 'ably',
    authEndpoint: 'http://www.localhost:8000/broadcasting/auth'
      // Additional ably specific options - https://ably.com/docs/api/realtime-sdk?lang=javascript#client-options  
    realtimeHost: 'realtime.ably.com',
    restHost: 'rest.ably.com',
    port: '80',
    echoMessages: true // By default self-echo for published message is false
```
Once you have uncommented and adjusted the Echo configuration according to your needs, you may compile your application's assets:

```shell
npm run dev
```

## Configure advanced features

**1. Modify private/presence channel capability. Default: Full capability**
- Channel access can be changed as per [Channel Capabilities](https://ably.com/docs/core-features/authentication#capability-operations)
```php
  // file - routes/channels.php

  // for private channel (Access is allowed for truthy values and denied for falsy values)
  Broadcast::channel('channel1', function ($user) {
      return ['capability' => ["subscribe", "history"]];
  });
  
  // for presence channel
  Broadcast::channel('channel2', function ($user) {
      return ['id' => $user->id, 'name' => $user->name, 'capability' => ["subscribe", "presence"]];
  });
```

**2. Disable public channels. Default: false**
- Update `ABLY_DISABLE_PUBLIC_CHANNELS`, set as **true** in `.env` file. 
- Update `ably` section under `config/broadcasting.php` with `'disable_public_channels' => env('ABLY_DISABLE_PUBLIC_CHANNELS', false)`

**3. Update token expiry. Default: 3600 seconds (1 hr)**
- Update `ABLY_TOKEN_EXPIRY` in `.env` file. 
- Update `ably` section under `config/broadcasting.php` with `'token_expiry' => env('ABLY_TOKEN_EXPIRY', 3600)`

<a name="migrate-pusher-to-ably"></a>
## Migrating from old AblyBroadcaster
- The current Ably broadcaster is fully compatible with the old Pusher based AblyBroadcaster.
-  The only difference is for **Leaving the channel**, you should use [Ably Channel Namespaces](https://ably.com/docs/general/channel-rules-namespaces) conventions
```js
 // public channel
Echo.channel('channel1');
Echo.leaveChannel("public:channel1");
// private channel
Echo.private('channel2'); 
Echo.leaveChannel("private:channel2")
// presence channel
Echo.join('channel3'); 
Echo.leaveChannel("presence:channel3")
```
instead of [Pusher Channel Conventions](https://pusher.com/docs/channels/using_channels/channels/#channel-types)
```js
 // public channel
Echo.channel('channel1');
Echo.leaveChannel("channel1");
// private channel
Echo.private('channel2'); 
Echo.leaveChannel("private-channel2")
// presence channel
Echo.join('channel3'); 
Echo.leaveChannel("presence-channel3")
```

## Addtional Documentation
- Current README covers basic ably broadcaster+echo configuration for setting up laravel app and getting it running.
- Please take a look at [Laravel Broadcasting Doc](https://laravel.com/docs/broadcasting) for more information on broadcasting and receiving events.

## Example 
- We have created a demo web-chat app using Ably Broadcaster+Echo based on laravel.
- Visit https://github.com/ably-labs/laravel-broadcast-app for detailed information.

<img src="https://github.com/ably-labs/laravel-broadcast-app/raw/main/docs/images/private_room.png" alt="Public room example">

</br>

## Testing
``` bash
composer test
```

## Changelog
Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## Contributing
1. Fork it
2. Create your feature branch (`git checkout -b my-new-feature`)
3. Commit your changes (`git commit -am 'Add some feature'`)
4. Ensure you have added suitable tests and the test suite is passing (run `vendor/bin/phpunit`)
4. Push to the branch (`git push origin my-new-feature`)
5. Create a new Pull Request

## Release Process
This library uses [semantic versioning](http://semver.org/). For each release, the following needs to be done:

1. Create a new branch for the release, named like `release/1.2.4` (where `1.2.4` is what you're releasing, being the new version)
2. Update the lib version in `src/AblyBroadcaster.php`
3. Run [`github_changelog_generator`](https://github.com/skywinder/Github-Changelog-Generator) to automate the update of the [CHANGELOG](./CHANGELOG.md). Once the `CHANGELOG` update has completed, manually change the `Unreleased` heading and link with the current version number such as `1.2.4`. Also ensure that the `Full Changelog` link points to the new version tag instead of the `HEAD`.
4. Commit generated [CHANGELOG.md](./CHANGELOG.md) file.
5. Make a PR against `main`.
6. Once the PR is approved, merge it into `main`.
7. Add a tag and push it to origin - e.g.: `git tag v1.2.4 && git push origin v1.2.4`.
8. Visit https://github.com/ably/laravel-broadcaster/tags and add release notes for the release including links to the changelog entry.
9. Visit https://packagist.org/packages/ably/laravel-broadcaster, log in to Packagist, and click the "Update" button.
