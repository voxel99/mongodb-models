<?php

declare(strict_types=1);

namespace Jam\Models\MongoDB\Tests\Integration;

use Jam\Models\Model;
use Jam\Models\MongoDB\Tests\Fixtures\Mongo\Category;
use Jam\Models\MongoDB\Tests\Fixtures\Mongo\Comment;
use Jam\Models\MongoDB\Tests\Fixtures\Mongo\Product;
use Jam\Models\MongoDB\Tests\Fixtures\Mongo\ProductCategory;
use Jam\Models\MongoDB\Tests\Fixtures\Mongo\Session;
use Jam\Models\MongoDB\Tests\Fixtures\Sql\Article;
use Jam\Models\MongoDB\Tests\Fixtures\Sql\User;
use Jam\Models\MongoDB\Tests\Support\MongoTestCase;

/**
 * Связи: SQL -> Mongo, Mongo -> SQL, Mongo -> Mongo (в т.ч. many-to-many).
 */
final class RelationsTest extends MongoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new User(['email' => 'alice@x.io', 'name' => 'Alice']))->save();
        (new User(['email' => 'bob@x.io', 'name' => 'Bob']))->save();
        (new Article(['user_id' => 1, 'title' => 'SQL article']))->save();
        (new Article(['user_id' => 2, 'title' => 'Another one']))->save();
        foreach ([[1, 2, 'Nice', 5], [1, 1, 'Thanks', 4], [2, 1, 'Meh', 2]] as [$article, $user, $body, $rating]) {
            (new Comment(['article_id' => $article, 'user_id' => $user, 'body' => $body, 'rating' => $rating]))->save();
        }
        $this->resetLogs();
    }

    public function testSqlModelHasManyMongoModels(): void
    {
        $articles = Article::instance()->with('comments:o(rating DESC), author')->orderBy('id')->all();

        $this->assertSame(['SELECT * FROM articles ORDER BY `id`', 'SELECT * FROM users WHERE `id` IN (1, 2)'], $this->sqlLog);
        $this->assertSame(['find comments'], $this->mongoLog, 'one Mongo query for all articles');
        $this->assertSame(['Nice', 'Thanks'], $articles[0]->comments->column('body'));
        $this->assertSame(['Meh'], $articles[1]->comments->column('body'));
        $this->assertSame('Bob', $articles[1]->author->name);
    }

    public function testMongoModelBelongsToSqlModels(): void
    {
        $comments = Comment::instance()->with('author(id, name), article(id, title)')->orderBy('id')->all();

        $this->assertSame(['find comments'], $this->mongoLog);
        $this->assertCount(2, $this->sqlLog);
        $this->assertSame(['Bob', 'Alice', 'Alice'], array_map(fn($c) => $c->author->name, iterator_to_array($comments)));
        $this->assertSame('Another one', $comments[2]->article->title);
    }

    public function testNestedRelationsAcrossStorages(): void
    {
        // SQL users -> Mongo comments -> SQL articles
        $user = User::instance()->with('comments:o(id).article')->id(1)->first();
        $this->assertSame(['SQL article', 'Another one'], array_map(fn($c) => $c->article->title, iterator_to_array($user->comments)));
    }

    public function testArrayResultForApi(): void
    {
        $arr = Article::instance()->with('comments(id, body):o(id)')->id(1)->first()->toArray();
        $this->assertSame([['id' => 1, 'article_id' => 1, 'user_id' => 2, 'body' => 'Nice'], ['id' => 2, 'article_id' => 1, 'user_id' => 1, 'body' => 'Thanks']], $arr['comments']);
    }

    public function testSaveGraphFromSqlModelIntoMongo(): void
    {
        $article = new Article(['user_id' => 1, 'title' => 'New', 'comments' => [['body' => 'first!', 'user_id' => 2]]]);
        $article->save();

        $this->assertSame(3, $article->id);
        $this->assertSame([3], Comment::instance()->where(['body' => 'first!'])->column('article_id'));

        // Отвязанные Mongo-документы удаляются так же, как SQL-строки
        $article = Article::instance()->with('comments')->id(1)->first();
        $article->comments = [$article->comments[0]];
        $article->save([Model::SAVE_USE_CHECK_OLD => true]);
        $this->assertSame(['Nice'], Comment::instance()->where(['article_id' => 1])->column('body'));
    }

    public function testMongoBelongsSavesSqlParentFirst(): void
    {
        $comment = new Comment(['body' => 'with new author', 'author' => ['email' => 'carol@x.io', 'name' => 'Carol']]);
        $comment->save();

        $this->assertSame(3, $comment->user_id);
        $this->assertSame('Carol', Comment::instance()->with('author')->where(['body' => 'with new author'])->first()->author->name);
    }

    public function testManyToManyInsideMongo(): void
    {
        $product = new Product(['name' => 'Laptop', 'price' => '999.90', 'categories' => [['title' => 'Computers'], ['title' => 'Sale']]]);
        $product->save();
        (new Product(['name' => 'Mouse', 'categories' => [['id' => 1, 'title' => 'Computers']]]))->save();
        $this->resetLogs();

        $products = Product::instance()->with('categories:o(title)')->orderBy('id')->all();

        $this->assertSame(['find products', 'find product_category', 'find categories'], $this->mongoLog);
        $this->assertSame(['Computers', 'Sale'], $products[0]->categories->column('title'));
        $this->assertSame(['Computers'], $products[1]->categories->column('title'));
        $this->assertSame(999.9, $products[0]->price);
        $this->assertSame(2, Category::instance()->count());
        $this->assertSame(3, ProductCategory::instance()->count());
    }

    public function testObjectIdModelsInRelations(): void
    {
        (new Session(['user_id' => 1, 'ip' => '10.0.0.1']))->save();
        (new Session(['user_id' => 1, 'ip' => '10.0.0.2']))->save();

        $user = User::instance()->with('sessions:o(ip)')->id(1)->first();
        $this->assertSame(['10.0.0.1', '10.0.0.2'], $user->sessions->column('ip'));

        $session = Session::instance()->with('user')->where(['ip' => '10.0.0.2'])->first();
        $this->assertSame('Alice', $session->user->name);
    }
}
