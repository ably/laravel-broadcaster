# Ably Broadcaster for Laravel

[![Latest Stable Version](https://poser.pugx.org/ably/laravel-broadcaster/v/stable)](https://packagist.org/packages/ably/laravel-broadcaster)
[![Total Downloads](https://poser.pugx.org/ably/laravel-broadcaster/downloads)](https://packagist.org/packages/ably/laravel-broadcaster)
[![License](https://poser.pugx.org/ably/laravel-broadcaster/license)](https://packagist.org/packages/ably/laravel-broadcaster)

_[Ably](https://ably.com) is the platform that powers synchronized digital experiences in realtime. Whether attending an event in a virtual venue, receiving realtime financial information, or monitoring live car performance data – consumers simply expect realtime digital experiences as standard. Ably provides a suite of APIs to build, extend, and deliver powerful digital experiences in realtime for more than 250 million devices across 80 countries each month. Organizations like Bloomberg, HubSpot, Verizon, and Hopin depend on Ably’s platform to offload the growing complexity of business-critical realtime data synchronization at global scale. For more information, see the [Ably documentation](https://ably.com/docs)._

This adds support for the official [Ably](https://ably.io) broadcaster to the [Laravel](https://laravel.com/) using the native [ably-php](https://github.com/ably/ably-php). This library supports native [ably-js](https://github.com/ably/ably-js) based [ably-laravel-echo](https://github.com/ably-forks/echo) framework at client side. Main aim is to replace old [pusher-client based AblyBroadcaster](https://laravel.com/docs/9.x/broadcasting#client-ably).

## Requirements
1. PHP version >= 7.2.0
2. Laravel version >= 6.0.0

## Installation

You can install the package via composer

```
composer require ably/laravel-broadcaster
```

## Setup

1. In your `.env` file, set `BROADCAST_DRIVER` and `ABLY_KEY`.
```dotenv
BROADCAST_DRIVER=ably
ABLY_KEY=ROOT_API_KEY_COPIED_FROM_ABLY_WEB_DASHBOARD
```

2. The following `.env` variables are optional, but can be added to further modify the behavior of Ably Broadcaster.
```dotenv
ABLY_DISABLE_PUBLIC_CHANNELS=false
ABLY_TOKEN_EXPIRY=3600
```

3. Uncomment `BroadcastServiceProvider` in `config/app.php`, as follows:
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

4. If running Laravel 8 or older, add the following lines in `config/broadcaster.php` to the `connections` array
```php
        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
            'disable_public_channels' => env('ABLY_DISABLE_PUBLIC_CHANNELS', false),
            'token_expiry' => env('ABLY_TOKEN_EXPIRY', 3600)
        ],
```

5. For Laravel 9, you can optionally add the following lines in `config/broadcaster.php` to the `ably` array:
```php
            'disable_public_channels' => env('ABLY_DISABLE_PUBLIC_CHANNELS', false),
            'token_expiry' => env('ABLY_TOKEN_EXPIRY', 3600)
```

## Example code

### Registering channels

You can define channel capabilities for private and presence channels in `routes/channels.php`.

**Private chanel**

For private channels, access is allowed for truthy values and denied for falsy values.
If the response is truthy, it should be in the format of an [Ably capability object](https://ably.com/docs/core-features/authentication#capabi  lity-operations).
```php
Broadcast::channel('channel1', function ($user) {
    return ['capability' => ["subscribe", "history"]];
});
```

**Presence channel**

For presence channels, you can also return data about the user. ([read more](https://laravel.com/docs/9.x/broadcasting#authorizing-presence-channels))
```php
Broadcast::channel('channel2', function ($user) {
    return ['id' => $user->id, 'name' => $user->name, 'capability' => ["subscribe", "presence"]];
});
```

### Using Laravel Echo on client-side

Laravel Echo is a JavaScript library that makes it painless to subscribe to channels and listen for events broadcast by your server-side broadcasting driver. Ably is maintaining a fork of the official laravel-echo module which allows you to use the official [ably-js SDK](https://github.com/ably/ably-js).

1. Install Laravel Echo and Ably:
```
npm install --save-dev @ably/laravel-echo ably
```
2. Add to `resources/js/bootstrap.js`:
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
3. Recompile Laravel assets:
```
npm run dev
```

### Broadcasting messages from server-side

Laravel supports [defining events](https://laravel.com/docs/events#defining-events) on server-side, and [broadcasting](https://laravel.com/docs/broadcasting#broadcasting-events) them at any time, to be [received](https://laravel.com/docs/broadcasting#receiving-broadcasts) by the event listeners. Below is guide on how to send a public message notification that can be received via Laravel Echo on frontend.

1. Create `app/Events/PublicMessageNotification.php` with the following content:
```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PublicMessageNotification implements ShouldBroadcast
{
    public $channel;
    public $message;

    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($channel, $message)
    {
        $this->channel = $channel;
        $this->message = $message;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new Channel($this->channel);
    }
}
```

2. Fire the event from anywhere within your application:
```php
PublicMessageNotification::dispatch($channel, $message);
```
The above event will be sent to all participants of the specified channel.

3. Receive the `PublicMessageNotification` event on frontend:
```js
Echo.channel(channel)
    .listen('PublicMessageNotification', (data) => {
        console.log(data);
        // Sample data: {"channel": "channelName", "message": "messageContent", "socket": null}
    })
```

## Contributing

1. Fork it
2. Create your feature branch (`git checkout -b my-new-feature`)
3. Commit your changes (`git commit -am 'Add some feature'`)
4. Ensure you have added suitable tests and the test suite is passing (run `vendor/bin/phpunit`)
4. Push to the branch (`git push origin my-new-feature`)
5. Create a new Pull Request

## Release Process

This library uses [semantic versioning](http://semver.org/). For each release, the following needs to be done:

* Run [`github_changelog_generator`](https://github.com/skywinder/Github-Changelog-Generator) to automate the update of the [CHANGELOG](./CHANGELOG.md). Once the `CHANGELOG` update has completed, manually change the `Unreleased` heading and link with the current version number such as `1.0.0`. Also ensure that the `Full Changelog` link points to the new version tag instead of the `HEAD`.
* Commit
* Add a tag and push to origin such as `git tag 1.0.0 && git push origin 1.0.0`
* Visit https://github.com/ably/laravel-broadcaster/tags and add release notes for the release including links to the changelog entry.
* Visit https://packagist.org/packages/ably/laravel-broadcaster, log in to Packagist, and click the "Update" button.
