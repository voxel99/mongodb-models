<?php

namespace Jam\Models\MongoDB;

use Jam\Models\Cast\CastClosure;
use Jam\Models\Model;
use Jam\Models\ModelException;
use Jam\Models\Storage\Criteria;
use Jam\Models\Storage\Query;
use Jam\Models\Storage\StorageInterface;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Operation\FindOneAndUpdate;

/**
 * Хранилище моделей в MongoDB: коллекция = $table модели, первичный ключ модели = _id.
 *
 * Ключи новых документов:
 *   ID_AUTOINCREMENT (по умолчанию) — целые 1, 2, 3... из служебной коллекции _counters.
 *     Совместимо с SQL-моделями: внешние ключи в SQL-таблицах остаются целыми.
 *   ID_OBJECTID — ObjectId; в модели ключ представлен 24-символьной hex-строкой.
 *
 * Хранение значений:
 *   - поля с типом json хранятся вложенными документами (а не JSON-строкой), модель получает
 *     их так же, как из SQL — объектом stdClass;
 *   - поля с типами int/uint/float/bool — числами (строки из форм/SQL приводятся);
 *   - остальное — как есть; даты Timestamps/SoftDeletes — строками 'Y-m-d H:i:s'
 *     (сравниваются и сортируются корректно). BSON-даты при чтении приводятся к той же строке.
 *
 * Поддерживаются только условия-массивы (Criteria); SQL-фрагменты, JOIN, GROUP BY и блокировки — нет.
 */
class MongoStorage implements StorageInterface
{
    public const ID_AUTOINCREMENT = 'autoincrement';
    public const ID_OBJECTID = 'objectid';

    public const COUNTERS_COLLECTION = '_counters';

    private const NUMERIC_TYPES = [CastClosure::TYPE_INT, CastClosure::TYPE_UINT, CastClosure::TYPE_FLOAT, CastClosure::TYPE_BOOL];

    public function __construct(
        private string $connection = MongoConnection::DEFAULT,
        private string $idStrategy = self::ID_AUTOINCREMENT,
    ) {
    }

    public function collection(Model $model): Collection
    {
        return MongoConnection::get($this->connection)->selectCollection($model->table());
    }

    public function select(Model $model, Query $query): array
    {
        $options = array_filter([
            'projection' => $this->projection($model, $query->fields),
            'sort' => $this->sort($model, $query->orderBy),
            'limit' => $query->limit,
            'skip' => $query->offset,
        ]);
        $cursor = $this->collection($model)->find($this->filter($model, $query), $options);

        $order = $options['projection'] ?? null ? array_map(fn($f) => $this->shortName($f), $query->fields) : null;
        $rows = [];
        foreach ($cursor as $document) {
            $row = $this->documentToRow($model, $document);
            // Порядок полей — как в запросе (column() берёт первое поле строки)
            $rows[] = $order ? array_replace(array_intersect_key(array_flip($order), $row), $row) : $row;
        }
        return $rows;
    }

    public function aggregate(Model $model, Query $query, string $function, string $field): mixed
    {
        $filter = $this->filter($model, $query);
        $collection = $this->collection($model);
        if ($function === 'COUNT') {
            if ($field !== '*') {
                $filter = ['$and' => [$filter ?: (object) [], [$this->fieldName($model, $field) => ['$ne' => null]]]];
            }
            return $collection->countDocuments($filter);
        }

        $operator = match ($function) {
            'SUM' => '$sum',
            'AVG' => '$avg',
            'MIN' => '$min',
            'MAX' => '$max',
            default => throw new ModelException("Unsupported aggregate function: $function"),
        };
        $pipeline = [
            ['$match' => $filter ?: (object) []],
            ['$group' => ['_id' => null, 'value' => [$operator => '$' . $this->fieldName($model, $field)]]],
        ];
        $result = $collection->aggregate($pipeline)->toArray();
        return isset($result[0]) ? $this->plainValue($result[0]['value']) : null;
    }

