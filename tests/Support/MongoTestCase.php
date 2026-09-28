<?php

declare(strict_types=1);

namespace Jam\Models\MongoDB\Tests\Support;

use Jam\DbSimple\Adapter\Sqlite;
use Jam\Models\Model;
use Jam\Models\MongoDB\MongoConnection;
use MongoDB\Database;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционные тесты: MongoDB (MONGODB_TEST_URI) + SQLite в памяти для SQL-моделей.
 */
abstract class MongoTestCase extends TestCase implements CommandSubscriber
{
    protected Database $mongo;
    protected Sqlite $sql;

    /** @var array<int, string> Выполненные команды Mongo: "find comments", "insert events" ... */
    protected array $mongoLog = [];

    /** @var array<int, string> Выполненный SQL */
    protected array $sqlLog = [];

    protected function setUp(): void
    {
        $uri = (string) getenv('MONGODB_TEST_URI');
        if ($uri === '') {
            $this->markTestSkipped('Set MONGODB_TEST_URI (e.g. mongodb://127.0.0.1:27017) to run MongoDB integration tests');
        }

        $this->mongo = MongoConnection::connect($uri, 'mongodb_models_test');
        $this->mongo->drop();
        \MongoDB\Driver\Monitoring\addSubscriber($this);

        $this->sql = new Sqlite(['path' => ':memory:']);
        $this->sql->getPdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(64), name VARCHAR(64))');
        $this->sql->getPdo()->exec('CREATE TABLE articles (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title VARCHAR(64))');
        $this->sql->setLogger(function ($db, $sql): void {
            if (is_string($sql) && !str_starts_with(ltrim($sql), '--')) {
                $this->sqlLog[] = trim((string) preg_replace('/\s+/', ' ', $sql));
            }
        });
        Model::initDbSimple([Model::DB_MASTER => $this->sql]);
    }

    protected function tearDown(): void
    {
        \MongoDB\Driver\Monitoring\removeSubscriber($this);
        MongoConnection::forget();
        Model::initDbSimple([]);
    }

    protected function resetLogs(): void
    {
        $this->mongoLog = [];
        $this->sqlLog = [];
    }

    public function commandStarted(CommandStartedEvent $event): void
    {
        $command = $event->getCommand();
        $name = $event->getCommandName();
        if (in_array($name, ['find', 'insert', 'update', 'delete', 'aggregate', 'count', 'findAndModify'], true)) {
            $this->mongoLog[] = $name . ' ' . ($command->{$name} ?? '');
        }
    }

    public function commandSucceeded(CommandSucceededEvent $event): void
    {
    }

    public function commandFailed(CommandFailedEvent $event): void
    {
    }
}
