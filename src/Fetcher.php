<?php

namespace Megamindame\SchoolScraper;

use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Megamindame\SchoolScraper\Enum\SchoolType;
use Megamindame\SchoolScraper\Exceptions\InvalidSchoolTypeException;
use Symfony\Component\DomCrawler\Crawler;

class Fetcher
{
    protected string $schoolType;
    protected ?int $year;
    private ?Client $client;
    private Crawler $nodes;
    private string $url;
    private array $schools = [];
    private array $regions;

    public function __construct(string $type, ?int $year = null, ?Client $client = null)
    {
        $this->schoolType = $type;
        $this->year = is_null($year) ? (int)date('Y', strtotime('- 1 year')) : $year;
        $this->client = is_null($client) ? new Client([
            'base_uri' => config('necta.base_results_url', ['year' => $this->year]),
            'pool_size' => get_processor_cores_number()
        ]) : $client;
    }

    /**
     * @throws GuzzleException|InvalidSchoolTypeException
     */
    protected function fetchNodes(): void
    {
        $response = $this->client->get($this->getUrl());

        $crawler = new Crawler((string)$response->getBody());

        $this->nodes = $crawler->evaluate('//table[last()]//td//a');
    }

    /**
     * @throws InvalidSchoolTypeException
     */
    protected function prepareUrl(): void
    {
        switch ($this->schoolType) {
            case SchoolType::CSEE:
                $this->url = config('necta.results_url.secondary', []);
                break;
            case SchoolType::PSLE:
                $this->url = config('necta.results_url.primary', []);
                break;
            case SchoolType::ACSEE:
                $this->url = config('necta.results_url.advanced_secondary', []);
                break;
            default:
                throw new InvalidSchoolTypeException();
        }
    }

    /**
     * @throws GuzzleException
     * @throws InvalidSchoolTypeException
     */
    protected function createSchoolList(): void
    {
        switch ($this->schoolType) {
            case SchoolType::CSEE:
                $this->schools = config('necta.results_url.secondary', []);
                break;
            case SchoolType::PSLE:
                $this->parsePSLERequest();
                break;
            case SchoolType::ACSEE:
                $this->schools = config('necta.results_url.advanced_secondary', []);
                break;
            default:
                $this->schools = [];
        }
    }

    /**
     * @throws GuzzleException|InvalidSchoolTypeException
     */
    protected function getNodes(): Crawler
    {
        if (!isset($this->nodes)) $this->fetchNodes();
        return $this->nodes;
    }

    /**
     * @throws GuzzleException
     * @throws InvalidSchoolTypeException
     */
    private function getRegions(): array
    {
        if (empty($this->regions)) {
            $this->regions = $this->getNodes()->each(function (Crawler $node) {
                return [$node->text() => $node->attr('href')];
            });
        }
        return $this->regions;
    }

    /**
     * @throws GuzzleException
     * @throws InvalidSchoolTypeException
     */
    private function generateRequests(): Generator
    {
        foreach ($this->getRegions() as $regionLink) {
            $link = current($regionLink);
            yield new Request('GET', "psle/$link");
        }
    }

    private function extractDistrictLinksFromResponse(Response $response): array
    {
        $crawler = new Crawler((string)$response->getBody());
        return $crawler->filterXPath('//table[last()]//td//a')->each(function (Crawler $node) {
            return [$node->text() => $node->attr('href')];
        });
    }

    /**
     * @throws GuzzleException
     */
    private function fetchDistrictResults(Client $client, string $region, array $districtLinks): void
    {
        foreach ($districtLinks as $districtLink) {
            $value = array_values($districtLink)[0];
            $key = array_keys($districtLink)[0];
            $response = $client->get("psle/results/$value");

            $crawler = new Crawler((string) $response->getBody());
            $crawler->filterXPath('//table//td//a')->each(function (Crawler $node) use ($region, $key) {
                $this->schools[$region][$key][] = $node->text();
            });
        }
    }

    /**
     * @throws GuzzleException
     * @throws InvalidSchoolTypeException
     */
    private function parsePSLERequest() {
        $pool = new Pool($this->client, $this->generateRequests(), [
            'concurrency' => get_processor_cores_number() / 2,
            'fulfilled' => function (Response $response, $index) {
                $this->fetchDistrictResults(
                    $this->client,
                    key($this->regions[$index]),
                    $this->extractDistrictLinksFromResponse($response)
                );
            },
            'rejected' => function ($reason, $index) {
                echo "Pool request id $index rejected because $reason\n" . "\n";
            }
        ]);

        $promise = $pool->promise();

        $promise->wait();
    }

    /**
     * @return string
     * @throws InvalidSchoolTypeException
     */
    public function getUrl(): string
    {
        if (!isset($this->url) || trim($this->url) === '')
            $this->prepareUrl();

        return $this->url;
    }

    /**
     * @throws GuzzleException
     * @throws InvalidSchoolTypeException
     */
    public function getSchools(bool $formatted = false): array
    {
        if (empty($this->schools))
            $this->createSchoolList();

        if ($this->schoolType === SchoolType::PSLE && !$formatted) {
            $results = [];
            array_walk_recursive($this->schools, function ($item) use (&$results) {
                $value = explode('-', $item);
                $results[trim(array_pop($value))] = trim(implode($value));
            });
            return $results;
        }

        return $this->schools;
    }
}
