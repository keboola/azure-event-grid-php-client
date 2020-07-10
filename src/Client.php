<?php

namespace Keboola\AzureEventGridClient;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class Client
{
    private const API_VERSION = '2018-01-01';

    /** @var GuzzleClient */
    private $guzzle;

    public function __construct(
        GuzzleClientFactory $clientFactory,
        $topicHostname,
        $accessKey
    ) {
        $handlerStack = HandlerStack::create();
        $this->guzzle = $clientFactory->getClient(
            sprintf('https://%s', $topicHostname),
            [
                'handler' => $handlerStack,
                'accessKey' => $accessKey,
                'backoffMaxTries' => 1,
            ]
        );
    }

    /**
     * @param EventGridEvent[] $events
     */
    public function publishEvents(array $events): void
    {
        $request = new Request(
            'POST',
            sprintf('/api/events?api-version=%s', self::API_VERSION),
            [],
            \GuzzleHttp\json_encode(array_map(static function ($event) {
                return $event->toArray();
            }, $events))
        );
        $this->sendRequest($request);
    }

    private function sendRequest(Request $request): \Psr\Http\Message\ResponseInterface
    {
        try {
            return $this->guzzle->send($request);
        } catch (GuzzleException $e) {
            $this->handleRequestException($e);
            throw new ClientException($e->getMessage(), $e->getCode(), $e);
        }
    }

    private function handleRequestException(GuzzleException $e): void
    {
        if ($e->getResponse() && is_a($e->getResponse(), Response::class)) {
            /** @var Response $response */
            $response = $e->getResponse();
            try {
                $data = \GuzzleHttp\json_decode($response->getBody()->getContents(), true);
            } catch (GuzzleException $e2) {
                // throw the original one, we don't care about e2
                throw new ClientException(trim($e->getMessage()), $response->getStatusCode(), $e);
            }

            if (!empty($data['error']) && !empty($data['error']['message']) && !empty($data['error']['code'])) {
                throw new ClientException(
                    trim($data['error']['code'] . ': ' . $data['error']['message']),
                    $response->getStatusCode(),
                    $e
                );
            }

            if (!empty($data['error']) && is_scalar($data['error'])) {
                throw new ClientException(
                    trim('Request failed with error: ' . $data['error']),
                    $response->getStatusCode(),
                    $e
                );
            }
        }
    }
}
