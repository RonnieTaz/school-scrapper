<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Megamindame\SchoolScraper\Enum\SchoolType;
use Megamindame\SchoolScraper\Fetcher;

beforeAll(function () {
    function getDirectory(array $directories): string {
        if (!empty($directories)) {
            $value = dirname(__DIR__) . '/samples/psle/' . $directories[array_rand($directories)];
        } else {
            fetch_sample_schools(dirname(__DIR__) . '/samples');
            $value = getDirectory($directories);
        }

        return $value;
    }

    if (!is_dir(dirname(__DIR__) . '/samples')) {
        fetch_sample_schools(dirname(__DIR__) . '/samples');
    }
});

beforeEach(function () {
    $directory = getDirectory(array_diff(scandir(dirname(__DIR__) . '/samples/psle/'), ['.', '..']));

    $mock = new MockHandler(array_fill(0, 1500, function (Request $request) use ($directory) {
        if (preg_match("/^https:\/\/onlinesys\.necta\.go\.tz\/results\/\d{4}\/psle\/results\/reg_\d{2}\.htm$/", $request->getUri())) {
            return new Response(200, [
                'Content-Type' => 'text/html'
            ], file_get_contents("$directory/index.html"));
        }
        if (preg_match("/^https:\/\/onlinesys\.necta\.go\.tz\/results\/\d{4}\/psle\/results\/distr_\d{4}\.htm$/", $request->getUri())) {
            $files = scandir($directory, SCANDIR_SORT_NONE);
            $files = array_diff($files, ['.', '..']);
            $file = $files[array_rand($files)];
            return new Response(200, [
                'Content-Type' => 'text/html'
            ], file_get_contents("$directory/$file"));
        }
        if (preg_match("/^https:\/\/onlinesys\.necta\.go\.tz\/results\/\d{4}\/psle\/psle\.htm$/", $request->getUri())) {
            return new Response(200, [
                'Content-Type' => 'text/html'
            ], file_get_contents(dirname(__DIR__) . '/samples/psle/index.html'));
        }
        if (preg_match("/^https:\/\/onlinesys\.necta\.go\.tz\/results\/\d{4}\/csee\/index\.htm$/", $request->getUri())) {
            return new Response(200, [
                'Content-Type' => 'text/html'
            ], file_get_contents(dirname(__DIR__) . '/samples/csee/index.html'));
        }
        if (preg_match("/^https:\/\/onlinesys\.necta\.go\.tz\/results\/\d{4}\/acsee\/index\.htm$/", $request->getUri())) {
            return new Response(200, [
                'Content-Type' => 'text/html'
            ], file_get_contents(dirname(__DIR__) . '/samples/acsee/index.html'));
        }
        return new Response(200, [], "Hello World");
    }));
    $this->client = new Client([
        'base_uri' => config('necta.base_results_url', ['year' => (int)date('Y', strtotime('- 1 year'))]),
        'pool_size' => get_processor_cores_number(),
        'handler' => HandlerStack::create($mock)
    ]);
});

it('returns an array of primary schools with indices as registration number', function () {
    $fetcher = new Fetcher(SchoolType::PSLE, null, $this->client);
    expect($fetcher->getSchools())->toBeArray()
        ->each(fn ($key, $value) => expect($key)->toMatch("/^PS\d{7}$/")->and($value)->toBeString());
});

it('returns an array of primary schools inside districts which are inside regions', function () {
    $fetcher = new Fetcher(SchoolType::PSLE, null, $this->client);
    expect($fetcher->getSchools(true))->toBeArray()
        ->each(fn ($key, $value) =>
            expect($key)->toBeString()
                ->and($value)->toBeArray()
                ->each(fn ($k, $v) =>
                    expect($k)->toBeString()
                        ->and($v)->toBeArray()
                )
        );
});

//it('it returns an array of secondary schools on with indices as registration number', function () {
//    $fetcher = new Fetcher(SchoolType::CSEE, null, $this->client);
//    expect($this->secondaryFetcher->getSchools())->toBeArray()
//        ->each(fn($key) => expect($key)->toMatch("^[PS]\d{4}$"));
//});
