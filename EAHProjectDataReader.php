<?php
/**
 * Class EAHProjectDataReader
 *
 * This class is responsible for fetching and parsing project data from EinsteinAtHome.
 */
require_once 'ProjectDataReader.php';

class EAHProjectDataReader implements ProjectDataReader {
    /**
     * @var array Configuration array containing hosts and URL.
     */
    private $config;

    /**
     * @var InfluxDBClient Client for interacting with InfluxDB.
     */
    private $influxDBClient;

    /**
     * EAHProjectDataReader constructor.
     *
     * @param array $config Configuration array containing hosts and URL.
     * @param InfluxDBClient $influxDBClient Client for interacting with InfluxDB.
     */
    public function __construct(array $config, InfluxDBClient $influxDBClient) {
        $this->config = $config;
        $this->influxDBClient = $influxDBClient;
    }

    /**
     * Fetches project data from the configured hosts.
     *
     * @return void
     */
    public function fetchProjectData(): void {
        echo str_pad("#### Fetching Results from EinsteinAtHome", 80 , "#") . "\n";
        foreach ($this->config['hosts'] as $host) {
            $url = $this->config['url'] . $host['id'] . '/tasks/0/0';
            echo "Fetching results from {$url} for host {$host['name']}...\n";
            $this->fetchAndParsePage($url, $host['name'], true);
        }
    }

    /**
     * Fetches HTML content from a given URL.
     *
     * @param string $url The URL to fetch the HTML content from.
     * @return string The fetched HTML content.
     */
    private function fetchHtmlContent(string $url): string {
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
     * Fetches and parses a page from a given URL.
     *
     * @param string $url The URL to fetch the page from.
     * @param string $hostName The name of the host.
     * @param bool $crawlPager Whether to crawl the pager for additional pages.
     * @return void
     */
    private function fetchAndParsePage(string $url, string $hostName, bool $crawlPager): void {
        $htmlContent = $this->fetchHtmlContent($url);
        $this->parseResults($htmlContent, $hostName);

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
                    $this->fetchAndParsePage($nextPageUrl, $hostName, false);
                }
            }
        }
    }

    /**
     * Parses the results from the HTML content.
     *
     * @param string $htmlContent The HTML content to parse.
     * @param string $hostName The name of the host.
     * @return void
     */
    private function parseResults(string $htmlContent, string $hostName): void {
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
                $this->influxDBClient->storeResult($result);
            }
        }
    }
}
