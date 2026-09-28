<?php

declare(strict_types=1);

namespace Jam\Models\MongoDB\Tests\Fixtures\Mongo;

use Jam\Models\MongoDB\MongoModel;
use Jam\Models\MongoDB\MongoStorage;
use Jam\Models\MongoDB\Tests\Fixtures\Sql\User;

/** Ключи ObjectId (в модели — 24-символьная hex-строка) */
class Session extends MongoModel
{
    protected $table = 'sessions';
    protected string $fields = 'id, user_id, ip, last_seen';
    protected $types = ['int' => 'user_id'];
    protected string $mongoIdStrategy = MongoStorage::ID_OBJECTID;

    public function __construct($data = null)
    {
        $this->belongs(User::class, 'user', 'user_id', 'id');
        parent::__construct($data);
    }
}
