<?php

/**
 * Simple HTTP response.
 **/

declare(strict_types=1);

namespace = Umonkey;

class HttpResponse
{
    /**
     * @var array
     **/
    protected $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getStatus(): int
    {
        return $this->data['status'];
    }

    public function getType(): string
    {
        $header = $this->data['headers']['content-type'] ?? 'application/octet-stream';
        $parts = explode(';', $header, 2);
        return trim($parts[0]);
    }

    public function getLength(): int
    {
        return strlen($this->data['data']);
    }

    public function getBody(): string
    {
        return $this->data['data'];
    }

    public function getHeader(string $key, ?string $default = null): string
    {
        return $this->data['headers'][strtolower($key)] ?? $default;
    }

    public function isCached(): bool
    {
        return isset($this->data['cached']);
    }
}
