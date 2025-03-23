<?php

namespace App\ProjectData;

use App\Database\InfluxDBClient;

/**
 * Class WCGProjectDataReader
 *
 * This class is responsible for fetching and parsing project data from the World Community Grid API.
 */
class WCGProjectDataReader implements ProjectDataReader {
    /**
     * @var array $config Configuration array containing API credentials.
     */
    private $config;

    /**
     * @var InfluxDBClient $influxDBClient Client for interacting with InfluxDB.
     */
    private $influxDBClient;

    /**
     * WCGProjectDataReader constructor.
     *
     * @param array $config Configuration array containing API credentials.
     * @param InfluxDBClient $influxDBClient Client for interacting with InfluxDB.
     */
    public function __construct(array $config, InfluxDBClient $influxDBClient) {
        $this->config = $config;
        $this->influxDBClient = $influxDBClient;
    }

    /**
     * Fetches project data from the World Community Grid API and stores it in InfluxDB.
     *
     * @return void
     */
    public function fetchProjectData(): void {
        echo str_pad("#### Fetching Results from World Community Grid", 80 , "#") . "\n";
        $username = $this->config['username'];
        $api_code = $this->config['api_code'];

        $offset = 0;
        $limit = 250;
        do {
            echo "Fetching results from offset {$offset}...\n";
            $url = "https://www.worldcommunitygrid.org/api/members/profile/{$username}/results?code={$api_code}&offset={$offset}&limit={$limit}";
            $results = $this->fetchResults($url);
            if (empty($results['ResultsStatus']['Results'])) {
                break;
            }
            $this->parseResults($results);
            $offset += 240;
        } while (true);
    }

    /**
     * Fetches results from a given URL.
     *
     * @param string $url The URL to fetch results from.
     * @return array The results fetched from the URL.
     */
    private function fetchResults(string $url): array {
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
     * Parses the results and stores them in InfluxDB.
     *
     * @param array $results The results to parse.
     * @return void
     */
    private function parseResults(array $results): void {
        $count = count($results['ResultsStatus']['Results']);
        echo "Parsing {$count} results...\n";

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
                        'CpuTime' => (float)$result['CpuTime'] * 3600,
                        'GrantedCredit' => (float)$result['GrantedCredit']
                    ],
                    'time' => strtotime($result['ReceivedTime'])
                ];
                $this->influxDBClient->storeResult($parsedResult);
            }
        }

        echo "Strored {$count} results - {$this->influxDBClient->getCount()} total.\n";
    }
}
