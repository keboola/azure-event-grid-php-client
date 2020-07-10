# Azure Event Grid PHP Client [![Build Status](https://dev.azure.com/keboola-dev/azure-event-grid-php-client/_apis/build/status/keboola.azure-event-grid-php-client?branchName=master)](https://dev.azure.com/keboola-dev/azure-event-grid-php-client/_build/latest?definitionId=12&branchName=master) [![Maintainability](https://api.codeclimate.com/v1/badges/fe983803eb7d71a87a34/maintainability)](https://codeclimate.com/github/keboola/azure-event-grid-php-client/maintainability) [![Test Coverage](https://api.codeclimate.com/v1/badges/fe983803eb7d71a87a34/test_coverage)](https://codeclimate.com/github/keboola/azure-event-grid-php-client/test_coverage)

PHP client for [Azure Event Grid](https://docs.microsoft.com/en-us/rest/api/eventgrid/).

Supports the following:

- **Publish Events** [Endpoint spec](https://docs.microsoft.com/en-us/rest/api/eventgrid/dataplane/publishevents/publishevents)

## Installation

    composer require keboola/azure-event-grid-php-client

## Usage

Create client instance and encrypt data:

```php
$client = new Client(
    new GuzzleClientFactory($logger),
    new AuthenticatorFactory(),
    'https://connection-events.northeurope-1.eventgrid.azure.net/api/events'
);
```

## Development

Run tests with:

    docker-compose run --rm testsXX

where XX is PHP version (56 - 74), e.g.:

    docker-compose run --rm tests70

### Resources Setup

Create a resource group:

	az group create --name testing-azure-event-grid-php-client --location "northeurope"

Deploy the event grid:

	az group deployment create --resource-group testing-azure-event-grid-php-client --template-file arm-template.json --location "northeurope"

optionally parameter `topicName` can be set to override default topic name

Get endpoint url:

    az resource show -g testing-azure-event-grid-php-client --resource-type "Microsoft.EventGrid/topics"

returns properties.endpoint set it as TEST_TOPIC_ENDPOIND
