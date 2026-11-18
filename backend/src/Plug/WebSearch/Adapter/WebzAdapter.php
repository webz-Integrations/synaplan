<?php

declare(strict_types=1);

namespace App\Plug\WebSearch\Adapter;

use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\WebSearch\Client\WebzClient;
use App\Plug\WebSearch\SearchResult;
use App\Plug\WebSearch\SearchResultSet;
use App\Plug\WebSearch\WebSearchCapabilities;
use App\Plug\WebSearch\WebSearchLiveProbeInterface;
use App\Plug\WebSearch\WebSearchOptionMapper;
use App\Plug\WebSearch\WebSearchProbe;
use App\Plug\WebSearch\WebSearchProviderInterface;
use App\Plug\WebSearch\WebSearchQuery;

/**
 * News search over the last 30 days. There is no `days` field on the API;
 * a freshness window is sent as filters.published_from.
 *
 * @internal
 */
final readonly class WebzAdapter implements WebSearchProviderInterface, WebSearchLiveProbeInterface
{
    /**
     * ISO 639-1 codes the chat path already sends, mapped to the language
     * names the News Search API accepts. An unknown code is left unset.
     *
     * @var array<string, string>
     */
    private const LANGUAGES = [
        'ar' => 'arabic',
        'cs' => 'czech',
        'da' => 'danish',
        'de' => 'german',
        'el' => 'greek',
        'en' => 'english',
        'es' => 'spanish',
        'fi' => 'finnish',
        'fr' => 'french',
        'he' => 'hebrew',
        'hi' => 'hindi',
        'hu' => 'hungarian',
        'id' => 'indonesian',
        'it' => 'italian',
        'ja' => 'japanese',
        'ko' => 'korean',
        'nl' => 'dutch',
        'no' => 'norwegian',
        'pl' => 'polish',
        'pt' => 'portuguese',
        'ro' => 'romanian',
        'ru' => 'russian',
        'sv' => 'swedish',
        'th' => 'thai',
        'tr' => 'turkish',
        'uk' => 'ukrainian',
        'vi' => 'vietnamese',
        'zh' => 'chinese',
    ];

    public function __construct(
        private WebzClient $client,
        private PlugConfigService $plugConfig,
    ) {
    }

    public function key(): string
    {
        return 'webz';
    }

    public function descriptor(): PlugDescriptor
    {
        return new PlugDescriptor(
            'webz',
            'Webz.io News Search',
            'https://docs.webz.io/docs/webz/news-search-api',
            ['WEBZ_API_TOKEN'],
            'Israel',
        );
    }

    public function capabilities(): WebSearchCapabilities
    {
        return WebSearchCapabilities::webz();
    }

    public function search(WebSearchQuery $query): SearchResultSet
    {
        $maxChars = $this->plugConfig->webSearchMaxContentChars();
        $body = [
            'query' => $query->query,
            'k' => WebSearchOptionMapper::count($query->options),
        ];
        $filters = [];
        $language = $this->languageName(WebSearchOptionMapper::language($query->options));
        if (null !== $language) {
            $filters['language'] = [$language];
        }
        $domain = $this->domain(WebSearchOptionMapper::site($query->options));
        if (null !== $domain) {
            $filters['domain'] = [$domain];
        }
        $publishedFrom = $this->publishedFrom(WebSearchOptionMapper::freshness($query->options));
        if (null !== $publishedFrom) {
            $filters['published_from'] = $publishedFrom;
        }
        if ([] !== $filters) {
            $body['filters'] = $filters;
        }

        $payload = $this->client->search($body);
        $results = [];
        foreach ($payload['results'] ?? [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $article = \is_array($row['article'] ?? null) ? $row['article'] : [];
            $chunk = \is_array($row['chunk'] ?? null) ? $row['chunk'] : [];
            $url = \is_string($article['url'] ?? null) ? $article['url'] : '';
            if ('' === $url) {
                continue;
            }
            $summary = \is_string($article['summary'] ?? null) ? $article['summary'] : '';
            $excerpt = \is_string($chunk['text'] ?? null) ? $chunk['text'] : '';
            $text = '' !== trim($excerpt) ? $excerpt : $summary;
            $published = $article['published_at'] ?? null;
            $results[] = new SearchResult(
                title: \is_string($article['title'] ?? null) ? $article['title'] : '',
                url: $url,
                description: '' !== trim($summary) ? $summary : $text,
                content: WebSearchOptionMapper::truncate($text, $maxChars),
                publishedAt: \is_string($published) ? $published : null,
            );
        }

        return SearchResultSet::fromResults($query->query, $results, [
            'provider' => $this->key(),
            'total' => count($results),
        ]);
    }

    public function health(): PlugHealth
    {
        return $this->client->hasKey()
            ? PlugHealth::available()
            : PlugHealth::unavailable('Webz.io API token is not configured');
    }

    public function probe(): PlugHealth
    {
        return WebSearchProbe::run(
            $this->client->hasKey(),
            'Webz.io API token is not configured',
            $this->client->probe(...),
        );
    }

    private function languageName(?string $code): ?string
    {
        if (null === $code) {
            return null;
        }

        return self::LANGUAGES[$code] ?? null;
    }

    private function domain(?string $site): ?string
    {
        if (null === $site || '' === $site) {
            return null;
        }
        if (str_contains($site, '://')) {
            $host = parse_url($site, \PHP_URL_HOST);

            return \is_string($host) && '' !== $host ? $host : null;
        }

        $host = explode('/', $site, 2)[0];

        return '' !== $host ? $host : null;
    }

    private function publishedFrom(?string $freshness): ?string
    {
        $days = WebSearchOptionMapper::freshnessDays($freshness);
        if (null === $days) {
            return null;
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d days', $days))
            ->format('Y-m-d');
    }
}
