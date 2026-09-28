<?php

declare(strict_types=1);

namespace Jam\Models\MongoDB\Tests\Fixtures\Mongo;

use Jam\Models\MongoDB\MongoModel;

class ProductCategory extends MongoModel
{
    protected $table = 'product_category';
    protected string $fields = 'id, product_id, category_id';
    protected $types = ['int' => 'id, product_id, category_id'];
}
