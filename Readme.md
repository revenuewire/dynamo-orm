# Install
composer require revenuewire/dynamo-orm

# Configuration
```php
<?php
if (APPLICATION_ENV == "local" || APPLICATION_ENV == "qa") {
    Model::configure(["region" => NETWORK_REGION, "endpoint" => 'http://dynamodb:8000']);
} else {
   Model::configure(["region" => NETWORK_REGION]);
}
```

# Model
Manually create object class.
```php
<?php
use RW\DynamoDb\Model;

class User extends Model
{
    public static $tableName = 'user';
    /**
     * DynamoDB Schema Definition
     */
    public static $schema = [
        "TableName" => "user",
        "AttributeDefinitions" => [
            [
                'AttributeName' => 'id',
                'AttributeType' => 'S',
            ]
        ],
        'KeySchema' => [
            [
                'AttributeName' => 'id',
                'KeyType' => 'HASH',
            ]
        ],
        'ProvisionedThroughput' => [
            'ReadCapacityUnits' => 5,
            'WriteCapacityUnits' => 5,
        ],
    ];
}
```

# Install DB
```php
<?php
require_once (__DIR__ . "/../vendor/autoload.php");

Model::configure(["region" => NETWORK_REGION]);

$schemas = [
    \Models\User::$schema,
];

echo "Install DBs...";
foreach ($schemas as $schema) {
    try {
        Model::$client->deleteTable([
            "TableName" => $schema['TableName']
        ]);
    } catch (Exception $e) {}
    Model::$client->createTable($schema);
}
echo "done\n";
```

# Usage

## Create
```php
<?php
$user = new User();
$user->id = "my-id";
$user->firstName = "hello";
$user->lastName = "world";
$user->save();
```

## Find one and update
```php
<?php
$user = User::getById('my-id');
$user->lastName = "wood";
$user->save();
```