<?php

declare(strict_types=1);

namespace Jam\Models\MongoDB\Tests\Fixtures\Sql;

use Jam\Models\Model;
use Jam\Models\MongoDB\Tests\Fixtures\Mongo\Comment;
use Jam\Models\MongoDB\Tests\Fixtures\Mongo\Session;

/** SQL-модель (SQLite) со связями на Mongo-модели */
class User extends Model
{
    protected $table = 'users';
    protected string $fields = 'id, email, name';
    protected $types = ['int' => 'id'];

    public function __construct($data = null)
    {
        $this->hasMany(Comment::class, 'comments', 'id', 'user_id');
        $this->hasMany(Session::class, 'sessions', 'id', 'user_id');
        parent::__construct($data);
    }
}
