<?php

namespace Jam\Models\MongoDB;

use Jam\Models\Storage\StorageInterface;

/**
 * Подключает модель к MongoDB. Используйте в своей базовой модели или в конкретной модели:
 *
 * <code>
 * class Event extends BaseModel
 * {
 *     use UsesMongoStorage;
 *
 *     protected $table = 'events';                           // коллекция
 *     protected string $mongoConnection = 'default';        // см. MongoConnection
 *     protected string $mongoIdStrategy = MongoStorage::ID_AUTOINCREMENT;
 * }
 * </code>
 */
trait UsesMongoStorage
{
    protected function createStorage(): StorageInterface
    {
        return new MongoStorage(
            property_exists($this, 'mongoConnection') ? $this->mongoConnection : MongoConnection::DEFAULT,
            property_exists($this, 'mongoIdStrategy') ? $this->mongoIdStrategy : MongoStorage::ID_AUTOINCREMENT,
        );
    }
}
