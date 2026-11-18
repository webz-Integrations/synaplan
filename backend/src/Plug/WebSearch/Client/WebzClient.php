<?php

declare(strict_types=1);

namespace App\Plug\WebSearch\Client;

use App\Plug\PlugConfigService;
use App\Plug\PlugKeyStore;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * POST https://api.webz.io/api/news/context.
 *
 * The token is sent only as an Authorization header, and only after the
 * endpoint is confirmed to be https. Redirects are not followed, so the
 * header is not forwarded to another host.
 */
final readonly class WebzClient
{
    private const ENDPOINT = 'https://api.webz.io/api/news/context';

    public function __construct(
        private HttpClientInterface $httpClient,
        private PlugKeyStore $keys,
        private PlugConfigService $plugConfig,
    ) {
    }

    public static function assertHttpsEndpoint(string $url): void
    {
        $parts = parse_url($url);
        $scheme = \is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = \is_array($parts) ? ($parts['host'] ?? '') : '';
        if ('https' !== $scheme || '' === $host) {
            throw new \RuntimeException('The Webz.io News Search endpoint must be an absolute https:// URL.');
        }
    }

    public function hasKey(): bool
    {
        $key = $this->keys->getKey('webz');

        return null !== $key && '' !== $key;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function search(array $body): array
    {
        self::assertHttpsEndpoint(self::ENDPOINT);

        $key = $this->keys->getKey('webz');
        if (null === $key || '' === $key) {
            throw new \RuntimeException('Webz.io API token is not configured');
        }

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'timeout' => $this->plugConfig->webSearchTimeoutMs() / 1000,
                'max_redirects' => 0,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$key,
                ],
                'json' => $body,
            ]);
            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            throw new \RuntimeException($this->publicFailure('Webz.io News Search request failed', $key, $e));
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Webz.io News Search returned HTTP '.$status);
        }

        try {
            $payload = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new \RuntimeException($this->publicFailure('Webz.io News Search returned a response that was not JSON', $key, $e));
        }

        return $payload;
    }

    public function probe(): void
    {
        $this->search([
            'query' => 'synaplan',
            'k' => 1,
        ]);
    }

    private function publicFailure(string $summary, string $token, \Throwable $error): string
    {
        $detail = $error->getMessage();
        if ('' !== $token && str_contains($detail, $token)) {
            return $summary;
        }
        $detail = trim($detail);
        if ('' === $detail) {
            return $summary;
        }
        if (strlen($detail) > 200) {
            $detail = substr($detail, 0, 200);
        }

        return $summary.': '.$detail;
    }
}
