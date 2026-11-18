<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\AI\Credential\ProviderKeyStore;
use App\Plug\PlugConfigService;
use App\Plug\PlugKeyStore;
use App\Plug\WebSearch\Adapter\ExaAdapter;
use App\Plug\WebSearch\Adapter\FirecrawlAdapter;
use App\Plug\WebSearch\Adapter\PerplexityAdapter;
use App\Plug\WebSearch\Adapter\SearxngAdapter;
use App\Plug\WebSearch\Adapter\TavilyAdapter;
use App\Plug\WebSearch\Adapter\WebzAdapter;
use App\Plug\WebSearch\Client\ExaClient;
use App\Plug\WebSearch\Client\FirecrawlClient;
use App\Plug\WebSearch\Client\PerplexitySearchClient;
use App\Plug\WebSearch\Client\SearxngClient;
use App\Plug\WebSearch\Client\TavilyClient;
use App\Plug\WebSearch\Client\WebzClient;
use App\Plug\WebSearch\WebSearchQuery;
use App\Repository\ConfigRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebSearchAdapterContractTest extends TestCase
{
    public function testSearxngMapsFixtureTitles(): void
    {
        $adapter = new SearxngAdapter($this->searxngClient());
        $set = $adapter->search(new WebSearchQuery('synaplan'));

        $this->assertSame('Synaplan', $set->results[0]['title'] ?? null);
        $this->assertTrue($adapter->capabilities()->language);
        $this->assertTrue($adapter->health()->available);
    }

    public function testTavilyMapsFixtureTitles(): void
    {
        $adapter = new TavilyAdapter($this->tavilyClient());
        $set = $adapter->search(new WebSearchQuery('synaplan'));

        $this->assertSame('Synaplan', $set->results[0]['title'] ?? null);
        $this->assertTrue($adapter->capabilities()->fullContent);
        $this->assertTrue($adapter->health()->available);
    }

    public function testExaMapsFixtureTitles(): void
    {
        $adapter = new ExaAdapter($this->exaClient(), $this->plugConfig());
        $set = $adapter->search(new WebSearchQuery('synaplan'));

        $this->assertSame('Synaplan', $set->results[0]['title'] ?? null);
        $this->assertTrue($adapter->capabilities()->siteFilter);
    }

    public function testFirecrawlPutsMarkdownInContent(): void
    {
        $adapter = new FirecrawlAdapter($this->firecrawlClient(), $this->plugConfig());
        $set = $adapter->search(new WebSearchQuery('synaplan'));

        $this->assertSame('Synaplan', $set->results[0]['title'] ?? null);
        $this->assertStringContainsString('Open-source', (string) ($set->results[0]['content'] ?? ''));
        $this->assertTrue($adapter->capabilities()->fullContent);
    }

    public function testPerplexityMapsFixtureAndSkipsAnswerWhenUnbound(): void
    {
        $adapter = new PerplexityAdapter(
            $this->perplexityClient(),
            $this->emptyConfigRepo(),
            new NullLogger(),
        );
        $set = $adapter->search(new WebSearchQuery('synaplan', [], true));

        $this->assertSame('Synaplan', $set->results[0]['title'] ?? null);
        $this->assertNull($set->answer);
        $this->assertTrue($adapter->capabilities()->answer);
    }

    public function testExaGarbageKeyIsUnavailableOnProbe(): void
    {
        $http = new MockHttpClient([new MockResponse('unauthorized', ['http_code' => 401])]);
        $adapter = new ExaAdapter(
            new ExaClient($http, $this->plugKeys('exa'), $this->plugConfig()),
            $this->plugConfig(),
        );

        self::assertTrue($adapter->health()->available, 'A stored key is still configured');
        $probed = $adapter->probe();
        self::assertFalse($probed->available);
        self::assertStringContainsString('401', (string) $probed->reason);
    }

    public function testHttpErrorBecomesExceptionForRegistryFallback(): void
    {
        $http = new MockHttpClient([new MockResponse('nope', ['http_code' => 500])]);
        $adapter = new TavilyAdapter(new TavilyClient($http, $this->plugKeys('tavily'), $this->plugConfig()));

        $this->expectException(\RuntimeException::class);
        $adapter->search(new WebSearchQuery('synaplan'));
    }

    public function testWebzMapsFixtureTitlesAndExcerpt(): void
    {
        $adapter = new WebzAdapter($this->webzClient(), $this->plugConfig());
        $set = $adapter->search(new WebSearchQuery('synaplan'));

        $this->assertSame('Synaplan', $set->results[0]['title'] ?? null);
        $this->assertSame('https://www.synaplan.com/', $set->results[0]['url'] ?? null);
        $this->assertStringContainsString('Open-source', (string) ($set->results[0]['content'] ?? ''));
        $this->assertSame('2026-09-01T00:00:00Z', $set->results[0]['age'] ?? null);
        $this->assertTrue($adapter->capabilities()->language);
        $this->assertTrue($adapter->capabilities()->siteFilter);
        $this->assertTrue($adapter->capabilities()->freshness);
        $this->assertTrue($adapter->capabilities()->fullContent);
        $this->assertFalse($adapter->capabilities()->answer);
        $this->assertFalse($adapter->capabilities()->country);
        $this->assertTrue($adapter->health()->available);
        $this->assertSame('Webz.io News Search', $adapter->descriptor()->label);
    }

    public function testWebzSendsFiltersWithoutADaysFieldAndKeepsTheTokenInTheHeader(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/web_search/webz/search.json'), [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });
        $adapter = new WebzAdapter(new WebzClient($http, $this->plugKeys('webz'), $this->plugConfig()), $this->plugConfig());
        $adapter->search(new WebSearchQuery('synaplan', [
            'search_lang' => 'en',
            'country' => 'en',
            'freshness' => 'pm',
            'site' => 'https://www.example.com/section',
            'count' => 5,
        ]));

        self::assertIsArray($captured);
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://api.webz.io/api/news/context', $captured['url']);
        self::assertSame(0, $captured['options']['max_redirects'] ?? null);
        $headers = $this->headerLines($captured['options']);
        self::assertContains('Authorization: Bearer test-key', $headers);
        self::assertStringNotContainsString('test-key', $captured['url']);
        $body = json_decode((string) ($captured['options']['body'] ?? ''), true);
        self::assertIsArray($body);
        self::assertSame('synaplan', $body['query'] ?? null);
        self::assertSame(5, $body['k'] ?? null);
        self::assertArrayNotHasKey('days', $body);
        self::assertSame(['english'], $body['filters']['language'] ?? null);
        self::assertSame(['www.example.com'], $body['filters']['domain'] ?? null);
        self::assertSame(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-30 days')->format('Y-m-d'),
            $body['filters']['published_from'] ?? null,
        );
    }

    public function testWebzRejectsACleartextEndpointBeforeTheTokenIsRead(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('https://');
        WebzClient::assertHttpsEndpoint('http://api.webz.io/api/news/context');
    }

    public function testWebzHttpErrorDoesNotEchoTheToken(): void
    {
        $http = new MockHttpClient([new MockResponse('token=test-key', ['http_code' => 401])]);
        $adapter = new WebzAdapter(new WebzClient($http, $this->plugKeys('webz'), $this->plugConfig()), $this->plugConfig());

        try {
            $adapter->search(new WebSearchQuery('synaplan'));
            self::fail('Expected the HTTP error to throw.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('HTTP 401', $e->getMessage());
            self::assertStringNotContainsString('test-key', $e->getMessage());
        }
    }

    private function searxngClient(): SearxngClient
    {
        return new SearxngClient(
            $this->mockClient('searxng/search.json'),
            $this->plugConfig(),
            'http://searxng.test',
        );
    }

    private function tavilyClient(): TavilyClient
    {
        return new TavilyClient($this->mockClient('tavily/search.json'), $this->plugKeys('tavily'), $this->plugConfig());
    }

    private function webzClient(): WebzClient
    {
        return new WebzClient($this->mockClient('webz/search.json'), $this->plugKeys('webz'), $this->plugConfig());
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private function headerLines(array $options): array
    {
        $headers = $options['headers'] ?? [];
        if (!\is_array($headers)) {
            return [];
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            if (\is_int($name)) {
                $lines[] = (string) $value;
                continue;
            }
            if (\is_array($value)) {
                foreach ($value as $item) {
                    $lines[] = $name.': '.$item;
                }
                continue;
            }
            $lines[] = $name.': '.$value;
        }

        return $lines;
    }

    private function exaClient(): ExaClient
    {
        return new ExaClient($this->mockClient('exa/search.json'), $this->plugKeys('exa'), $this->plugConfig());
    }

    private function firecrawlClient(): FirecrawlClient
    {
        return new FirecrawlClient($this->mockClient('firecrawl/search.json'), $this->plugKeys('firecrawl'), $this->plugConfig());
    }

    private function perplexityClient(): PerplexitySearchClient
    {
        $keys = $this->createMock(ProviderKeyStore::class);
        $keys->method('getKey')->with('perplexity')->willReturn('test-key');

        return new PerplexitySearchClient($this->mockClient('perplexity/search.json'), $keys, $this->plugConfig());
    }

    private function mockClient(string $relative): MockHttpClient
    {
        $path = dirname(__DIR__, 2).'/Fixtures/web_search/'.$relative;

        return new MockHttpClient([
            new MockResponse((string) file_get_contents($path), [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/json'],
            ]),
        ]);
    }

    private function plugKeys(string $provider): PlugKeyStore
    {
        $keys = $this->createMock(PlugKeyStore::class);
        $keys->method('getKey')->with($provider)->willReturn('test-key');

        return $keys;
    }

    private function plugConfig(): PlugConfigService
    {
        return new PlugConfigService($this->emptyConfigRepo());
    }

    private function emptyConfigRepo(): ConfigRepository
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);

        return $repo;
    }
}
