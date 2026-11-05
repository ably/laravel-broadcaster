<?php

namespace Ably\LaravelBroadcaster\Tests;

use Ably\LaravelBroadcaster\AblyBroadcaster;
use Ably\LaravelBroadcaster\AblyClientFactory;
use Ably\LaravelBroadcaster\LaravelAblyBroadcasterServiceProvider;
use Ably\LaravelBroadcaster\Utils;
use Ably\PubSub\Defaults;
use Ably\PubSub\Exceptions\AblyException;
use Ably\PubSub\PubSubHttpClient;
use Ably\PubSub\Server;
use Ably\PubSub\Utils\Miscellaneous;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Broadcast;

class AblyClientFactoryTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [LaravelAblyBroadcasterServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('broadcasting.connections.ably', [
            'driver' => 'ably',
            'key' => 'abcd:efgh',
            'httpClass' => RecordingHttp::class,
            'logLevel' => 0, // keep Ably's own stdout logger quiet
        ]);
    }

    private static function client(array $config): PubSubHttpClient
    {
        return AblyClientFactory::make(array_merge(
            ['key' => 'abcd:efgh', 'httpClass' => RecordingHttp::class, 'logLevel' => 0],
            $config
        ));
    }

    private static function expectedHeader(): string
    {
        return 'ably-pubsub-php/'.Defaults::LIB_VERSION.' php/'.Miscellaneous::getNumeric(phpversion())
            .' laravel-broadcaster/'.AblyBroadcaster::LIB_VERSION.' '.Server::SERVER_AGENT_IDENTIFIER;
    }

    public function testServiceProviderBuildsClientThroughServerFactory()
    {
        $broadcaster = Broadcast::connection('ably');
        self::assertInstanceOf(AblyBroadcaster::class, $broadcaster);

        $client = (new \ReflectionProperty(AblyBroadcaster::class, 'ably'))->getValue($broadcaster);
        self::assertInstanceOf(PubSubHttpClient::class, $client);
        self::assertSame(self::expectedHeader(), $client->ablyAgentHeader());
    }

    public function testAgentsAreOursAndServerSide()
    {
        $client = self::client([]);
        self::assertSame(
            ['laravel-broadcaster' => AblyBroadcaster::LIB_VERSION, Server::SERVER_AGENT_IDENTIFIER => null],
            $client->options->agents
        );
        self::assertSame(self::expectedHeader(), $client->ablyAgentHeader());
    }

    public function testUserAgentsAreOverwritten()
    {
        foreach ([['my-wrapper' => '1.0', 'laravel' => '3.0'], 'oops', [Server::SERVER_AGENT_IDENTIFIER => '9']] as $agents) {
            self::assertSame(self::expectedHeader(), self::client(['agents' => $agents])->ablyAgentHeader());
        }
    }

    public function testExceptionTypes()
    {
        try {
            AblyClientFactory::make(['driver' => 'ably', 'logLevel' => 0]); // no key
            self::fail('expected AblyException');
        } catch (AblyException $e) {
            self::assertSame(40103, $e->getCode());
        }

        $broadcaster = new AblyBroadcaster(self::client([]), []);
        $this->expectException(BroadcastException::class);
        $this->expectExceptionMessage('Ably error: Base64 decoding failed, '.Utils::SOCKET_ID_ERROR);
        $broadcaster->broadcast(['channel1'], 'evt', ['socket' => 'invalid_socket_id']);
    }

    public function testMissingKeyAlwaysFailsWithClearError()
    {
        $withoutKey = [
            'driver' => 'ably',
            'logLevel' => 0,
        ];
        foreach ([
            [],
            ['key' => ''],
            ['key' => null],
            ['key' => '  '],
            ['authUrl' => 'https://example.com/auth'],
            ['token' => 'some-token'],
            ['authCallback' => 'strtoupper', 'key' => ''],
        ] as $extra) {
            try {
                AblyClientFactory::make(array_merge($withoutKey, $extra));
                self::fail('expected AblyException');
            } catch (AblyException $e) {
                self::assertSame(40103, $e->getCode());
                self::assertStringContainsString('`key`', $e->getMessage());
                self::assertStringContainsString('ABLY_KEY', $e->getMessage());
                self::assertStringContainsString('signs client tokens with the Ably API key', $e->getMessage());
                self::assertStringContainsString('`authUrl`', $e->getMessage());
            }
        }
    }

    public function testPublishFailureIsWrapped()
    {
        $broadcaster = new AblyBroadcaster(self::client(['httpClass' => HttpErrorMock::class]), []);
        $this->expectException(BroadcastException::class);
        $this->expectExceptionMessage('Ably error: boom');
        $broadcaster->broadcast(['c'], 'e', []);
    }

    public function testConstructorRejectsAnythingButPubSubHttpClient()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ably\PubSub\Server::createHttpClient()');
        new AblyBroadcaster(new \stdClass, []);
    }
}
