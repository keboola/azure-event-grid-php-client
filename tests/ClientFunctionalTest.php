<?php

namespace Keboola\AzureEventGridClient\Tests;

use Keboola\AzureEventGridClient\Client;
use Keboola\AzureEventGridClient\EventGridEvent;
use Keboola\AzureEventGridClient\GuzzleClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class ClientFunctionalTest extends TestCase
{
    public function setUp()
    {
        $envs = ['TEST_TOPIC_ENDPOINT', 'TEST_TOPIC_KEY'];
        foreach ($envs as $env) {
            if (!getenv($env)) {
                throw new RuntimeException(
                    sprintf('At least one of %s environment variables is empty.', implode(', ', $envs))
                );
            }
        }
        parent::setUp();
        putenv('TEST_TOPIC_ENDPOINT=' . trim(getenv('TEST_TOPIC_ENDPOINT')));
        putenv('TEST_TOPIC_KEY=' . trim(getenv('TEST_TOPIC_KEY')));
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testPublishEvents(): void
    {
        $client = new Client(
            new GuzzleClientFactory(new NullLogger()),
            getenv('TEST_TOPIC_ENDPOINT'),
            getenv('TEST_TOPIC_KEY')
        );

        $event = new EventGridEvent('3e9825ed-b7db-44ea-a5c9-a1601fa43e23', 'TestSubject', [
            'Property1' => 'Value1',
            'Property2' => 'Value2',
        ], 'Keboola.EventGridClient.TestEvent');

        $client->publishEvents([$event]);
    }
}
