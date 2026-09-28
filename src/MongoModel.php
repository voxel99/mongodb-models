<?php

namespace Jam\Models\MongoDB;

use Jam\Models\Model;

/**
 * Базовая модель на MongoDB. Всё API — как у Jam\Models\Model: поля, типы, связи (в том числе
 * с SQL-моделями), with(), события, Timestamps, SoftDeletes, toArray().
 * Условия — только массивом: where(['status' => [1, 2]]).
 */
abstract class MongoModel extends Model
{
    use UsesMongoStorage;

    /** Имя соединения в MongoConnection */
    protected string $mongoConnection = MongoConnection::DEFAULT;

    /** MongoStorage::ID_AUTOINCREMENT (целые ключи) или MongoStorage::ID_OBJECTID */
    protected string $mongoIdStrategy = MongoStorage::ID_AUTOINCREMENT;
}
