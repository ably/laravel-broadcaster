<?php

namespace Ably\LaravelBroadcaster;

use Ably\AblyRest;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class LaravelAblyBroadcasterServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        Broadcast::extend('ably', function ($broadcasting, $config) {
            return new AblyBroadcaster(new AblyRest($config), $config);
        });
    }
}
