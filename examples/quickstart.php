<?php

/**
 * Быстрый старт: Mongo-модели рядом с SQL-моделями.
 *
 *   docker run -d -p 27017:27017 mongo:7
 *   MONGODB_URI=mongodb://127.0.0.1:27017 php examples/quickstart.php
 */

declare(strict_types=1);

use Jam\DbSimple\Adapter\Sqlite;
use Jam\Models\Model;
use Jam\Models\MongoDB\MongoConnection;
use Jam\Models\MongoDB\MongoModel;
use Jam\Models\Storage\Criteria;
use Jam\Models\Traits\Timestamps;

require __DIR__ . '/../vendor/autoload.php';

// SQL-часть приложения (здесь — SQLite в памяти; в проекте — MySQL через Jam\DbSimple\Connect)
$sql = new Sqlite(['path' => ':memory:']);
$sql->getPdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(64))');
Model::initDbSimple([Model::DB_MASTER => $sql]);

// Mongo-часть
MongoConnection::connect(getenv('MONGODB_URI') ?: 'mongodb://127.0.0.1:27017', 'mongodb_models_example')->drop();

final class User extends Model
{
    protected $table = 'users';
    protected string $fields = 'id, name';
    protected $types = ['int' => 'id'];

    public function __construct($data = null)
    {
        $this->hasMany(Activity::class, 'activities', 'id', 'user_id'); // связь на Mongo-модель
        parent::__construct($data);
    }
}

final class Activity extends MongoModel
{
    use Timestamps;

    protected $table = 'activities';                                      // коллекция
    protected string $fields = 'id, user_id, action, meta, created_at, updated_at';
    protected $types = ['int' => 'id, user_id', 'json' => 'meta'];      // meta — вложенный документ

    public function __construct($data = null)
    {
        $this->belongs(User::class, 'user', 'user_id', 'id');            // связь на SQL-модель
        parent::__construct($data);
    }
}

$alice = new User(['name' => 'Alice', 'activities' => [
    ['action' => 'login', 'meta' => ['ip' => '10.0.0.1']],
    ['action' => 'post', 'meta' => ['title' => 'Hello']],
]]);
$alice->save();                                 // users -> SQL, activities -> Mongo (user_id проставлен)
(new User(['name' => 'Bob']))->save();
(new Activity(['user_id' => 2, 'action' => 'login']))->save();

echo "Logins with authors:\n";
foreach (Activity::instance()->with('user')->where(['action' => 'login'])->orderBy('id')->all() as $activity) {
    echo "  #{$activity->id} {$activity->user->name} {$activity->created_at}\n";
}

echo "Users with activities:\n";
foreach (User::instance()->with('activities:o(id)')->orderBy('id')->all() as $user) {
    echo "  {$user->name}: ", implode(', ', $user->activities?->column('action') ?? []), "\n";
}

echo "Count (Alice OR post): ",
    Activity::instance()->where([Criteria::OR => [['user_id' => 1], ['action' => 'post']]])->count(), "\n";

echo json_encode(User::instance()->with('activities(id, action, meta)')->id(1)->first()->toArray(), JSON_PRETTY_PRINT), "\n";
