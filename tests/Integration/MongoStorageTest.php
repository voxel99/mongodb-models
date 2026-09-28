<?php

declare(strict_types=1);

namespace Jam\Models\MongoDB\Tests\Integration;

use Jam\Models\Model;
use Jam\Models\ModelException;
use Jam\Models\ModelList;
use Jam\Models\MongoDB\Tests\Fixtures\Mongo\Category;
use Jam\Models\MongoDB\Tests\Fixtures\Mongo\Comment;
use Jam\Models\MongoDB\Tests\Fixtures\Mongo\Event;
use Jam\Models\MongoDB\Tests\Fixtures\Mongo\Session;
use Jam\Models\MongoDB\Tests\Support\MongoTestCase;
use Jam\Models\Storage\Criteria;

final class MongoStorageTest extends MongoTestCase
{
    public function testInsertAssignsAutoIncrementIdsAndStoresNativeTypes(): void
    {
        $id1 = (new Event(['type' => 'login', 'payload' => ['source' => 'web', 'level' => 2], 'tags' => ['a', 'b'], 'score' => '1.5', 'is_public' => true]))->save();
        $id2 = (new Event(['type' => 'logout']))->save();

        $this->assertSame([1, 2], [$id1, $id2]);

        $doc = $this->mongo->selectCollection('events')->findOne(['_id' => 1]);
        $this->assertSame(['source' => 'web', 'level' => 2], $doc['payload'], 'json field is stored as a document');
        $this->assertSame(1.5, $doc['score']);
        $this->assertSame(1, $doc['is_public']);
        $this->assertSame('a,b', $doc['tags']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $doc['created_at']);
    }

    public function testReadCastsAndTopLevelJsonKeys(): void
    {
        (new Event(['type' => 'login', 'payload' => ['source' => 'web'], 'tags' => ['x'], 'is_public' => '1']))->save();

        $event = Event::instance()->id(1)->first();
        $this->assertSame(1, $event->id);
        $this->assertSame('web', $event->payload->source, 'same representation as a JSON column in SQL');
        $this->assertSame(['x'], $event->tags);
        $this->assertTrue($event->is_public);
        $this->assertSame('login:web', $event->toArray(':c')['summary']);
    }

    public function testQueryingWithArrayConditions(): void
    {
        foreach ([['a', 10, 5], ['b', 20, 1], ['c', 30, 5], ['d', 40, null]] as [$body, $rating, $user]) {
            (new Comment(['body' => $body, 'rating' => $rating, 'user_id' => $user]))->save();
        }

        $this->assertSame(['c', 'a'], Comment::instance()->where(['user_id' => 5])->orderBy('rating DESC')->column('body'));
        $this->assertSame(['b', 'c'], Comment::instance()->where(['rating' => ['>=' => '20', '<' => 40]])->orderBy('id')->column('body'));
        $this->assertSame(['d'], Comment::instance()->where(['user_id' => null])->column('body'));
        $this->assertSame(['b'], Comment::instance()->where(['user_id' => ['!=' => 5]])->column('body'), 'NULL is not "!= 5", like in SQL');
        $this->assertSame(['a', 'd'], Comment::instance()->where([Criteria::OR => [['rating' => 10], ['body' => ['like' => 'D%']]]])->orderBy('id')->column('body'));
        $this->assertSame(['b', 'c'], Comment::instance()->where(['id' => ['2', 3]])->column('body'), 'string ids from HTTP/SQL work');
        $this->assertSame([2 => 'b', 3 => 'c'], Comment::instance()->orderBy('id')->limit(2)->offset(1)->column('body', 'id'));
        $this->assertSame([], Comment::instance()->where(['id' => []])->column('body'));
    }

    public function testFieldProjectionAndFirst(): void
    {
        (new Comment(['body' => 'hello', 'rating' => 3, 'user_id' => 1]))->save();

        $comment = Comment::instance()->first('id, body');
        $this->assertSame(['id' => 1, 'body' => 'hello'], $comment->toArray());
        $this->assertTrue(Comment::instance()->id(99)->first()->isEmpty());
    }

