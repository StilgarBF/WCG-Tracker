<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'vendor/autoload.php';
require 'config.php';

use InfluxDB2\Client;
use InfluxDB2\Model\WritePrecision;
use InfluxDB2\Point;

$influxDB_host = $conf['influxDB']['host'];
$influxDB_port = $conf['influxDB']['port'];
$influxDB_org = $conf['influxDB']['org'];
$influxDB_token = $conf['influxDB']['token'];
$influxDB_bucket = $conf['influxDB']['bucket'];

$client = new Client([
    "url" => "http://{$influxDB_host}:{$influxDB_port}",
    "token" => $influxDB_token,
    "org" => $influxDB_org
]);

if (!$client) {
    die("Failed to initialize InfluxDB client.\n");
}

$writeApi = $client->createWriteApi();

/**
 * Fetch results from the given URL.
 *
 * @param string $url The URL to fetch results from.
 * @return array The decoded JSON response.
 */
function fetchResults(string $url): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $output = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);
    return json_decode($output, true);
}

/**
 * Fetch HTML content from the given URL.
 *
 * @param string $url The URL to fetch HTML content from.
 * @return string The HTML content.
 */
function fetchHtmlContent(string $url): string {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $output = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);
    return $output;
}

/**
 * Parse World Community Grid results.
 *
 * @param array $results The results to parse.
 */
function parseWCGResults(array $results): void {
    foreach ($results['ResultsStatus']['Results'] as $result) {
        if ($result['GrantedCredit'] > 0) {
            $parsedResult = [
                'measurement' => 'boinc_results',
                'tags' => [
                    'Project' => 'World Community Grid',
                    'AppName' => $result['AppName'],
                    'DeviceName' => $result['DeviceName'],
                    'Name' => $result['Name']
                ],
                'fields' => [
                    'ClaimedCredit' => (float)$result['ClaimedCredit'],
                    'CpuTime' => (float)$result['CpuTime'],
                    'GrantedCredit' => (float)$result['GrantedCredit']
                ],
                'time' => strtotime($result['ReceivedTime'])
            ];
            storeResult($parsedResult);
        }
    }
}

/**
 * Store a single result in InfluxDB.
 *
 * @param array $result The parsed result to store.
 */
function storeResult(array $result): void {
    global $writeApi, $influxDB_bucket;

    $point = Point::measurement($result['measurement']);

    foreach ($result['tags'] as $key => $value) {
        $point->addTag($key, $value);
    }

    foreach ($result['fields'] as $key => $value) {
        $point->addField($key, $value);
    }

    $point->time($result['time'], WritePrecision::S);

    $writeApi->write($point, WritePrecision::S, $influxDB_bucket);
}

/**
 * Fetch results from World Community Grid.
 *
 * @param array $wcgConfig The World Community Grid configuration.
 */
function fetchWorldCommunityGrid(array $wcgConfig): void {
    echo str_pad("#### Fetching Results from World Community Grid", 80 , "#") . "\n";
    $username = $wcgConfig['username'];
    $api_code = $wcgConfig['api_code'];

    $offset = 0;
    $limit = 250;
    do {
        echo "Fetching results from offset {$offset}...\n";
        $url = "https://www.worldcommunitygrid.org/api/members/profile/{$username}/results?code={$api_code}&offset={$offset}&limit={$limit}";
        $results = fetchResults($url);
        if (empty($results['ResultsStatus']['Results'])) {
            break;
        }
        parseWCGResults($results);
        $offset += 240;
    } while (true);
}

/**
 * Fetch results from Einstein@Home.
 */
function fetchEinsteinAtHome(array $einsteinConfig): void {
    echo str_pad("#### Fetching Results from EinsteinAtHome", 80 , "#") . "\n";
    foreach ($einsteinConfig['hosts'] as $host) {
        $url = $einsteinConfig['url'] . $host['id'] . '/tasks/0/0';
        echo "Fetching results from {$url} for host {$host['name']}...\n";
        fetchAndParseEinsteinPage($url, $host['name'], true);
    }
}

/**
 * Fetch and parse a single page of Einstein@Home results.
 *
 * @param string $url The URL to fetch and parse.
 * @param string $hostName The name of the host.
 * @param bool $crawlPager Whether to crawl the pager for additional pages.
 */
function fetchAndParseEinsteinPage(string $url, string $hostName, bool $crawlPager): void {
    $htmlContent = fetchHtmlContent($url);
    parseEinsteinAtHomeResults($htmlContent, $hostName);

    if ($crawlPager) {
        $dom = new DOMDocument();
        @$dom->loadHTML($htmlContent);
        $xpath = new DOMXPath($dom);
        $pager = $xpath->query("//ul[contains(@class, 'pager')]");

        if ($pager->length > 0) {
            $pagerItems = $xpath->query("//ul[contains(@class, 'pager')]//li[contains(@class, 'pager-item')]//a");
            foreach ($pagerItems as $pagerItem) {
                $queryPart = $pagerItem->getAttribute('href');
                $queryPart = strstr($queryPart, '?');
                $nextPageUrl = $url . $queryPart;
                echo "Fetching additional page: {$nextPageUrl}\n";
                fetchAndParseEinsteinPage($nextPageUrl, $hostName, false);
            }
        }
    }
}

/**
 * Parse Einstein@Home results.
 *
 * @param string $htmlContent The HTML content to parse.
 * @param string $hostName The name of the host.
 */
function parseEinsteinAtHomeResults(string $htmlContent, string $hostName): void {
    $dom = new DOMDocument();
    @$dom->loadHTML($htmlContent);

    $xpath = new DOMXPath($dom);
    $rows = $xpath->query("//table[contains(@class, 'sticky-enabled')]//tbody//tr");

    foreach ($rows as $row) {
        $columns = $row->getElementsByTagName('td');

        if ($columns->length >= 9
            && $columns->item(4)->nodeValue !== 'In progress')
        {
            $nameLink = $columns->item(0)->getElementsByTagName('a')->item(0);
            $name = $nameLink ? $nameLink->nodeValue : '';

            $result = [
                'measurement' => 'boinc_results',
                'tags' => [
                    'Project' => 'EinsteinAtHome',
                    'DeviceName' => $hostName,
                    'AppName' => $columns->item(8)->nodeValue,
                    'Name' => $name
                ],
                'fields' => [
                    'ClaimedCredit' => (float)0,
                    'CpuTime' => (float) $columns->item(6)->nodeValue,
                    'GrantedCredit' => (float) $columns->item(7)->nodeValue,
                ],
                'time' => strtotime($columns->item(2)->nodeValue)
            ];
            storeResult($result);
        }
    }
}

// Fetch and store results from World Community Grid
fetchWorldCommunityGrid($conf['wcg']);

// Fetch and store results from Einstein@Home
fetchEinsteinAtHome($conf['einstein']);

$writeApi->close();
?>
