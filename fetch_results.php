<?php
require 'vendor/autoload.php';
require 'config.php';

use InfluxDB2\Client;
use InfluxDB2\Model\WritePrecision;
use InfluxDB2\Point;

$username = $conf['username'];
$api_code = $conf['api_code'];

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
 * Store results in InfluxDB.
 *
 * @param \InfluxDB2\WriteApi $writeApi The InfluxDB write API instance.
 * @param string $bucket The InfluxDB bucket name.
 * @param array $results The results to store.
 */
function storeResults(\InfluxDB2\WriteApi $writeApi, string $bucket, array $results): void {
    global $influxDB;
    foreach ($results['ResultsStatus']['Results'] as $result) {
        if ($result['GrantedCredit'] > 0) {
            echo "Storing result for {$result['AppName']} on {$result['DeviceName']}...\n";
            $point = Point::measurement('wcg_results')
                ->addTag('AppName', $result['AppName'])
                ->addTag('DeviceName', $result['DeviceName'])
                ->addTag('Name', $result['Name'])
                ->addField('ClaimedCredit', $result['ClaimedCredit'])
                ->addField('CpuTime', $result['CpuTime'])
                ->addField('GrantedCredit', $result['GrantedCredit'])
                ->time(strtotime($result['ReceivedTime']), WritePrecision::S);

            $writeApi->write($point, WritePrecision::S, $bucket);
        } else {
            echo "Skipping result for {$result['AppName']} on {$result['DeviceName']}...\n";
        }
    }
}

$offset = 0;
$limit = 250;
do {
    echo "Fetching results from offset {$offset}...\n";
    $url = "https://www.worldcommunitygrid.org/api/members/profile/{$username}/results?code={$api_code}&offset={$offset}&limit={$limit}";
    $results = fetchResults($url);
    if (empty($results['ResultsStatus']['Results'])) {
        echo "No more results to fetch.\n";
        echo $url."\n";
        break;
    }
    storeResults($writeApi, $influxDB_bucket, $results);
    $offset += 240;
} while (true);

$writeApi->close();
?>