    public function insert(Model $model, array $rows, bool $ignore = false, bool $upsert = false): int|string
    {
        $collection = $this->collection($model);
        $id = 0;
        foreach ($rows as $row) {
            $document = $this->rowToDocument($model, $row);
            $document['_id'] ??= $this->nextId($model);
            $id = $this->externalId($document['_id']);

            if ($upsert) {
                $set = $document;
                unset($set['_id']);
                $collection->updateOne(['_id' => $document['_id']], $set ? ['$set' => $set] : ['$setOnInsert' => ['_id' => $document['_id']]], ['upsert' => true]);
                continue;
            }
            try {
                $collection->insertOne($document);
            } catch (BulkWriteException $e) {
                if (!$ignore || !self::isDuplicateKey($e)) {
                    throw $e;
                }
                $id = 0; // как INSERT IGNORE: строка не вставлена
            }
        }
        return $id;
    }

    public function update(Model $model, int|string|array $id, array $data): int
    {
        $set = $this->rowToDocument($model, $data);
        unset($set['_id']);
        if (!$set) {
            return 0;
        }
        return $this->collection($model)
            ->updateMany($this->idFilter($id), ['$set' => $set])
            ->getModifiedCount() ?? 0;
    }

    public function increment(Model $model, int|string $id, string $field, int|float $by): int
    {
        return $this->collection($model)
            ->updateOne($this->idFilter($id), ['$inc' => [$this->fieldName($model, $field) => $by]])
            ->getModifiedCount() ?? 0;
    }

