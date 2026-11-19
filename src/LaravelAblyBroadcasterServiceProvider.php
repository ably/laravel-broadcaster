<?php

namespace Ably\LaravelBroadcaster;

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
            return new AblyBroadcaster(AblyClientFactory::make($config), $config);
        });
    }
}
