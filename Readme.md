# Install
composer require revenuewire/dynamo-orm

# Configuration
```php
if (APPLICATION_ENV == "local" || APPLICATION_ENV == "qa") {
    Model::configure(["region" => NETWORK_REGION, "endpoint" => 'http://dynamodb:8000']);
} else {
   Model::configure(["region" => NETWORK_REGION]);
}
```