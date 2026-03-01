<?php

declare(strict_types=1);

namespace YellParser;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;

class HttpClient
{
    private Client $client;
    private array $defaultHeaders;

    public function __construct(array $config = [])
    {
        $this->defaultHeaders = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive',
        ];

        $this->client = new Client(array_merge([
            'timeout' => 30,
            'allow_redirects' => true,
            'verify' => false,
        ], $config));
    }

    public function get(string $url, array $headers = []): ?string
    {
        try {
            $response = $this->client->get($url, [
                'headers' => array_merge($this->defaultHeaders, $headers),
            ]);

            return $response->getBody()->getContents();
        } catch (RequestException $e) {
            error_log("HTTP Request failed: {$e->getMessage()}");
            return null;
        }
    }

    public function getAsync(string $url, array $headers = []): PromiseInterface
    {
        return $this->client->getAsync($url, [
            'headers' => array_merge($this->defaultHeaders, $headers),
        ]);
    }

    public function post(string $url, array $data = [], array $headers = []): ?string
    {
        try {
            $response = $this->client->post($url, [
                'headers' => array_merge($this->defaultHeaders, $headers),
                'form_params' => $data,
            ]);

            return $response->getBody()->getContents();
        } catch (RequestException $e) {
            error_log("HTTP POST Request failed: {$e->getMessage()}");
            return null;
        }
    }
}
