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
use Psr\Log\NullLogger;
use Psr\Log\Test\TestLogger;

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
     * @param array $options
     * @param string $expectedMessage
     */
    public function testInvalidOptions(array $options, $expectedMessage): void
    {
        $factory = new GuzzleClientFactory(new NullLogger());
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage($expectedMessage);
        $factory->getClient('http://example.com', $options);
    }

    public function invalidOptionsProvider(): array
    {
        return [
            'invalid-options' => [
                [
                    'non-existent' => 'foo',
                    'accessKey' => '123',
                ],
                'Invalid options when creating client: non-existent. Valid options are: backoffMaxTries, userAgent, handler, logger, accessKey.',
            ],
            'invalid-backoff' => [
                [
                    'backoffMaxTries' => 'foo',
                    'accessKey' => '123',
                ],
                'Invalid options when creating client: Value "foo" is invalid: This value should be a valid number.',
            ],
            'missing-accessKey' => [
                [
                ],
                'Access key is not set.',
            ],
        ];
    }

    public function testInvalidUrl(): void
    {
        $factory = new GuzzleClientFactory(new NullLogger());
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('boo');
        $factory->getClient('boo', ['accessKey' => '123']);
    }

    public function testLogger(): void
    {
        $logger = new TestLogger();
        $factory = new GuzzleClientFactory(new NullLogger());
        $client = $factory->getClient('https://example.com', [
            'logger' => $logger,
            'userAgent' => 'test-client',
            'accessKey' => '123',
        ]);
        $client->get('');
        $this->assertTrue($logger->hasInfoThatContains('test-client - ['));
        $this->assertTrue($logger->hasInfoThatContains('"GET  /1.1" 200'));
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
            $this->assertContains('Client error: `GET https://example.com` resulted in a `403 Forbidden` response', $e->getMessage());
        }

        $this->assertCount(1, $requestHistory);
        /** @var Request $request */
        $request = $requestHistory[0]['request'];
        $this->assertEquals('https://example.com', $request->getUri()->__toString());
    }

    public function testRetryDeciderRetryFail(): void
    {
        $mock = new MockHandler([
            new Response(
                501,
                [],
                'boo'
            ),
            new Response(
                501,
                [],
                'boo'
            ),
            new Response(
                501,
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
            'backoffMaxTries' => 2,
            'accessKey' => '123',
        ]);
        try {
            $client->get('');
            $this->fail('Must throw exception');
        } catch (ServerException $e) {
            $this->assertContains('Server error: `GET https://example.com` resulted in a `501 Not Implemented`', $e->getMessage());
        }

        $this->assertCount(3, $requestHistory);
        /** @var Request $request */
        $request = $requestHistory[0]['request'];
        $this->assertEquals('https://example.com', $request->getUri()->__toString());
        $request = $requestHistory[1]['request'];
        $this->assertEquals('https://example.com', $request->getUri()->__toString());
        $request = $requestHistory[2]['request'];
        $this->assertEquals('https://example.com', $request->getUri()->__toString());
    }

    public function testRetryDeciderRetrySuccess(): void
    {
        $mock = new MockHandler([
            new Response(
                501,
                [],
                'boo'
            ),
            new Response(
                501,
                [],
                'boo'
            ),
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
        $logger = new TestLogger();
        $factory = new GuzzleClientFactory($logger);
        $client = $factory->getClient('https://example.com', [
            'handler' => $stack,
            'userAgent' => 'test-client',
            'backoffMaxTries' => 2,
            'accessKey' => '123',
        ]);
        $client->get('');

        $this->assertCount(3, $requestHistory);
        /** @var Request $request */
        $request = $requestHistory[0]['request'];
        $this->assertEquals('https://example.com', $request->getUri()->__toString());
        $request = $requestHistory[1]['request'];
        $this->assertEquals('https://example.com', $request->getUri()->__toString());
        $request = $requestHistory[2]['request'];
        $this->assertEquals('https://example.com', $request->getUri()->__toString());
        $this->assertTrue($logger->hasWarningThatContains('Request failed (Server error: `GET https://example.com` resulted in a `501 Not Implemented`'));
        $this->assertTrue($logger->hasWarningThatContains('retrying (1 of 2)'));
    }

    public function testRetryDeciderThrottlingRetrySuccess(): void
    {
        $mock = new MockHandler([
            new Response(
                429,
                [],
                'boo'
            ),
            new Response(
                429,
                [],
                'boo'
            ),
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
        $logger = new TestLogger();
        $factory = new GuzzleClientFactory($logger);
        $client = $factory->getClient('https://example.com', [
            'handler' => $stack,
            'userAgent' => 'test-client',
            'backoffMaxTries' => 2,
            'accessKey' => '123',
        ]);
        $client->get('');

        $this->assertCount(3, $requestHistory);
        /** @var Request $request */
        $request = $requestHistory[0]['request'];
        $this->assertEquals('https://example.com', $request->getUri()->__toString());
        $request = $requestHistory[1]['request'];
        $this->assertEquals('https://example.com', $request->getUri()->__toString());
        $request = $requestHistory[2]['request'];
        $this->assertEquals('https://example.com', $request->getUri()->__toString());
        $this->assertTrue($logger->hasWarningThatContains('Request failed (Client error: `GET https://example.com` resulted in a `429 Too Many Requests`'));
        $this->assertTrue($logger->hasWarningThatContains('retrying (1 of 2)'));
    }
}
