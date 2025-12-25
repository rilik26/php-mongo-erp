<?php
require_once __DIR__ . '/vendor/autoload.php';

$client = new MongoDB\Client("mongodb://recepilik26:rFFruIIcZzWEj0lx@cluster0-shard-00-00.kc5qf.mongodb.net:27017,cluster0-shard-00-01.kc5qf.mongodb.net:27017,cluster0-shard-00-02.kc5qf.mongodb.net:27017/erp_main?ssl=true&replicaSet=atlas-xxxx-shard-0&authSource=admin&retryWrites=true&w=majority");
echo "OK";
