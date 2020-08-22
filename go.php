<?php

require 'vendor/autoload.php';

use Aws\S3\S3Client;
use Erorus\BattleNet;

define('SUMMARY_PATH', __DIR__ . '/data');
define('CSV_PATH', __DIR__ . '/data');

main();

function logTime(string $message): void {
    echo sprintf("%s %d %s\n", date('Y-m-d H:i:s'), posix_getpid(), $message);
}

function main(): void {
    date_default_timezone_set('UTC');
    ini_set('memory_limit', '256M');

    logTime('Starting');
    $bnet = new BattleNet(getenv('BATTLE_NET_KEY', true), getenv('BATTLE_NET_SECRET', true));
    $s3 = new S3Client([
        's3_us_east_1_regional_endpoint' => 'legacy',
        'credentials' => ['key' => getenv('S3_KEY', true), 'secret' => getenv('S3_SECRET', true)],
        'endpoint' => 'https://us-east-1.linodeobjects.com',
        'version' => 'latest',
        'region' => 'us-east-1',
    ]);

    $regions = [$bnet::REGION_US, $bnet::REGION_EU];
    foreach ($regions as $region) {
        processRegion($bnet, $region, $s3);
    }

    logTime('Finished');
}

function processRegion(BattleNet $bnet, string $region, S3Client $s3) {
    $realmLimit = 0;

    logTime("Reading {$region} summary...");
    $oldSummary = readSummary($region);

    logTime("Getting {$region} realm index...");
    $data = $bnet->fetch($region, '/data/wow/connected-realm/index');

    $realms = [];
    foreach ($data->connected_realms ?? [] as $row) {
        if (preg_match('/connected-realm\/(\d+)/', $row->href ?? '', $res)) {
            $realms[] = $res[1];
            if ($realmLimit && count($realms) >= $realmLimit) {
                break;
            }
        }
    }

    logTime(sprintf("Found %d realms.", count($realms)));

    $summaryData = (object)[
        'realms' => (object)[],
    ];

    foreach ($realms as $realmId) {
        $realmData = $bnet->fetch($region, "/data/wow/connected-realm/{$realmId}");
        if (!$realmData) {
            logTime("Could not fetch realm data for {$region} realm {$realmId}.");
            continue;
        }

        $mainSlug = null;
        $names = [];
        foreach ($realmData->realms ?? [] as $realm) {
            if (is_null($mainSlug) || $realm->id === $realmId) {
                $mainSlug = $realm->slug;
            }
            $names[] = $realm->name;
        }

        if (!isset($mainSlug)) {
            logTime("Could not find any realms under {$region} connected realm {$realmId}.");
            continue;
        }
        sort($names);

        logTime(sprintf("Region %s connected realm %d has slug %s with realms %s.",
            $region, $realmId, $mainSlug, implode(', ', $names)
        ));

        $summaryData->realms->$realmId = (object)[
            'slug' => $mainSlug,
            'names' => $names,
        ];

        $lastModified = processRealm(
            $s3,
            $bnet,
            $region,
            $realmId,
            $mainSlug,
            $oldSummary->realms->$realmId->lastModified ?? null
        );

        $summaryData->realms->$realmId->lastModified = $lastModified;
    }

    logTime("Writing {$region} summary...");
    writeSummary($region, $summaryData);
}

function processRealm(
    S3Client $s3,
    BattleNet $bnet,
    string $region,
    int $realmId,
    string $slug,
    ?int $lastModified
): int {
    logTime("Getting auctions for {$region} realm {$realmId}.");

    $opts = [];
    if (isset($lastModified)) {
        $opts[$bnet::OPT_MODIFIED_SINCE] = $lastModified;
    }

    $data = $bnet->fetch($region, sprintf('/data/wow/connected-realm/%d/auctions', $realmId), $opts);
    $auctionHeaders = $bnet->getLastResponseHeaders();
    $lastModified = strtotime($auctionHeaders['last-modified'] ?? 'now');

    if ($auctionHeaders['http-status'] === 304) {
        logTime("Not modified since " . date('Y-m-d H:i:s', $lastModified));

        return $lastModified;
    }

    logTime(sprintf("Found %d auctions.", count($data->auctions ?? [])));

    $fields = [
        'realm-slug',
        'quantity',
        'unit-price',
        'bid',
        'buyout',
        'item-id',
        'pet-breed',
        'pet-level',
        'pet-quality',
        'pet-species',
    ];
    for ($x = 1; $x <= 16; $x++) {
        $fields[] = "item-bonus-{$x}";
    }

    $csvFileName = sprintf('%s-%s.csv', $region, $slug);

    $tempCsvPath = tempnam(CSV_PATH, "temp-{$region}");
    $handle = fopen($tempCsvPath, 'w+');
    fputcsv($handle, $fields);
    foreach ($data->auctions ?? [] as $auction) {
        $row = [
            $slug,
            $auction->quantity ?? '',
            $auction->unit_price ?? '',
            $auction->bid ?? '',
            $auction->buyout ?? '',
            $auction->item->id ?? '',
            $auction->item->pet_breed_id ?? '',
            $auction->item->pet_level ?? '',
            $auction->item->pet_quality_id ?? '',
            $auction->item->pet_species_id ?? '',
        ];
        $bonuses = $auction->item->bonus_lists ?? [];
        sort($bonuses, SORT_NUMERIC);
        $row = array_merge($row, $bonuses);

        fputcsv($handle, $row);
    }
    rewind($handle);
    logTime("Assembled CSV file. Uploading plaintext to S3 bucket...");
    $s3->putObject([
        'ACL' => 'public-read',
        'Bucket' => getenv('S3_BUCKET', true),
        'Key' => $csvFileName,
        'Body' => $handle,
        'ContentType' => 'text/csv',
        'Expires' => $lastModified + 70 * 60,
    ]);
    fclose($handle);

    /*
    logTime("Uploading compressed CSV to S3 bucket...");
    $s3->putObject([
        'ACL' => 'public-read',
        'Bucket' => getenv('S3_BUCKET', true),
        'Key' => $csvFileName,
        'Body' => gzcompress(file_get_contents($tempCsvPath)),
        'ContentType' => 'text/csv',
        'ContentEncoding' => 'gzip',
        'Expires' => $lastModified + 60 * 60,
    ]);
    */

    logTime("Writing file to local disk.");
    chmod($tempCsvPath, 0644);
    touch($tempCsvPath, $lastModified);
    rename($tempCsvPath, CSV_PATH . "/{$csvFileName}");

    return $lastModified;
}

function readSummary(string $region): object {
    $path = SUMMARY_PATH . "/{$region}.json";

    if (!file_exists($path)) {
        return (object)[];
    }

    $data = json_decode(file_get_contents($path));
    if (json_last_error() !== JSON_ERROR_NONE) {
        logTime("Summary file could not be parsed as JSON: " . json_last_error_msg());

        return (object)[];
    }

    return $data;
}

function writeSummary(string $region, object $data): void {
    $path = SUMMARY_PATH . "/{$region}.json";

    file_put_contents($path, json_encode($data));
}
