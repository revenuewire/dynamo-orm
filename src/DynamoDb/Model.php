<?php
namespace RW\DynamoDb;
use Aws\CommandInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Psr\Http\Message\RequestInterface;

/**
 * Class Model
 */
class Model
{
    protected $data;
    protected $table;
    protected $isNew;
    protected $modifiedColumns;
    protected $dbClient;

    /** @var $marshaller Marshaler */
    public static $marshaller;

    /** @var $client DynamoDbClient */
    public static $client;

    public static $config;

    protected static $schema = [];

    /**
     * Init the db
     * @param $config
     * @param $mock MockHandler
     *
     * @return DynamoDbClient
     */
    public static function configure($config, $mock = null)
    {
        $defaultConfig = [
            "region" => "us-west-1",
            "version" => "2012-08-10"
        ];
        self::$config = array_merge($defaultConfig, $config);

        if ($mock !== null) {
            self::$config['handler'] = $mock;
        }

        self::$client = new DynamoDbClient(self::$config);
        self::$marshaller = new Marshaler();

        return self::$client;
    }

    /**
     * Set Mock Handler
     *
     * @param array $result
     * @return MockHandler
     */
    public static function mock($result = ['foo' => 'bar'])
    {
        $mock = new MockHandler();

        // Return a mocked result.
        $mock->append(new Result($result));

        return $mock;
    }

    /**
     * Model constructor.
     *
     * @param null $config
     */
    function __construct($config = null)
    {
        if ($config === null && self::$client instanceof DynamoDbClient) {
            $this->dbClient = self::$client;
        } else {
            $defaultConfig = [
                "region" => "us-west-1",
                "version" => "2012-08-10"
            ];
            $config = array_merge($defaultConfig, $config);
            $this->dbClient = new DynamoDbClient($config);
        }
        $this->isNew = true;

        return $this;
    }

    /**
     * Get Property
     *
     * @param $property
     *
     * @return mixed
     */
    public function __get($property)
    {
        if (isset($this->data[$property])) {
            return $this->data[$property];
        }
        return null;
    }

    /**
     * Set Property
     *
     * @param $property
     * @param $value
     */
    public function __set($property, $value)
    {
        if ($this->isNew === false) {
            $this->modifiedColumns[$property] = true;
        }
        $this->data[$property] = $value;
    }

    /**
     * Set Id
     *
     * @param $id
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Get Id
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return mixed
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * @return mixed
     */
    public function getModified()
    {
        return $this->modified;
    }

    /**
     * Get A dump of data
     */
    public function debug()
    {
        var_dump($this->data);
    }

    /**
     * Return true if the object has been modified.
     *
     * @return bool
     */
    public function isModified()
    {
        return !empty($this->modifiedColumns);
    }

    /**
     * Get DynamoDB client
     * @param $table
     *
     */
    public static function init($table)
    {
        self::$marshaller = new Marshaler();

        $class = get_called_class();
        $class::$client = new DynamoDbClient($table);
        $class::$table = $table['name'];
    }

    /**
     * Populate Item into object
     *
     * @param $item
     *
     * @return Model
     */
    public static function populateItemToObject($item)
    {
        if (empty($item)) {
            return null;
        }

        $class = get_called_class();
        $object = new $class();
        foreach (self::$marshaller->unmarshalItem($item) as $k => $v) {
            $object->data[$k] = $v;
        }
        $object->isNew = false;
        return $object;
    }

    /**
     * Get a translation queue item by ID
     *
     * @param $id
     *
     * @return Model
     */
    public static function getById($id)
    {
        $class = get_called_class();

        /** @var $result Result */
        $result = $class::$client->getItem(array(
            'TableName' => $class::$tableName,
            'Key' => array(
                'id' => array('S' => $id)
            ),
            'ConsistentRead' => true,
        ));

        return self::populateItemToObject($result->get('Item'));
    }

    /**
     * Return the array
     *
     * @return mixed
     */
    public function toArray()
    {
        return $this->data;
    }

    /**
     * Convert a collection to array
     *
     * @param $collections
     * @return array
     */
    public static function collectionToArray($collections)
    {
        $collectionData = [];

        foreach ($collections as $collection) {
            $collectionData[] = $collection->toArray();
        }

        return $collectionData;
    }

    /**
     * Convert a query result to array
     *
     * @param $collections
     * @return array
     */
    public static function queryResultToArray($collections)
    {
        $collectionData = [];

        foreach ($collections->get('Items') as $item) {
            $collectionData[] = self::populateItemToObject($item)->toArray();
        }

        return $collectionData;
    }

    /**
     * Delete the object
     *
     * @return bool
     */
    public function delete()
    {
        $class = get_called_class();
        $item = [
            'TableName' => $class::$tableName,
            'Key' => array(
                'id' => array('S' => $this->getId())
            ),
            'ReturnValues' => "NONE",
        ];
        $class::$client->deleteItem($item);

        return true;
    }