    public function testAggregates(): void
    {
        foreach ([10, 20, 30, null] as $rating) {
            (new Comment(['body' => 'x', 'rating' => $rating]))->save();
        }
        $this->assertSame(4, Comment::instance()->count());
        $this->assertSame(3, Comment::instance()->count('rating'));
        $this->assertSame(60, Comment::instance()->sum('rating'));
        $this->assertEquals(20, Comment::instance()->avg('rating'));
        $this->assertSame(30, Comment::instance()->max('rating'));
        $this->assertSame(2, Comment::instance()->where(['rating' => ['>' => 10]])->count());
    }

    public function testUpdateIncrementDelete(): void
    {
        (new Comment(['body' => 'a', 'rating' => 1]))->save();
        (new Comment(['body' => 'b', 'rating' => 1]))->save();

        $comment = Comment::instance()->id(1)->first();
        $comment->body = 'a!';
        $comment->save();
        $comment->incByUpdate('rating', 5);

        $fresh = Comment::instance()->id(1)->first();
        $this->assertSame('a!', $fresh->body);
        $this->assertSame(6, $fresh->rating);
        $this->assertNotEmpty($fresh->updated_at);

        Comment::updateRaw(['rating' => 9], [1, 2]);
        $this->assertSame([9, 9], Comment::instance()->column('rating'));

        $fresh->delete();
        $this->assertSame(['b'], Comment::instance()->column('body'));
    }

    public function testNullValueUnsetsFieldValue(): void
    {
        (new Comment(['body' => 'a', 'user_id' => 3]))->save();
        $comment = Comment::instance()->first();
        $comment->user_id = Model::NULL_VALUE;
        $comment->update('user_id');

        $this->assertSame(1, Comment::instance()->where(['user_id' => null])->count());
    }

    public function testSoftDeletes(): void
    {
        (new Event(['type' => 'a']))->save();
        (new Event(['type' => 'b']))->save();

        Event::instance()->id(1)->first()->delete();
        $this->assertSame(['b'], Event::instance()->column('type'));
        $this->assertSame(['a', 'b'], Event::instance()->withTrashed()->orderBy('id')->column('type'));
        $this->assertSame(['a'], Event::instance()->where(['deleted_at' => ['!=' => null]])->column('type'));

        Event::instance()->withTrashed()->id(1)->first()->restore();
        $this->assertSame(2, Event::instance()->count());
    }

    public function testEventsAndCancelledInsert(): void
    {
        $event = new Event(['type' => 'x']);
        $event->on(Model::EVENT_CREATING, function (array $row) {
            $row['type'] = strtoupper($row['type']);
            return $row;
        });
        $event->save();
        $this->assertSame('X', Event::instance()->value('type'));

        $cancelled = new Event(['type' => 'nope']);
        $cancelled->on(Model::EVENT_CREATING, fn() => false);
        $this->assertSame(0, $cancelled->save());
        $this->assertSame(1, Event::instance()->count());
    }

    public function testBulkInsertAndInsertIgnore(): void
    {
        (new ModelList(Category::class, [['title' => 'A'], ['title' => 'B']]))->insert();
        $this->assertSame(['A', 'B'], Category::instance()->orderBy('id')->column('title'));

        // Дубликат пропущен; при явно заданном ключе save() возвращает этот ключ (как и в SQL)
        $this->assertSame(1, (new Category(['id' => 1, 'title' => 'dup']))->save([Model::SAVE_USE_INSERT_IGNORE => true]));
        $this->assertSame('A', Category::instance()->id(1)->value('title'));

        (new Category(['id' => 1, 'title' => 'upserted']))->save([Model::SAVE_USE_INSERT_DKU => true]);
        $this->assertSame('upserted', Category::instance()->id(1)->value('title'));
    }

    public function testObjectIdKeys(): void
    {
        $session = new Session(['user_id' => 1, 'ip' => '10.0.0.1']);
        $id = $session->save();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $id);
        $this->assertSame($id, $session->id);
        $this->assertSame('10.0.0.1', Session::instance()->id($id)->first()->ip);
        $this->assertInstanceOf(\MongoDB\BSON\ObjectId::class, $this->mongo->selectCollection('sessions')->findOne()['_id']);
    }

    public function testSqlFragmentsAreRejected(): void
    {
        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('supports only array conditions');
        Comment::instance()->where('rating > ?d', 1)->all();
    }
}
