<?php

declare(strict_types=1);

namespace Prooph\EventStoreBenchmarks;

use ArangoDb\Connection;
use PDO;
use Prooph\Common\Messaging\FQCNMessageFactory;
use function Prooph\EventStore\ArangoDb\Fn\eventStreamsBatch;
use function Prooph\EventStore\ArangoDb\Fn\execute;
use function Prooph\EventStore\ArangoDb\Fn\projectionsBatch;
use Prooph\EventStore\ArangoDb\Type\DeleteCollection;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\Pdo\MariaDbEventStore;
use Prooph\EventStore\Pdo\MySqlEventStore;
use Prooph\EventStore\Pdo\PersistenceStrategy\MariaDbAggregateStreamStrategy;
use Prooph\EventStore\Pdo\PersistenceStrategy\MySqlAggregateStreamStrategy;
use Prooph\EventStore\Pdo\PersistenceStrategy\PostgresAggregateStreamStrategy;
use Prooph\EventStore\Pdo\PostgresEventStore;
use Prooph\EventStore\Pdo\Projection\MariaDbProjectionManager;
use Prooph\EventStore\Pdo\Projection\MySqlProjectionManager;
use Prooph\EventStore\Pdo\Projection\PostgresProjectionManager;
use Prooph\EventStore\Projection\ProjectionManager;
use Prooph\EventStore\StreamName;
use ProophTest\EventStore\Mock\TestDomainEvent;
use Prooph\EventStore\ArangoDb\Projection\ProjectionManager as ArangoDbProjectionManager;
use Prooph\EventStore\ArangoDb\EventStore as ArangoDbEventStore;
use Prooph\EventStore\ArangoDb\PersistenceStrategy\AggregateStreamStrategy as ArangoDbAggregateStreamStrategy;

function testDatabases(): array
{
    return [
        'mysql' => getenv('MYSQL_DB'),
        'mariadb' => getenv('MARIADB_DB'),
        'postgres' => getenv('POSTGRES_DB'),
        'arangodb' => getenv('ARANGODB_DB'),
        'arangodbext' => getenv('ARANGODB_DB'),
    ];
}

function createConnection(string $driver)
{
    switch (strtolower($driver)) {
        case 'mysql':
            $host = getenv('MYSQL_HOST');
            $port = getenv('MYSQL_PORT');
            $dbName = getenv('MYSQL_DB');
            $charset = getenv('MYSQL_CHARSET');
            $username = getenv('MYSQL_USER');
            $password = getenv('MYSQL_PASSWORD');

            return new PDO("mysql:host=$host;port=$port;dbname=$dbName;charset=$charset;", $username, $password);
        case 'mariadb':
            $host = getenv('MARIADB_HOST');
            $port = getenv('MARIADB_PORT');
            $dbName = getenv('MARIADB_DB');
            $charset = getenv('MARIADB_CHARSET');
            $username = getenv('MARIADB_USER');
            $password = getenv('MARIADB_PASSWORD');

            return new PDO("mysql:host=$host;port=$port;dbname=$dbName;charset=$charset", $username, $password);
        case 'postgres':
            $host = getenv('POSTGRES_HOST');
            $port = getenv('POSTGRES_PORT');
            $dbName = getenv('POSTGRES_DB');
            $charset = getenv('POSTGRES_CHARSET');
            $username = getenv('POSTGRES_USER');
            $password = getenv('POSTGRES_PASSWORD');

            return new PDO("pgsql:host=$host;port=$port;dbname=$dbName;options='--client_encoding=\"$charset\"'", $username, $password);
        case 'arangodb':
        case 'arangodbext':
            $connection = new Connection(
                [
                    Connection::HOST => getenv('ARANGODB_HOST'),
                    Connection::MAX_CHUNK_SIZE => 64,
                    Connection::VST_VERSION => Connection::VST_VERSION_11,
                ]
            );
            $connection->connect();
            return $connection;
    }
}

function createDatabase($connection, string $driver, string $dbName): void
{
    switch (strtolower($driver)) {
        case 'mysql':
        case 'mariadb':
        case 'postgres':
            try {
                $path = '../vendor/prooph/pdo-event-store/scripts/' . $driver . '/';
                $connection->exec(file_get_contents($path . '01_event_streams_table.sql'));
                $connection->exec(file_get_contents($path . '02_projections_table.sql'));
            } catch (\Throwable $e) {
                echo $e->getMessage();
            }

            break;
        case 'arangodb':
        case 'arangodbext':
            $result = $connection->get('/_api/collection', ['excludeSystem'=>true]);

            $collections = json_decode($result->getBody(), true);

            if (count($collections['result']) > 1) {
                execute($connection,
                    null,
                    ...array_map(function ($col) {
                        return DeleteCollection::with($col['name']);
                    }, $collections['result'])
                );
            }

            execute($connection, null, ...eventStreamsBatch());
            execute($connection, null, ...projectionsBatch());
            break;
        default:
            throw new \RuntimeException(sprintf('Driver "%s" not supported', $driver));
    }
    unset($connection);
    // give DB some time
    sleep(1);
}

