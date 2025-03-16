<?php
// config.sample.php - Sample configuration file
$conf = [
    'wcg' => [
        'username' => 'your_username',
        'api_code' => 'your_api_code',
    ],
    'influxDB' => [
        'host' => 'your_host',
        'port' => 'your_port',
        'org' => 'your_org',
        'token' => 'your_token',
        'bucket' => 'your_bucket',
    ],
    'einstein' => [
        'url' => 'https://einsteinathome.org/de/host/',
        'hosts' => [
            [
                'id' => '2222',
                'name' => 'yourpc1',
            ],
            [
                'id' => '1111',
                'name' => 'yourpc2',
            ],
            // ...add more hosts as needed...
        ],
    ],
];
