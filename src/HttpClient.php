<?php

/**
 * Simple HTTP client.  Not PSR complient.
 *
 * Use methods get(), post() to retrieve HttpResponse objects.
 *
 * Uses caching if set up.
 **/

declare(strict_types=1);

namespace Umonkey;

use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

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

    /**
     * Cache TTL, if used.
     *
     * @var int
     **/
    protected $cacheTtl;

    /**
     * Cache size limit.
     *
     * @var int
     **/
    protected $cacheLimit;

    /**
     * Cache interface (PSR-16).
     *
     * @var CacheInterface
     **/
    protected $cache;

    /**
     * User agent string.
     *
     * @var string
     **/
    protected $userAgent;

    /**
     * @var int
     **/
    protected $throttle

    public function __construct(LoggerInterface $logger, CacheInterface $cache, $settings)
    {
        $this->logger = $logger;
        $this->callback = null;
        $this->cache = $cache;

        $this->cacheTtl = $settings['httpClient']['cache_ttl'] ?? null;
        $this->cacheLimit = $settings['httpClient']['cache_limit'] ?? null;
        $this->userAgent = $settings['httpClient']['agent'] ?? 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:74.0) Gecko/20100101 Firefox/74.0';
        $this->throttle = $settings['httpClient']['throttle'] ?? 0;
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

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, null, $headers);
    }

    public function post(string $url, $payload, array $headers = []): HttpResponse
    {
        return $this->request('POST', $url, $payload, $headers);
    }

    public function request(string $method, string $url, $payload = null, array $headers = []): HttpResponse
    {
        if (null !== $this->callback) {
            $res = $this->callback($method, $url, $payload, $headers);
            if (!($res instanceof HttpResponse)) {
                throw new \InvalidArgumentException('callback MUST return an HttpResponse instance');
            } else {
                return $res;
            }
        }

        $ts = microtime(true);

        if (isset($this->settings['headers'])) {
            $headers = array_replace($this->settings['headers'], $headers);
        }

        $curlOptions = $this->settings['curl_options'] ?? [];
        $curlOptions[\CURLOPT_URL] = $url;
        $curlOptions[\CURLOPT_HTTPHEADER] = $headers;
        $curlOptions[\CURLOPT_RETURNTRANSFER] = 1;
        $curlOptions[\CURLOPT_FOLLOWLOCATION] = false;

        if (isset($this->settings['proxy'])) {
            $curlOptions[\CURLOPT_PROXY] = $this->settings['proxy'];
        }

        if ($method === 'POST') {
            $curlOptions[\CURLOPT_POST] = 1;
            $curlOptions[\CURLOPT_POSTFIELDS] = $payload;
        }

        $res = [
            'status' => null,
            'headers' => [],
            'data' => null,
            'cached' => false,
        ];

        $curlOptions[\CURLOPT_HEADERFUNCTION] = function ($ch, $header) use (&$res) {
            if (preg_match('@^HTTP/[0-9.]+ (\d+) .+@', $header, $m)) {
                $res['status'] = (int)$m[1];
            } elseif (2 == count($parts = explode(':', trim($header), 2))) {
                $k = strtolower($parts[0]);
                $v = trim($parts[1]);
                $res['headers'][$k] = $v;
            }

            return strlen($header);
        });

        $this->throttle($url);

        $ch = curl_init();
        curl_setopt_array($ch, $curlOptions);
        $res['data'] = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \RuntimeException(curl_error($ch));
        }

        $res = new HttpResponse($res);

        $this->logger->debug('http {method} to {url} status={status} type={type} length={length} duration={dur}', [
            'url' => $url,
            'status' => $res->getStatus(),
            'type' => $res->getType(),
            'length' => $res->getLength(),
            'dur' => sprintf('%.2f', microtime(true) - $ts),
        ]);

        return $res;
    }

    public function setCallback($func): void
    {
        if (!is_callable($func)) {
            throw new \InvalidArgumentException('func is not callable');
        }

        $this->callback = $func;
    }

    protected function throttle(string $url): void
    {
        if ($this->throttle) {
            sleep($this->throttle);
        }
    }
}
