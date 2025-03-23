<?php

namespace App\Database;

use InfluxDB2\Client;
use InfluxDB2\Model\WritePrecision;
use InfluxDB2\Point;

class InfluxDBClient {
    private $writeApi;
    private $bucket;
    private $count = 0;

    public function __construct(array $config) {
        $client = new Client([
            "url" => "http://{$config['host']}:{$config['port']}",
            "token" => $config['token'],
            "org" => $config['org']
        ]);

        if (!$client) {
            die("Failed to initialize InfluxDB client.\n");
        }

        $this->writeApi = $client->createWriteApi();
        $this->bucket = $config['bucket'];
    }

    public function storeResult(array $result): void {
        $point = Point::measurement($result['measurement']);
        
        foreach ($result['tags'] as $key => $value) {
            $point->addTag($key, $value);
        }
        
        foreach ($result['fields'] as $key => $value) {
            $point->addField($key, $value);
        }
        
        $point->time($result['time'], WritePrecision::S);

        $this->writeApi->write($point, WritePrecision::S, $this->bucket);
        $this->count++;
    }

    public function close(): void {
        $this->writeApi->close();
    }

    public function getCount(): int {
        return $this->count;
    }
}
