<?php

declare(strict_types=1);

namespace Jam\Models\MongoDB\Tests\Fixtures\Mongo;

use Jam\Models\MongoDB\MongoModel;

/** many-to-many внутри Mongo через pivot-коллекцию */
class Product extends MongoModel
{
    protected $table = 'products';
    protected string $fields = 'id, name, price';
    protected $types = ['int' => 'id', 'float' => 'price'];

    public function __construct($data = null)
    {
        $this->hasMany(Category::class, 'categories', 'id', 'id', [], ProductCategory::class, 'product_id', 'category_id');
        parent::__construct($data);
    }
}
