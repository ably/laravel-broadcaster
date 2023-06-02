<?php

namespace Ably\LaravelBroadcaster;

use Ably\AblyRest;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Ably\Utils\Miscellaneous;

class LaravelAblyBroadcasterServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        Broadcast::extend('ably', function ($broadcasting, $config) {
            AblyRest::setAblyAgentHeader('laravel-broadcaster', AblyBroadcaster::LIB_VERSION);
            $laravelVersion = Miscellaneous::getNumeric(app()->version());
            AblyRest::setAblyAgentHeader('laravel', $laravelVersion);
            return new AblyBroadcaster(new AblyRest($config), $config);
        });
    }
}
