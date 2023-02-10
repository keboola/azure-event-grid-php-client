<?php

namespace Keboola\AzureEventGridClient\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Keboola\AzureEventGridClient\ClientException;
use Keboola\AzureEventGridClient\GuzzleClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class GuzzleClientFactoryTest extends TestCase
{
    public function testGetClient(): void
    {
        $factory = new GuzzleClientFactory(new NullLogger());
        $client = $factory->getClient('http://example.com', ['accessKey' => '123']);
        $this->assertInstanceOf(Client::class, $client);
        $this->assertInstanceOf(NullLogger::class, $factory->getLogger());
    }

    /**
     * @dataProvider invalidOptionsProvider
     * @param array{backoffMaxTries?:int, userAgent?:string, handler?:HandlerStack, logger?:LoggerInterface, accessKey:string} $options
     */
    public function testInvalidOptions(array $options, string $expectedMessage): void
    {
        $factory = new GuzzleClientFactory(new NullLogger());
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage($expectedMessage);
        $factory->getClient('http://example.com', $options);
    }

    /**
     * @return \Generator<array<mixed>>
     */
    public function invalidOptionsProvider(): \Generator
    {
        yield 'invalid-options' => [
            [
                'non-existent' => 'foo',
                'accessKey' => '123',
            ],
            'Invalid options when creating client: non-existent. Valid options are: backoffMaxTries, userAgent, handler, logger, accessKey.',
        ];
        yield 'invalid-backoff' => [
            [
                'backoffMaxTries' => 'foo',
                'accessKey' => '123',
            ],
            'Invalid options when creating client: Options error: This value should be a valid number.',
        ];
    }

    public function testInvalidUrl(): void
    {
        $factory = new GuzzleClientFactory(new NullLogger());
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Options error: This value is not a valid URL.');
        $factory->getClient('boo', ['accessKey' => '123']);
    }
    public function testDefaultHeader(): void
    {
        $mock = new MockHandler([
            new Response(
                200,
                [],
                'boo'
            ),
        ]);

        $requestHistory = [];
        $history = Middleware::history($requestHistory);
        $stack = HandlerStack::create($mock);
        $stack->push($history);

        $factory = new GuzzleClientFactory(new NullLogger());
        $client = $factory->getClient('https://example.com', [
            'handler' => $stack,
            'userAgent' => 'test-client',
            'accessKey' => '123',
        ]);
        $client->get('');

        $this->assertCount(1, $requestHistory);
        /** @var Request $request */
        $request = $requestHistory[0]['request'];
        $this->assertEquals('https://example.com', $request->getUri()->__toString());
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('test-client', $request->getHeader('User-Agent')[0]);
        $this->assertEquals('123', $request->getHeader('aeg-sas-key')[0]);
        // default header
        $this->assertEquals('application/json', $request->getHeader('Content-type')[0]);
        $this->assertEquals('123', $request->getHeader('aeg-sas-key')[0]);
    }

    public function testRetryDeciderNoRetry(): void
    {
        $mock = new MockHandler([
            new Response(
                403,
                [],
                'boo'
            ),
        ]);

        $requestHistory = [];
        $history = Middleware::history($requestHistory);
        $stack = HandlerStack::create($mock);
        $stack->push($history);

        $factory = new GuzzleClientFactory(new NullLogger());
        $client = $factory->getClient('https://example.com', [
            'handler' => $stack,
            'userAgent' => 'test-client',
            'accessKey' => '123',
        ]);
        try {
            $client->get('');
            $this->fail('Must throw exception');
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $this->assertStringContainsString('Client error: `GET https://example.com` resulted in a `403 Forbidden` response', $e->getMessage());
        }

        $this->assertCount(1, $requestHistory);
        /** @var Request $request */
        $request = $requestHistory[0]['request'];
        $this->assertEquals('https://example.com', $request->getUri()->__toString());
    }
}
