<?php

declare(strict_types=1);

namespace Jam\Models\MongoDB\Tests\Fixtures\Sql;

use Jam\Models\Model;
use Jam\Models\MongoDB\Tests\Fixtures\Mongo\Comment;

class Article extends Model
{
    protected $table = 'articles';
    protected string $fields = 'id, user_id, title';
    protected $types = ['int' => 'id, user_id'];

    public function __construct($data = null)
    {
        $this->belongs(User::class, 'author', 'user_id', 'id');
        $this->hasMany(Comment::class, 'comments', 'id', 'article_id');
        parent::__construct($data);
    }
}
