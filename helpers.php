<?php

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Symfony\Component\DomCrawler\Crawler;

$config = include 'config.php';

if (!function_exists('config')) {
    function config($accessor, $options)
    {
        $accessors = explode('.', $accessor);

        $value = include 'config.php';

        foreach ($accessors as $item) {
            $value = $value[$item];
        }

        foreach ($options as $key => $option) {
            $value = str_replace('{' . $key . '}', $option, $value);
        }
        return $value;
    }
}

if (!function_exists('dd')) {
    function dd($var)
    {
       var_dump($var);
       exit();
    }
}

if (!function_exists('get_processor_cores_number')) {
    function get_processor_cores_number(): int
    {
        if (PHP_OS_FAMILY == 'Windows') {
            $cores = shell_exec('echo %NUMBER_OF_PROCESSORS%');
        } else {
            $cores = shell_exec('nproc');
        }

        return (int) $cores;
    }
}

if (!function_exists('fetch_sample_schools')) {
    function fetch_sample_schools (string $baseDir) {
        $directories = [
            $baseDir,
            $baseDir . '/psle',
            $baseDir . '/csee',
            $baseDir . '/acsee',
        ];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory);
            }
        }

        $client = new Client([
            'base_uri' => config('necta.base_results_url', ['year' => 2022]),
            'pool_size' => 8
        ]);

        try {
            $primary = $client->get('psle/psle.htm', [
                'on_stats' => function (TransferStats $stats) {
                    echo "Primary school request: {$stats->getEffectiveUri()}\n";
                }
            ]);
            $secondary = $client->get('csee/index.htm', [
                'on_stats' => function (TransferStats $stats) {
                    echo "Secondary school request: {$stats->getEffectiveUri()}\n";
                }
            ]);
            $advanced = $client->get('acsee/index.htm', [
                'on_stats' => function (TransferStats $stats) {
                    echo "Advanced school request: {$stats->getEffectiveUri()}\n";
                }
            ]);
        } catch (GuzzleException $e) {
            die("Failed to fetch results page.\nError: {$e->getMessage()} on line {$e->getLine()}\n");
        }

        if ($primary->getStatusCode() === 200) {
            file_put_contents($baseDir . '/psle/index.html', (string)$primary->getBody());
            $crawler = new Crawler((string)$primary->getBody());
            $regions = $crawler->filterXPath('//table[last()]//td//a')->each(function (Crawler $node) {
                return [$node->text() => $node->attr('href')];
            });
            $pool = new Pool($client, generateRequests($regions), [
                'concurrency' => 4,
                'fulfilled' => function (Response $response, $index) use ($client, &$regions, $baseDir) {
                    $region = array_keys($regions[$index])[0];
                    if (!is_dir($baseDir . "/psle/$region")) {
                        mkdir($baseDir . "/psle/$region");
                    }

                    file_put_contents($baseDir . "/psle/$region/index.html", (string)$response->getBody());
                    fetchDistrictResults($client, $region, extractDistrictLinksFromResponse($response));
                },
                'rejected' => function ($reason, $index) {
                    echo "Pool request id $index rejected because $reason\n" . "\n";
                }
            ]);

            $promise = $pool->promise();

            $promise->wait();
        }

        if ($secondary->getStatusCode() === 200) {
            file_put_contents($baseDir . '/csee/index.html', $secondary->getBody());
        }

        if ($advanced->getStatusCode() === 200) {
            file_put_contents($baseDir . '/acsee/index.html', $advanced->getBody());
        }
    }
    function generateRequests(iterable $regionLinks): Generator
    {
        foreach ($regionLinks as $regionLink) {
            $link = array_values($regionLink)[0];
            yield new Request('GET', "psle/$link", ['index' => array_keys($regionLink)[0]]);
        }
    }

    function extractDistrictLinksFromResponse(Response $response): array
    {
        $crawler = new Crawler((string)$response->getBody());
        return $crawler->filterXPath('//table[last()]//td//a')->each(function (Crawler $node) {
            return [$node->text() => $node->attr('href')];
        });
    }

    /**
     */
    function fetchDistrictResults(Client $client, string $region, array $districtLinks): void
    {
        foreach ($districtLinks as $districtLink) {
            $value = array_values($districtLink)[0];
            $key = array_keys($districtLink)[0];
            $response = $client->get("psle/results/$value");

            $fileName = preg_replace('/[^a-z0-9]+/', '-', strtolower($key));

            $districtFilePath = BASE_DIR . "/psle/$region/$fileName.html";
            file_put_contents($districtFilePath, $response->getBody());
        }
    }
}
