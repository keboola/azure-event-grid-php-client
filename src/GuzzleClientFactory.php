<?php

namespace Keboola\AzureEventGridClient;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validation;

class GuzzleClientFactory
{
    private const DEFAULT_USER_AGENT = 'Azure PHP Client';
    private const DEFAULT_BACKOFF_RETRIES = 10;
    private const AZURE_THROTTLING_CODE = 429;
    private const ALLOWED_OPTIONS = ['backoffMaxTries', 'userAgent', 'handler', 'logger', 'accessKey'];

    /** @var LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function getClient(string $baseUrl, array $options = []): GuzzleClient
    {
        $validator = Validation::createValidator();
        $errors = $validator->validate($baseUrl, [new NotBlank(), new Url()]);

        $unknownOptions = array_diff(array_keys($options), self::ALLOWED_OPTIONS);
        if ($unknownOptions) {
            throw new ClientException(sprintf(
                'Invalid options when creating client: %s. Valid options are: %s.',
                implode(', ', $unknownOptions),
                implode(', ', self::ALLOWED_OPTIONS)
            ));
        }

        if (!empty($options['backoffMaxTries'])) {
            $errors->addAll($validator->validate($options['backoffMaxTries'], [new Range(['min' => 0, 'max' => 100])]));
            $options['backoffMaxTries'] = (int) $options['backoffMaxTries'];
        } else {
            $options['backoffMaxTries'] = self::DEFAULT_BACKOFF_RETRIES;
        }
        if (empty($options['userAgent'])) {
            $options['userAgent'] = self::DEFAULT_USER_AGENT;
        }

        $errors->addAll($validator->validate($options['accessKey'], [new NotBlank()]));

        if ($errors->count() !== 0) {
            $messages = [];
            /** @var ConstraintViolationInterface $error */
            foreach ($errors as $error) {
                $messages[] = sprintf('Value "%s" is invalid: %s', $error->getInvalidValue(), $error->getMessage());
            }
            throw new ClientException('Invalid options when creating client: ' . implode("\n", $messages));
        }
        return $this->initClient($baseUrl, $options);
    }

    private function initClient(string $url, array $options = []): GuzzleClient
    {
        // Initialize handlers (start with those supplied in constructor)
        if (isset($options['handler']) && $options['handler'] instanceof HandlerStack) {
            $handlerStack = HandlerStack::create($options['handler']);
        } else {
            $handlerStack = HandlerStack::create();
        }
        // Set exponential backoff
        $handlerStack->push(Middleware::retry($this->createDefaultDecider((int) $options['backoffMaxTries'])));

        // Set client logger
        if (isset($options['logger']) && $options['logger'] instanceof LoggerInterface) {
            $handlerStack->push(Middleware::log(
                $options['logger'],
                new MessageFormatter(
                    '{hostname} {req_header_User-Agent} - [{ts}] "{method} {resource} {protocol}/{version}"' .
                    ' {code} {res_header_Content-Length}'
                )
            ));
        }

        // finally create the instance
        return new GuzzleClient([
            'base_uri' => $url,
            'handler' => $handlerStack,
            'headers' => [
                'User-Agent' => $options['userAgent'],
                'Content-type' => 'application/json',
                'aeg-sas-key' => $options['accessKey'],
            ],
        ]);
    }

    private function createDefaultDecider(int $maxRetries): callable
    {
        return function (
            $retries,
            RequestInterface $request,
            ResponseInterface $response = null,
            $error = null
        ) use ($maxRetries) {
            if ($retries >= $maxRetries) {
                return false;
            }
            $code = null;
            if ($response) {
                $code = (int) $response->getStatusCode();
            } elseif ($error) {
                $code = (int) $error->getCode();
            }
            if (($code >= 400) && ($code < 500) && ($code !== self::AZURE_THROTTLING_CODE)) {
                return false;
            }
            if ($code >= 500 || $code === self::AZURE_THROTTLING_CODE || $error) {
                $this->logger->warning(
                    sprintf(
                        'Request failed (%s), retrying (%s of %s)',
                        empty($error) ? $response->getBody()->getContents() : $error->getMessage(),
                        $retries,
                        $maxRetries
                    )
                );
                return true;
            }
            return false;
        };
    }
}
