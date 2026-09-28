<?php

declare(strict_types=1);

namespace Jam\Models\MongoDB\Tests\Fixtures\Mongo;

use Jam\Models\MongoDB\MongoModel;
use Jam\Models\MongoDB\Tests\Fixtures\Sql\Article;
use Jam\Models\MongoDB\Tests\Fixtures\Sql\User;
use Jam\Models\Traits\Timestamps;

/** Mongo-модель, связанная с SQL-моделями */
class Comment extends MongoModel
{
    use Timestamps;

    protected $table = 'comments';
    protected string $fields = 'id, article_id, user_id, body, rating, created_at, updated_at';
    protected $types = ['int' => 'id, article_id, user_id, rating'];

    public function __construct($data = null)
    {
        $this->belongs(Article::class, 'article', 'article_id', 'id');
        $this->belongs(User::class, 'author', 'user_id', 'id');
        parent::__construct($data);
    }
}
