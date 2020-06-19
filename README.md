# Simple HTTP client

Very basic HTTP client for personal projects built on Slim framework.

Usage:

```
$settings = [
    'httpClient' => [
        'cache_size_limit' => 1048576,  // 1 MB
        'cache_ttl' => 86400,  // 1 day
    ],
];

$http = new \Umonkey\Http($logger, $settings);

$res = $http->fetch($url);

if ($res->getStatus() !== 200) {
    throw new \RuntimeException('error fetching resource');
}

var_dump($res);
```

In a Slim project, add this to `config/dependencies.php`:

```
$container['http'] = function ($c) {
    return $c['callableResolver']->getClassInstance('Umonkey\HttpClient');
};
```
