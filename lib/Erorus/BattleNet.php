<?php

namespace Erorus;

use Exception;
use GuzzleHttp\Client;

class BattleNet {
    public const OPT_MODIFIED_SINCE = 'If-Modified-Since';

    public const REGION_US = 'us';
    public const REGION_EU = 'eu';
    public const REGION_TW = 'tw';
    public const REGION_KR = 'kr';

    private const REGIONS = [self::REGION_US, self::REGION_EU, self::REGION_TW, self::REGION_KR];

    private const API_HOST_FORMAT = 'https://%s.api.blizzard.com';
    private const TOKEN_URI = 'https://us.battle.net/oauth/token';

    /** @var Client */
    private $guzzle;

    /** @var string[]  */
    private $lastResponseHeaders = [];

    /** @var string */
    private $key;

    /** @var string */
    private $secret;

    /** @var string */
    private $token;

    public function __construct(string $key, string $secret) {
        $this->key = $key;
        if (!$this->key) {
            throw new Exception("Empty key supplied.");
        }

        $this->secret = $secret;
        if (!$this->secret) {
            throw new Exception("Empty secret supplied.");
        }
    }

    /**
     * Returns a parsed API response at the given path, or null when not found.
     *
     * @param string $region
     * @param string $path
     * @param array $options
     * @return null|object
     */
    public function fetch(string $region, string $path, array $options = []): ?object {
        if (!in_array($region, self::REGIONS)) {
            throw new Exception("Invalid region: {$region}");
        }
        if (substr($path, 0, 1) !== '/') {
            throw new Exception("Path must begin with a slash.");
        }

        $queryString = '';
        $pos = strpos($path, '?');
        if ($pos !== false) {
            $queryString = substr($path, $pos + 1);
            $path = substr($path, 0, $pos);
        }
        parse_str($queryString, $qsa);
        if (!isset($qsa['namespace'])) {
            $qsa['namespace'] = 'dynamic-' . $region;
        }
        if (!isset($qsa['locale'])) {
            $qsa['locale'] = 'en_US';
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->getToken(),
            'Accept-Encoding' => 'gzip',
        ];
        if (isset($options[self::OPT_MODIFIED_SINCE])) {
            $dt = new \DateTime();
            $dt->setTimezone(new \DateTimeZone('UTC'));
            $dt->setTimestamp($options[self::OPT_MODIFIED_SINCE]);
            $headers['If-Modified-Since'] = $dt->format(\DateTimeInterface::RFC7231);
        }

        $client = $this->getGuzzle();
        $res = $client->get(sprintf(self::API_HOST_FORMAT, $region) . $path, [
            'headers' => $headers,
            'query' => $qsa,
        ]);

        $this->lastResponseHeaders = [
            'http-status' => $res->getStatusCode(),
        ];
        foreach ($res->getHeaders() as $name => $values) {
            $this->lastResponseHeaders[strtolower($name)] = count($values) === 1 ? $values[0] : $values;
        }
        ksort($this->lastResponseHeaders);

        if ($res->getStatusCode() !== 200) {
            return null;
        }

        $body = (string)$res->getBody();
        $data = json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Could not parse API response json: " . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Returns the headers that came with the last fetch() response.
     *
     * @return string[]
     */
    public function getLastResponseHeaders(): array {
        return $this->lastResponseHeaders;
    }

    /**
     * Returns our Guzzle client for all calls.
     *
     * @return Client
     */
    private function getGuzzle(): Client {
        if (!isset($this->guzzle)) {
            $this->guzzle = new Client([
                'timeout' => 15.0,
            ]);
        }

        return $this->guzzle;
    }

    /**
     * Returns our client credentials OAuth token.
     *
     * @return string
     */
    private function getToken(): string {
        if ($this->token) {
            return $this->token;
        }

        $client = $this->getGuzzle();
        $res = $client->get(self::TOKEN_URI, [
            'auth' => [$this->key, $this->secret],
            'headers' => [
                'Accept' => 'application/json',
            ],
            'query' => [
                'grant_type' => 'client_credentials',
            ]
        ]);

        if ($res->getStatusCode() !== 200) {
            throw new Exception('Invalid status code when fetching client credentials token.');
        }

        $body = (string)$res->getBody();
        $data = json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Could not parse client credentials body json: " . json_last_error_msg());
        }

        if (isset($data->error)) {
            throw new Exception("Client credentials response included error: " . $data->error);
        }

        if (!isset($data->access_token)) {
            throw new Exception("Client credentials response did not include the access token.");
        }

        return $this->token = $data->access_token;
    }
}
