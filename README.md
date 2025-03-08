# WCG Tracker

This project is designed to keep track of your progress and project distribution on the World Community Grid (WCG). It fetches results from the WCG API and stores them in an InfluxDB bucket. Future plans include extending this project to use the collected data in a small web-based dashboard.

## Requirements

- PHP
- Composer
- InfluxDB

## Setup

1. Clone the repository:
    ```sh
    git clone <repository_url>
    cd WCG-Tracker
    ```

2. Install dependencies:
    ```sh
    composer install
    ```

3. Copy the sample configuration file and update it with your own values:
    ```sh
    cp config.sample.php config.php
    nano config.php
    ```

4. Ensure you have an InfluxDB bucket set up. Update the `config.php` file with your InfluxDB details.

## Usage

Run the `fetch_results.php` script to fetch and store results from the WCG API:
```sh
php fetch_results.php
```

It is recommended to run this script twice a day to avoid losing any data.

## Future Plans

- Develop a web-based dashboard to visualize the collected data.

## License

This project is licensed under the GPL v3 License.
