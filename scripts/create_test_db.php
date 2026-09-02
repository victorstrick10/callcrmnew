<?php

$pdo = new PDO('pgsql:host=127.0.0.1;port=5433;dbname=postgres', 'call_crm', 'call_crm');
$exists = $pdo->query("SELECT 1 FROM pg_database WHERE datname = 'call_crm_test'")->fetch();
if (! $exists) {
    $pdo->exec('CREATE DATABASE call_crm_test OWNER call_crm');
    echo "created\n";
} else {
    echo "exists\n";
}
