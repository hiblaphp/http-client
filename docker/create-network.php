<?php

$output = [];
$code   = 0;

exec('docker network inspect hibla_shared 2>&1', $output, $code);

if ($code !== 0) {
    echo "Creating Docker network: hibla_shared\n";
    passthru('docker network create hibla_shared');
} else {
    echo "Docker network hibla_shared already exists, skipping.\n";
}