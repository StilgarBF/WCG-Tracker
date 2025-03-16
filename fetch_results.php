<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
echo "<pre>";

require 'vendor/autoload.php';
require 'config.php';

use App\Database\InfluxDBClient;
use App\ProjectData\WCGProjectDataReader;
use App\ProjectData\EAHProjectDataReader;

global $influxDBClient;
$influxDBClient = new InfluxDBClient($conf['influxDB']);

$wcgReader = new WCGProjectDataReader($conf['wcg'], $influxDBClient);
$eahReader = new EAHProjectDataReader($conf['einstein'], $influxDBClient);

// Fetch and store results from World Community Grid
$wcgReader->fetchProjectData();

// Fetch and store results from Einstein@Home
$eahReader->fetchProjectData();

$influxDBClient->close();
