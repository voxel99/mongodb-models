<?php

declare(strict_types=1);

namespace Jam\Models\MongoDB\Tests\Fixtures\Mongo;

use Jam\Models\MongoDB\MongoModel;

class Category extends MongoModel
{
    protected $table = 'categories';
    protected string $fields = 'id, title';
    protected $types = ['int' => 'id'];
}
