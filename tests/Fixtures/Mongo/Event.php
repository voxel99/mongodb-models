<?php

declare(strict_types=1);

namespace Jam\Models\MongoDB\Tests\Fixtures\Mongo;

use Jam\Models\MongoDB\MongoModel;
use Jam\Models\Traits\SoftDeletes;
use Jam\Models\Traits\Timestamps;

/** Типы, вложенные документы, SoftDeletes */
class Event extends MongoModel
{
    use Timestamps;
    use SoftDeletes;

    protected $table = 'events';
    protected string $fields = 'id, type, payload, tags, score, is_public, created_at, updated_at, deleted_at';
    protected string $computed = 'summary';
    protected $types = [
        'int' => 'id',
        'float' => 'score',
        'bool' => 'is_public',
        'delim' => 'tags',
        'json' => 'payload(source, level)',
    ];

    public function summary(): string
    {
        return $this->type . ':' . ($this->payload->source ?? $this->payload['source'] ?? '?');
    }
}
