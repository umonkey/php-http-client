<?php

declare(strict_types=1);

namespace Umonkey;

use Psr\Log\LoggerInterface;

class HttpClient
{
    /**
     * @var LoggerInterface
     **/
    protected $logger;

    /**
     * Callback for response faking.
     *
     * @var Callable
     **/
    protected $callback;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->callback = null;
    }

    public function buildURL(string $base, array $args = []): string
    {
        $qs = [];

        foreach ($args as $k => $v) {
            $qs[] = $k . '=' . urlencode((string)$v);
        }

        $url = $base;

        if ($qs) {
            $url .= '?' . implode('&', $qs);
        }

        return $url;
    }

    public function fetch(string $url): array
    {
        if (null !== $this->callback) {
            $callback = $this->callback;
            if (is_array($res = $callback($url))) {
                return $res;
            }
        }

        $context = stream_context_create(array(
            "http" => array(
                "method" => "GET",
                "ignore_errors" => true,
                ),
            ));

        $res = array(
            "status" => null,
            "status_text" => null,
            "headers" => array(),
            "data" => @file_get_contents($url, false, $context),
            );

        if (!empty($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('@^HTTP/[0-9.]+ (\d+) (.*)$@', $h, $m)) {
                    $res["status"] = $m[1];
                    $res["status_text"] = $m[2];
                } else {
                    $parts = explode(":", $h, 2);
                    $k = strtolower(trim($parts[0]));
                    $v = trim($parts[1]);
                    $res["headers"][$k] = $v;
                }
            }
        }

        if (false === $res["data"]) {
            $res["error"] = error_get_last();
        }

        return $res;
    }

    public function post(string $url, array $data, array $headers = []): array
    {
        if (is_array($data)) {
            if ($data = $this->buildURL('', $data)) {
                $data = substr($data, 1);
            }

            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        if (!is_string($data) and $data !== null) {
            throw new RuntimeException('post data must be a string');
        }

        $h = '';
        foreach ($headers as $k => $v) {
            $h .= "{$k}: {$v}\r\n";
        }

        $context = stream_context_create($ctx = [
            'http' => [
                'method' => 'POST',
                'header' => $h,
                'content' => $data,
                'ignore_errors' => true,
            ],
        ]);

        $res = [
            'status' => null,
            'status_text' => null,
            'headers' => [],
            'data' => file_get_contents($url, false, $context),
        ];

        foreach ($http_response_header as $h) {
            if (preg_match('@^HTTP/[0-9.]+ (\d+) (.*)$@', $h, $m)) {
                $res['status'] = $m[1];
                $res['status_text'] = $m[2];
            } else {
                $parts = explode(':', $h, 2);
                $k = strtolower(trim($parts[0]));
                $v = trim($parts[1]);
                $res['headers'][$k] = $v;
            }
        }

        return $res;
    }

    public function setCallback($func): void
    {
        $this->callback = $func;
    }
}
