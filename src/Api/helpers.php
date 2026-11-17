<?php
/**
 * Shared plumbing for the API functions: the request singleton, pagination
 * meta, the admin hidden-flag parser and the eBay lookup used by the product
 * detail page. No business rules live here.
 */
declare(strict_types=1);

use App\Http\ApiException;
use App\Http\Request;
use App\Support\Validate;

/** The request object for the current HTTP call. */
function api_request(): Request
{
    static $request = null;
    return $request ??= new Request();
}

/**  */
function api_pagination(array $result): array
{
    return [
        'page'        => $result['page'],
        'per_page'    => $result['per_page'],
        'total'       => $result['total'],
        'total_pages' => $result['total_pages'],
    ];
}

/**  */
function api_hidden_flag(): bool
{
    $body = api_request()->body();
    if (!array_key_exists('is_hidden', $body)) {
        throw ApiException::badRequest('Missing is_hidden');
    }
    Validate::make($body)->boolean('is_hidden')->validate();
    return (bool) $body['is_hidden'];
}

/**  */
function api_usd_to_hkd_rate(): float
{
    $response = @file_get_contents(
        'https://api.exchangerate.fun/latest?base=USD',
        false,
        stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ])
    );
    if ($response === false) {
        error_log('Exchange rate request failed; using fallback rate 7.8');
        return 7.8;
    }

    $data = json_decode($response, true);
    $rate = $data['rates']['HKD'] ?? null;
    if (!is_numeric($rate) || (float) $rate <= 0) {
        error_log('Exchange rate response missing HKD; using fallback rate 7.8');
        return 7.8;
    }

    return (float) $rate;
}

/**  */
function api_ebay_token(): ?string
{
    $cacheFile = '/tmp/ebay_token.json';
    if (is_file($cacheFile)) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['access_token']) && (int) ($cached['expires_at'] ?? 0) > time()) {
            return (string) $cached['access_token'];
        }
    }

    $clientId = (string) config('ebay_client_id');
    $clientSecret = (string) config('ebay_client_secret');
    if ($clientId === '' || $clientSecret === '') {
        error_log('eBay token request skipped: missing credentials');
        return null;
    }

    $response = api_ebay_http_request(
        'POST',
        'https://api.ebay.com/identity/v1/oauth2/token',
        [
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
        ],
        http_build_query([
            'grant_type' => 'client_credentials',
            'scope' => 'https://api.ebay.com/oauth/api_scope',
        ])
    );
    if ($response === null || $response['status'] !== 200) {
        error_log('eBay token request failed with HTTP ' . ($response['status'] ?? 0));
        return null;
    }

    $data = json_decode($response['body'], true);
    if (!is_array($data) || empty($data['access_token'])) {
        error_log('eBay token response did not contain an access token');
        return null;
    }

    $token = (string) $data['access_token'];
    @file_put_contents($cacheFile, json_encode([
        'access_token' => $token,
        'expires_at' => time() + 7200,
    ]), LOCK_EX);
    @chmod($cacheFile, 0600);

    return $token;
}

/**  */
function api_ebay_search(string $title, string $token): ?array
{
    $query = http_build_query([
        'q' => $title,
        'limit' => 5,
        'filter' => 'conditions:{USED}',
    ]);
    $response = api_ebay_http_request(
        'GET',
        'https://api.ebay.com/buy/browse/v1/item_summary/search?' . $query,
        [
            'Authorization: Bearer ' . $token,
            'X-EBAY-C-MARKETPLACE-ID: EBAY_US',
            'Accept: application/json',
        ]
    );
    if ($response === null || $response['status'] !== 200) {
        error_log('eBay search failed with HTTP ' . ($response['status'] ?? 0));
        return null;
    }

    $data = json_decode($response['body'], true);
    $items = $data['itemSummaries'] ?? [];
    if (!is_array($items) || $items === []) {
        return null;
    }

    $prices = [];
    $currency = 'USD';
    foreach ($items as $item) {
        $value = $item['price']['value'] ?? null;
        if (is_numeric($value)) {
            $prices[] = (float) $value;
        }
        if (!empty($item['price']['currency'])) {
            $currency = (string) $item['price']['currency'];
        }
    }
    if ($prices === []) {
        return null;
    }

    return [
        'min' => min($prices),
        'max' => max($prices),
        'currency' => $currency,
        'url' => 'https://www.ebay.com/sch/i.html?_nkw=' . urlencode($title),
    ];
}

/**  */
function api_ebay_http_request(string $method, string $url, array $headers, ?string $body = null): ?array
{
    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => 3,
            'ignore_errors' => true,
        ],
    ];
    if ($body !== null) {
        $options['http']['content'] = $body;
    }

    $responseBody = @file_get_contents($url, false, stream_context_create($options));
    if ($responseBody === false) {
        error_log('eBay request failed: ' . $url);
        return null;
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches)) {
            $status = (int) $matches[1];
        }
    }

    return ['status' => $status, 'body' => $responseBody];
}
