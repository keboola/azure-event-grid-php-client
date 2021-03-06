<?php

declare(strict_types=1);

namespace Keboola\AzureEventGridClient;

final class EventGridEvent
{
    /** @var string */
    private $subject;

    /** @var array<mixed> */
    private $data;

    /** @var string */
    private $eventType;

    /** @var string */
    private $id;

    /**
     * @param array<mixed> $data
     */
    public function __construct(string $id, string $subject, array $data, string $eventType)
    {
        $this->subject = $subject;
        $this->data = $data;
        $this->eventType = $eventType;
        $this->id = $id;
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'data' => $this->data,
            'eventType' => $this->eventType,
            'eventTime' => (new \DateTime('now'))->format('Y-m-d\TH:i:s\Z'),
            'dataVersion' => "1.0",
        ];
    }
}