    public function delete(Model $model, int|string|array $id): int
    {
        return $this->collection($model)->deleteMany($this->idFilter($id))->getDeletedCount() ?? 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function filter(Model $model, Query $query): array
    {
        if ($query->hasRawSql()) {
            throw new ModelException(sprintf(
                '%s (%s) supports only array conditions: where([...]); SQL fragments, joins, GROUP BY and locks are SQL-only',
                $model::class,
                self::class
            ));
        }
        $conditions = $query->where;
        if ($query->softDeleteColumn !== null) {
            $conditions[] = new Criteria([$query->softDeleteColumn => null]);
        }
        return $this->compiler($model)->compileAll($conditions);
    }

    private function compiler(Model $model): FilterCompiler
    {
        return new FilterCompiler(
            fn(string $field) => $this->fieldName($model, $field),
            fn(string $field, mixed $value) => $this->candidates($model, $field, $value),
            fn(string $field, mixed $value) => $this->normalize($model, $field, $value),
        );
    }

    /** Имя поля в документе: без алиаса таблицы, первичный ключ -> _id */
    private function fieldName(Model $model, string $field): string
    {
        if (str_contains($field, '.')) {
            $field = substr($field, strrpos($field, '.') + 1);
        }
        return $field === $model->pk() ? '_id' : $field;
    }

    /**
     * Варианты значения для сравнения на равенство.
     *
     * @return array<int, mixed>
     */
    private function candidates(Model $model, string $field, mixed $value): array
    {
        $name = $this->shortName($field);
        if ($name === $model->pk()) {
            return [$this->internalId($value)];
        }
        if (in_array($model->getType($name), self::NUMERIC_TYPES, true)) {
            return [$this->normalize($model, $name, $value)];
        }
        // Тип неизвестен: '5' и 5 — одно и то же для SQL, но не для Mongo
        if (is_string($value) && is_numeric($value)) {
            return [$value, $value + 0];
        }
        if (is_int($value) || is_float($value)) {
            return [$value, (string) $value];
        }
        return [$value];
    }

    private function normalize(Model $model, string $field, mixed $value): mixed
    {
        $name = $this->shortName($field);
        if ($name === $model->pk()) {
            return $this->internalId($value);
        }
        return match ($model->getType($name)) {
            CastClosure::TYPE_INT, CastClosure::TYPE_UINT => is_numeric($value) ? (int) $value : $value,
            CastClosure::TYPE_FLOAT => is_numeric($value) ? (float) $value : $value,
            CastClosure::TYPE_BOOL => (int) (bool) $value,
            default => is_string($value) && is_numeric($value) ? $value + 0 : $value,
        };
    }

    private function shortName(string $field): string
    {
        return str_contains($field, '.') ? substr($field, strrpos($field, '.') + 1) : $field;
    }

    /**
     * Строка модели (db-представление) -> документ
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function rowToDocument(Model $model, array $row): array
    {
        $document = [];
        foreach ($row as $field => $value) {
            if ($field === $model->pk()) {
                if ($value !== null && $value !== '' && $value !== 0 && $value !== '0') {
                    $document['_id'] = $this->internalId($value);
                }
                continue;
            }
            $type = $model->getType($field);
            if ($type === CastClosure::TYPE_JSON && is_string($value)) {
                $value = json_decode($value, true);
            } elseif ($value !== null && in_array($type, self::NUMERIC_TYPES, true)) {
                $value = $this->normalize($model, $field, $value);
            }
            $document[$field] = $value;
        }
        return $document;
    }

    /**
     * Документ -> строка модели: _id -> pk, BSON-типы -> скаляры
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function documentToRow(Model $model, array $document): array
    {
        $row = [];
        foreach ($document as $field => $value) {
            if ($field === '_id') {
                $row[$model->pk()] = $this->externalId($value);
                continue;
            }
            $value = $this->plainValue($value);
            // JSON-поле отдаём модели так же, как SQL (строкой): внешнее представление
            // будет одинаковым — объект stdClass, а не массив
            if (is_array($value) && $model->getType($field) === CastClosure::TYPE_JSON) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            }
            $row[$field] = $value;
        }
        return $row;
    }

    private function plainValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof ObjectId => (string) $value,
            $value instanceof UTCDateTime => $value->toDateTime()->format('Y-m-d H:i:s'),
            $value instanceof Decimal128 => (string) $value,
            is_array($value) => array_map($this->plainValue(...), $value),
            default => $value,
        };
    }

    /** @return array<string, mixed>|null */
    private function projection(Model $model, ?array $fields): ?array
    {
        if (!$fields) {
            return null;
        }
        $projection = [];
        foreach ($fields as $field) {
            if ($field === '*' || str_ends_with($field, '.*')) {
                return null;
            }
            if (preg_match('/[\s(]/', $field)) {
                throw new ModelException("Expressions are not supported in MongoDB field lists: $field");
            }
            $projection[$this->fieldName($model, $field)] = 1;
        }
        // _id Mongo возвращает всегда — исключаем, если ключ не запрошен
        return $projection + ['_id' => 0];
    }

    /** @return array<string, int>|null */
    private function sort(Model $model, mixed $orderBy): ?array
    {
        if (!$orderBy) {
            return null;
        }
        if (is_object($orderBy) && !empty($orderBy->key)) {
            $orderBy = $orderBy->key . ' ' . ($orderBy->order ?? '');
        }
        $sort = [];
        foreach (explode(',', (string) $orderBy) as $part) {
            [$field, $direction] = array_pad(preg_split('/\s+/', trim($part)) ?: [], 2, '');
            if ($field === '') {
                continue;
            }
            if (str_contains($field, '(')) {
                throw new ModelException("Expressions are not supported in MongoDB sort: $orderBy");
            }
            $sort[$this->fieldName($model, trim($field, '`'))] = strtoupper($direction) === 'DESC' ? -1 : 1;
        }
        return $sort ?: null;
    }

    /** @return array<string, mixed> */
    private function idFilter(int|string|array $id): array
    {
        return is_array($id)
            ? ['_id' => ['$in' => array_map($this->internalId(...), array_values($id))]]
            : ['_id' => $this->internalId($id)];
    }

    /** Ключ модели -> _id документа */
    private function internalId(mixed $id): mixed
    {
        if ($id instanceof ObjectId) {
            return $id;
        }
        if ($this->idStrategy === self::ID_OBJECTID && is_string($id) && preg_match('/^[0-9a-f]{24}$/i', $id)) {
            return new ObjectId($id);
        }
        return is_string($id) && ctype_digit($id) ? (int) $id : $id;
    }

    /** _id документа -> ключ модели */
    private function externalId(mixed $id): int|string
    {
        return $id instanceof ObjectId ? (string) $id : $id;
    }

    private function nextId(Model $model): int|ObjectId
    {
        if ($this->idStrategy === self::ID_OBJECTID) {
            return new ObjectId();
        }
        $counter = MongoConnection::get($this->connection)
            ->selectCollection(self::COUNTERS_COLLECTION)
            ->findOneAndUpdate(
                ['_id' => $model->table()],
                ['$inc' => ['seq' => 1]],
                ['upsert' => true, 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
            );
        return (int) $counter['seq'];
    }

    private static function isDuplicateKey(BulkWriteException $e): bool
    {
        foreach ($e->getWriteResult()->getWriteErrors() as $error) {
            if ($error->getCode() === 11000) {
                return true;
            }
        }
        return false;
    }
}
