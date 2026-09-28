<?php

namespace Jam\Models\MongoDB;

use MongoDB\Client;
use MongoDB\Database;
use RuntimeException;

/**
 * Реестр баз MongoDB, с которыми работают модели.
 *
 * <code>
 * MongoConnection::connect('mongodb://127.0.0.1:27017', 'app');            // соединение 'default'
 * MongoConnection::connect('mongodb://analytics:27017', 'events', 'stats'); // именованное
 * </code>
 *
 * Документы читаются как PHP-массивы (typeMap array), чтобы модели получали те же структуры,
 * что и из SQL.
 */
final class MongoConnection
{
    public const DEFAULT = 'default';

    private const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    /** @var array<string, Database> */
    private static array $databases = [];

    /**
     * @param array<string, mixed> $uriOptions
     * @param array<string, mixed> $driverOptions
     */
    public static function connect(
        string $uri,
        string $database,
        string $name = self::DEFAULT,
        array $uriOptions = [],
        array $driverOptions = []
    ): Database {
        $client = new Client($uri, $uriOptions, $driverOptions + ['typeMap' => self::TYPE_MAP]);
        return self::register($client->selectDatabase($database), $name);
    }

    public static function register(Database $database, string $name = self::DEFAULT): Database
    {
        return self::$databases[$name] = $database->withOptions(['typeMap' => self::TYPE_MAP]);
    }

    public static function get(string $name = self::DEFAULT): Database
    {
        return self::$databases[$name]
            ?? throw new RuntimeException(sprintf('MongoDB connection `%s` is not registered: call MongoConnection::connect()', $name));
    }

    public static function has(string $name = self::DEFAULT): bool
    {
        return isset(self::$databases[$name]);
    }

    public static function forget(?string $name = null): void
    {
        if ($name === null) {
            self::$databases = [];
        } else {
            unset(self::$databases[$name]);
        }
    }
}
