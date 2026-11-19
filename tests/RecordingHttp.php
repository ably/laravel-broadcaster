<?php

namespace Ably\LaravelBroadcaster\Tests;

use Ably\PubSub\Http;

/**
 * Records the last request and answers every call like GET /time.
 */
class RecordingHttp extends Http
{
    public $lastUrl;

    public $lastHeaders;

    public function request($method, $url, $headers = [], $params = [])
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;

        return ['headers' => "HTTP/1.1 200 OK\n", 'body' => [round(microtime(true) * 1000), 0]];
    }
}
