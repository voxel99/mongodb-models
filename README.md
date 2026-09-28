# Jam MongoDB Models

MongoDB models for PHP with the same API as [`jam/dbsimple-models`](https://github.com/voxel99/dbsimple-models):
fields, casting, relations, eager loading, events, timestamps and soft deletes on MongoDB collections.
Array conditions compile to MongoDB filters; relations work across MongoDB and SQL models, including
saving object graphs.

```php
final class Activity extends MongoModel
{
    use Timestamps;

    protected $table = 'activities';                                   // collection
    protected string $fields = 'id, user_id, action, meta, created_at, updated_at';
    protected $types = ['int' => 'id, user_id', 'json' => 'meta'];   // meta is a nested document

    public function __construct($data = null)
    {
        $this->belongs(User::class, 'user', 'user_id', 'id');         // User is a SQL model
        parent::__construct($data);
    }
}

$logins = Activity::instance()
    ->with('user')                                                    // one SQL query for all authors
    ->where(['action' => 'login', 'created_at' => ['>=' => '2024-01-01']])
    ->orderBy('created_at DESC')
    ->limit(20)
    ->all();
```

## Requirements

- PHP 8.4+, `ext-mongodb` 2.1+, `mongodb/mongodb` 2.1+
- `jam/dbsimple-models` with the storage layer (`Jam\Models\Storage`)
- MongoDB 5.0+ (tested with 7.0)

## Setup

```php
use Jam\Models\MongoDB\MongoConnection;

MongoConnection::connect('mongodb://127.0.0.1:27017', 'app');               // 'default'
MongoConnection::connect('mongodb://analytics:27017', 'events', 'stats');   // named connection
```

A model becomes a MongoDB model by extending `MongoModel`, or by using the `UsesMongoStorage`
trait in your own base model:

```php
abstract class BaseMongoModel extends BaseModel
{
    use UsesMongoStorage;

    protected string $mongoConnection = 'default';
    protected string $mongoIdStrategy = MongoStorage::ID_AUTOINCREMENT;
}
```

## What works as with SQL models

| Feature | Notes |
|---------|-------|
| `$fields`, `$types`, `$hidden`, `$guarded`, `$computed`, `$extra`, `change*()` mutators | unchanged |
| `save()`, `update($fields)`, `delete()`, `incByUpdate()`, `updateRaw()`, `ModelList::insert()` | `INSERT IGNORE` / upsert semantics are emulated |
| `where([...])`, `orderBy()`, `limit()`, `offset()`, `first()`, `all()`, `column()`, `value()`, `chunk()` | array conditions only |
| `count()`, `sum()`, `avg()`, `min()`, `max()` | `countDocuments` / aggregation pipeline |
| `hasOne`, `hasMany`, `belongs`, many-to-many via a pivot collection | also **to and from SQL models** |
| `with('rel(fields):o(...):c')`, nested `with()`, `toArray()` flags | one query per relation |
| Saving graphs, `SAVE_USE_CHECK_OLD` | across storages |
| Events, `Timestamps`, `SoftDeletes` | unchanged |

Not supported (SQL-only): SQL fragments in `where('...')` and in `with('rel[...]')`, joins,
`GROUP BY`, locks, subqueries. A model throws a clear `ModelException` if it gets one.

## Conditions

```php
Activity::instance()->where([
    'user_id' => 5,                          // equality
    'action' => ['login', 'logout'],         // $in
    'deleted_at' => null,                    // null or missing
    'score' => ['>=' => 10, '<' => 100],
    'title' => ['like' => 'PHP%'],           // case-insensitive regex
    'status' => ['!=' => 3],                 // $nin [3, null] — like SQL, NULL is not "!= 3"
    Criteria::OR => [['pinned' => 1], ['views' => ['>' => 1000]]],
]);
```

SQL compares loosely (`'5' = 5`), MongoDB strictly. Values are normalized using the model's
`$types` (`int`, `float`, `bool` fields are stored and queried as numbers); for untyped fields a
numeric string matches both `'5'` and `5`. Keys coming from HTTP requests or SQL rows (`'12'`) work.

## Keys

| `$mongoIdStrategy` | `_id` | In the model |
|--------------------|-------|--------------|
| `MongoStorage::ID_AUTOINCREMENT` (default) | 1, 2, 3… (counter in the `_counters` collection) | `int` — foreign keys in SQL tables stay integers |
| `MongoStorage::ID_OBJECTID` | `ObjectId` | 24-character hex string |

The model's primary key (`$pk`, `id` by default) maps to `_id`.

## Storage format

- `json` fields are stored as nested documents and reach the model exactly as from a SQL JSON column.
- `int` / `uint` / `float` / `bool` fields are stored as numbers.
- Dates from `Timestamps` / `SoftDeletes` are stored as `'Y-m-d H:i:s'` strings (they sort and compare
  correctly); BSON dates written by other tools are read back in the same format.

## Example

```bash
docker run -d -p 27017:27017 mongo:7
MONGODB_URI=mongodb://127.0.0.1:27017 php examples/quickstart.php
```

## Development

`composer.json` resolves `jam/dbsimple-models` and `jam/dbsimple` from sibling checkouts
(`../dbsimple-models`, `../dbsimple`) — clone all three repositories next to each other.

## Tests

```bash
composer install
vendor/bin/phpunit                                                 # unit tests (no server)
MONGODB_TEST_URI=mongodb://127.0.0.1:27017 vendor/bin/phpunit      # + integration tests
```

Integration tests use MongoDB and an in-memory SQLite database (via `jam/dbsimple`) to check
relations between MongoDB and SQL models.

## License

[GNU Lesser General Public License v2.1](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html).