    /**
     * Clean up the data
     *
     * @param $data
     * @return mixed
     */
    public static function dataSanity($data)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::dataSanity($data[$key]);
            }

            if ($data[$key] === null || $data[$key] === "" || $data[$key] === []) {
                unset($data[$key]);
            }
        }

        return $data;

    }

    /**
     * Save
     *
     * @return $this
     */
    public function save()
    {
        $this->data = self::dataSanity($this->data);

        $class = get_called_class();
        if ($this->isNew) {
            $this->isNew = false;
            $this->created = time();
            $this->modified = time();

            $item = array(
                'TableName' => $class::$tableName,
                'Item' => self::$marshaller->marshalItem($this->data),
                'ConditionExpression' => 'attribute_not_exists(id)',
                'ReturnValues' => 'ALL_OLD'
            );

            $class::$client->putItem($item);

            return $this;
        }

        if ($this->isModified()) {
            $this->modified = time();

            $expressionAttributeNames = [];
            $expressionAttributeValues = [];
            $updateExpressionHolder = [];
            foreach ($this->modifiedColumns as $field => $hasModified) {
                if ($hasModified === true && isset($this->data[$field])) {
                    $expressionAttributeNames['#' . $field] = $field;
                    $expressionAttributeValues[':'.$field] = self::$marshaller->marshalValue($this->data[$field]);
                    $updateExpressionHolder[] = "#$field = :$field";

                    $this->modifiedColumns[$field] = false;
                }
            }
            $updateExpression = implode(', ', $updateExpressionHolder);

            $updateAttributes = [
                'TableName' => $class::$tableName,
                'Key' => array(
                    'id' => self::$marshaller->marshalValue($this->id)
                ),
                'ExpressionAttributeNames' =>$expressionAttributeNames,
                'ExpressionAttributeValues' =>  $expressionAttributeValues,
                'ConditionExpression' => 'attribute_exists(id)',
                'UpdateExpression' => "set $updateExpression",
                'ReturnValues' => 'ALL_NEW'
            ];

            $class::$client->updateItem($updateAttributes);
        }

        return $this;
    }

    /**
     * @param $filters
     *
     * @return array
     * @throws Exception
     */
    protected static function keyConditionExpression($filters)
    {
        $index = null;
        $keyConditionExpression = null;
        $expressionAttributeValues = [];
        $expressionAttributeNames = [];
        foreach ($filters as $name => $filter) {
            $index = $name . '-idx';

            // do we have an index for this filter?
            if (!isset(self::$schema['GlobalSecondaryIndexes']) || !in_array($index, array_column(self::$schema['GlobalSecondaryIndexes'], 'IndexName'))) {
                $index = null;
                continue;
            }

            $expressionAttributeNames['#' . $name] = $name;

            if (is_array($filter)) {
                // hash indexes do not support multiple values, leave this commented out for now
                //                foreach($filter as $key => $value) {
                //                    $expressionAttributeValues[':' . $name . $key] = self::$marshaller->marshalValue($value);
                //                }
                //
                //                $keyConditionExpression = "#$name IN (" . implode(',', array_keys($expressionAttributeValues)) . ")";

                $index = null;
                continue;

            } else {
                $expressionAttributeValues[':' . $name] = self::$marshaller->marshalValue($filter);
                $keyConditionExpression = "#$name = :$name";
            }

            // dynamo only supports one index, so stop after first filter
            break;
        }

        return [$index, $keyConditionExpression, $expressionAttributeNames, $expressionAttributeValues];
    }

    /**
     * @param $filters
     *
     * @return array
     * @throws Exception
     */
    protected static function filterExpression($index, $filters)
    {
        $index = str_replace('-idx', '', $index);

        $filterExpressions = [];
        $expressionAttributeValues = [];
        $expressionAttributeNames = [];
        foreach ($filters as $name => $filter) {
            // do not use the index in the expression filter (https://docs.aws.amazon.com/aws-sdk-php/v2/guide/service-dynamodb.html)
            if ($index === $name) continue;

            $expressionAttributeNames['#' . $name] = $name;

            if (is_array($filter)) {
                foreach($filter as $key => $value) {
                    $expressionAttributeValues[':' . $name . $key] = self::$marshaller->marshalValue($value);
                }

                $filterExpressions[] = "#$name IN (" . implode(',', array_keys($expressionAttributeValues)) . ")";

            } else {
                $expressionAttributeValues[':' . $name] = self::$marshaller->marshalValue($filter);
                $filterExpressions[] = "#$name = :$name";
            }
        }

        return [implode(' AND ', $filterExpressions), $expressionAttributeNames, $expressionAttributeValues];
    }

    /**
     * @param $filters
     * @param $index
     *
     * @return array
     */
    protected static function scanFilter($filters, $index)
    {
        $index = str_replace('-idx', '', $index);

        $scanfilter = [];
        foreach ($filters as $name => $filter) {
            // do not use the index in the scan filter (https://docs.aws.amazon.com/aws-sdk-php/v2/guide/service-dynamodb.html)
            if ($name === $index) continue;

            $scanfilter[$name] = [
                'AttributeValueList' => []
            ];

            if (is_array($filter)) {
                $scanfilter[$name]['ComparisonOperator'] = 'IN';

                foreach ($filter as $value) {
                    $scanfilter[$name]['AttributeValueList'][] = self::$marshaller->marshalValue($value);
                }
            } else {
                $scanfilter[$name]['ComparisonOperator'] = 'EQ';
                $scanfilter[$name]['AttributeValueList'][] = self::$marshaller->marshalValue($filter);
            }
        }

        return $scanfilter;
    }
}
