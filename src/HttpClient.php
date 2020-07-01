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
    protected $throttle;

    /**
     * More request headers.
     *
     * @var array
     **/
    protected $headers;

    /**
     * Extra curl options.
     *
     * @var array
     **/
    protected $curl_options;

    /**
     * Proxy settings.
     * Example: socks5://1.2.3.4:567
     *
     * @var string|null
     **/
    protected $proxy;

    /**
     * Last request timestamp.
     *
     * @var float
     **/
    protected $lastRequestTime = 0;

    /**
     * URL rewrite rules.
     *
     * @var array
     **/
    protected $rewrite;

    public function __construct(LoggerInterface $logger, CacheInterface $cache, $settings)
    {
        $this->logger = $logger;
        $this->callback = null;
        $this->cache = $cache;

        $this->cacheTtl = $settings['httpClient']['cache_ttl'] ?? null;
        $this->cacheLimit = $settings['httpClient']['cache_limit'] ?? null;
        $this->userAgent = $settings['httpClient']['agent'] ?? 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:74.0) Gecko/20100101 Firefox/74.0';
        $this->throttle = $settings['httpClient']['throttle'] ?? 0;
        $this->headers = $settings['httpClient']['headers'] ?? [];
        $this->curl_options = $settings['httpClient']['curl_options'] ?? [];
        $this->proxy = $settings['httpClient']['proxy'] ?? null;
        $this->rewrite = $settings['httpClient']['rewrite'] ?? [];
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

    /**
     * Join two urls.
     * Source: https://github.com/fluffy-critter/php-urljoin
     **/
    public function urlJoin(string $base, string $rel): string
    {
        $uses_relative = array('', 'ftp', 'http', 'gopher', 'nntp', 'imap',
            'wais', 'file', 'https', 'shttp', 'mms',
            'prospero', 'rtsp', 'rtspu', 'sftp',
            'svn', 'svn+ssh', 'ws', 'wss');

        $pbase = parse_url($base);
        $prel = parse_url($rel);

        if ($prel === false || preg_match('/^[a-z0-9\-.]*[^a-z0-9\-.:][a-z0-9\-.]*:/i', $rel)) {
            /*
                Either parse_url couldn't parse this, or the original URL
                fragment had an invalid scheme character before the first :,
                which can confuse parse_url
            */
            $prel = array('path' => $rel);
        }

        if (array_key_exists('path', $pbase) && $pbase['path'] === '/') {
            unset($pbase['path']);
        }

        if (isset($prel['scheme'])) {
            if ($prel['scheme'] != $pbase['scheme'] || in_array($prel['scheme'], $uses_relative) == false) {
                return $rel;
            }
        }

        $merged = array_merge($pbase, $prel);

        // Handle relative paths:
        //   'path/to/file.ext'
        // './path/to/file.ext'
        if (array_key_exists('path', $prel) && substr($prel['path'], 0, 1) != '/') {

            // Normalize: './path/to/file.ext' => 'path/to/file.ext'
            if (substr($prel['path'], 0, 2) === './') {
                $prel['path'] = substr($prel['path'], 2);
            }

            if (array_key_exists('path', $pbase)) {
                $dir = preg_replace('@/[^/]*$@', '', $pbase['path']);
                $merged['path'] = $dir . '/' . $prel['path'];
            } else {
                $merged['path'] = '/' . $prel['path'];
            }

        }

        if(array_key_exists('path', $merged)) {
            // Get the path components, and remove the initial empty one
            $pathParts = explode('/', $merged['path']);
            array_shift($pathParts);

            $path = [];
            $prevPart = '';
            foreach ($pathParts as $part) {
                if ($part == '..' && count($path) > 0) {
                    // Cancel out the parent directory (if there's a parent to cancel)
                    $parent = array_pop($path);
                    // But if it was also a parent directory, leave it in
                    if ($parent == '..') {
                        array_push($path, $parent);
                        array_push($path, $part);
                    }
                } else if ($prevPart != '' || ($part != '.' && $part != '')) {
                    // Don't include empty or current-directory components
                    if ($part == '.') {
                        $part = '';
                    }
                    array_push($path, $part);
                }
                $prevPart = $part;
            }
            $merged['path'] = '/' . implode('/', $path);
        }

        $ret = '';
        if (isset($merged['scheme'])) {
            $ret .= $merged['scheme'] . ':';
        }

        if (isset($merged['scheme']) || isset($merged['host'])) {
            $ret .= '//';
        }

        if (isset($prel['host'])) {
            $hostSource = $prel;
        } else {
            $hostSource = $pbase;
        }

        // username, password, and port are associated with the hostname, not merged
        if (isset($hostSource['host'])) {
            if (isset($hostSource['user'])) {
                $ret .= $hostSource['user'];
                if (isset($hostSource['pass'])) {
                    $ret .= ':' . $hostSource['pass'];
                }
                $ret .= '@';
            }
            $ret .= $hostSource['host'];
            if (isset($hostSource['port'])) {
                $ret .= ':' . $hostSource['port'];
            }
        }

        if (isset($merged['path'])) {
            $ret .= $merged['path'];
        }

        if (isset($prel['query'])) {
            $ret .= '?' . $prel['query'];
        }

        if (isset($prel['fragment'])) {
            $ret .= '#' . $prel['fragment'];
        }

        return $ret;
    }

    public function post(string $url, $payload, array $headers = []): HttpResponse
    {
        return $this->request('POST', $url, $payload, $headers);
    }

    public function request(string $method, string $url, $payload = null, array $headers = []): HttpResponse
    {
        $url = $this->rewrite($url);

        if (null !== $this->callback) {
            $res = $this->callback($method, $url, $payload, $headers);
            if (!($res instanceof HttpResponse)) {
                throw new \InvalidArgumentException('callback MUST return an HttpResponse instance');
            } else {
                return $res;
            }
        }

        $cacheKey = ($this->cacheTtl && $this->cacheLimit)
            ? md5(serialize([$method, $url, $payload]))
            : null;

        if ($cacheKey !== null) {
            $item = $this->cache->get($cacheKey);
            if (is_array($item) && strlen($item['data']) < $this->cacheLimit) {
                $item['cached'] = true;
                $res = new HttpResponse($item);

                $this->logger->debug('http {method} to {url} status={status} type={type} length={length} duration=0 (cached)', [
                    'url' => $url,
                    'method' => strtolower($method),
                    'status' => $res->getStatus(),
                    'type' => $res->getType(),
                    'length' => $res->getLength(),
                ]);

                return $res;
            }
        }

        $ts = microtime(true);

        if (isset($this->headers)) {
            $headers = array_replace($this->headers, $headers);
        }

        $curlOptions = $this->curl_options;
        $curlOptions[\CURLOPT_URL] = $url;
        $curlOptions[\CURLOPT_HTTPHEADER] = $headers;
        $curlOptions[\CURLOPT_RETURNTRANSFER] = 1;
        $curlOptions[\CURLOPT_FOLLOWLOCATION] = false;

        if (isset($this->proxy)) {
            $curlOptions[\CURLOPT_PROXY] = $this->proxy;
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
        };

        $this->throttle($url);

        $ch = curl_init();
        curl_setopt_array($ch, $curlOptions);
        $res['data'] = curl_exec($ch);

        if (curl_errno($ch)) {
            $this->logger->error('http {method} to {url} error={error}', [
                'url' => $url,
                'method' => strtolower($method),
                'error' => curl_error($ch),
            ]);
            throw new \RuntimeException('HTTP request failed');
        }

        if ($cacheKey !== null && strlen($res['data']) < $this->cacheLimit) {
            $this->cache->set($cacheKey, $res, $this->cacheTtl);
        }

        $res = new HttpResponse($res);

        $this->logger->debug('http {method} to {url} status={status} type={type} length={length} duration={dur}', [
            'url' => $url,
            'method' => strtolower($method),
            'status' => $res->getStatus(),
            'type' => $res->getType(),
            'length' => $res->getLength(),
            'dur' => sprintf('%.2f', microtime(true) - $ts),
        ]);

        return $res;
    }

    protected function rewrite(string $url): string
    {
        $old = $url;

        foreach ($this->rewrite as $src => $dst) {
            $url = preg_replace($src, $dst, $url);

            if ($url === null) {
                $this->logger->error('http: bad rewrite rule: {0}', [$src]);
                $url = $old;
            } elseif ($url !== $old) {
                $this->logger->debug('http: url rewrite: {src} => {dst}', [
                    'src' => $old,
                    'dst' => $url,
                ]);
                return $url;
            }
        }

        return $url;
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
            $now = microtime(true);
            $passed = $now - $this->lastRequestTime;

            if ($passed < $this->throttle) {
                $delay = $this->throttle - $passed;
                usleep((int)($delay * 1000000));
            }

            $this->lastRequestTime = $now;
        }
    }
}