function destroyDatabase($connection, string $driver, string $dbName): void
{
    switch (strtolower($driver)) {
        case 'mysql':
        case 'mariadb':
        case 'postgres':
            /* @var PDO $connection */
            $statement = $connection->query("SELECT stream_name FROM event_streams;");
            $rows = $statement->fetchAll();
            foreach ($rows as $row) {
                $connection->exec('DROP TABLE ' . $row['stream_name'] . ';');
            }
            $connection->exec('DROP TABLE event_streams;');
            $connection->exec('DROP TABLE projections;');
            break;
        case 'arangodb':
        case 'arangodbext':
            $result = $connection->get('/_api/collection', ['excludeSystem' =>1]);

            $collections = json_decode($result->getBody(), true);

            if (count($collections['result']) > 1) {
                execute($connection,
                    null,
                    ...array_map(function ($col) {
                        return DeleteCollection::with($col['name']);
                    }, $collections['result'])
                );
            }
            break;
        default:
            throw new \RuntimeException(sprintf('Driver "%s" not supported', $driver));
    }
}

function createEventStore(string $driver, $connection): EventStore
{
    switch (strtolower($driver)) {
        case 'mysql':
            return new MySqlEventStore(
                new FQCNMessageFactory(),
                $connection,
                new MySqlAggregateStreamStrategy()
            );
        case 'mariadb':
            return new MariaDbEventStore(
                new FQCNMessageFactory(),
                $connection,
                new MariaDbAggregateStreamStrategy()
            );
        case 'postgres':
            return new PostgresEventStore(
                new FQCNMessageFactory(),
                $connection,
                new PostgresAggregateStreamStrategy()
            );
        case 'arangodb':
        case 'arangodbext':
            return new ArangoDbEventStore(
                new FQCNMessageFactory(),
                $connection,
                new ArangoDbAggregateStreamStrategy()
            );
    }
}

function createProjectionManager(EventStore $eventStore, string $driver, $connection): ProjectionManager
{
    switch (strtolower($driver)) {
        case 'mysql':
            return new MySqlProjectionManager(
                $eventStore,
                $connection
            );
        case 'mariadb':
            return new MariaDbProjectionManager(
                $eventStore,
                $connection
            );
        case 'postgres':
            return new PostgresProjectionManager(
                $eventStore,
                $connection
            );
        case 'arangodb':
        case 'arangodbext':
            return new ArangoDbProjectionManager(
                $eventStore,
                $connection
            );
    }
}

function createConnections(array $drivers): array
{
    $data = [];

    foreach ($drivers as $driver) {
        $data[$driver] = createConnection($driver);
    }
    return $data;
}

function createEventStores(array $connections): array
{
    $data = [];

    foreach ($connections as $driver => $connection) {
        $data[$driver] = createEventStore($driver, $connections[$driver]);
    }
    return $data;
}

function createProjectionManagers(array $eventStores, array $connections): array
{
    $data = [];

    foreach ($connections as $driver => $connection) {
        $data[$driver] = createProjectionManager($eventStores[$driver], $driver, $connections[$driver]);
    }
    return $data;
}

function createTestEvent(array $payload, int $version)
{
    return TestDomainEvent::with($payload, $version);
}

function createTestStreamName()
{
    return new StreamName(uniqid('stream'));
}

function createTestEvents(array $payload, int $amount, int $startFrom = 0)
{
    $events = [];

    for ($j = 0; $j < $amount; ++$j) {
        $events[] = createTestEvent($payload, ++$startFrom);
    }

    return $events;
}

function testPayload(): array
{
    return [
        'key1' => 'value1',
        'key2' => 2,
        'key3' => 'some@mail.com',
        'key4' => 'value4',
        'key5' => true,
        'key6' => false,
        'key7' => 7,
    ];
}


function outputText(string $text) {
    $time = new \DateTime('now');
    echo $time->format('Y-m-d\TH:i:s.u') . ': ' . $text . PHP_EOL;
}
