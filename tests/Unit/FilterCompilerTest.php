<?php

declare(strict_types=1);

namespace Jam\Models\MongoDB\Tests\Unit;

use Jam\Models\MongoDB\FilterCompiler;
use Jam\Models\Storage\Criteria;
use MongoDB\BSON\Regex;
use PHPUnit\Framework\TestCase;

final class FilterCompilerTest extends TestCase
{
    private function compile(array $conditions): array
    {
        $compiler = new FilterCompiler(
            static fn(string $f) => $f === 'id' ? '_id' : (str_contains($f, '.') ? substr($f, strrpos($f, '.') + 1) : $f),
            // «тип известен» только для поля qty: целое; остальное — как в MongoStorage без типа
            static fn(string $f, $v) => $f === 'qty'
                ? [(int) $v]
                : (is_string($v) && is_numeric($v) ? [$v, $v + 0] : (is_int($v) ? [$v, (string) $v] : [$v])),
            static fn(string $f, $v) => is_numeric($v) ? $v + 0 : $v,
        );
        return $compiler->compile($conditions);
    }

    public function testEqualityAndTypes(): void
    {
        $this->assertSame(['qty' => 5], $this->compile(['qty' => '5']));
        $this->assertSame(['name' => 'Ann'], $this->compile(['name' => 'Ann']));
        $this->assertSame(['code' => ['$in' => ['7', 7]]], $this->compile(['code' => '7']), 'SQL-like loose equality');
        $this->assertSame(['_id' => ['$in' => [3, '3']]], $this->compile(['id' => 3]));
    }

    public function testOperators(): void
    {
        $this->assertSame(['deleted_at' => ['$eq' => null]], $this->compile(['deleted_at' => null]));
        $this->assertSame(['deleted_at' => ['$ne' => null]], $this->compile(['deleted_at' => ['!=' => null]]));
        $this->assertSame(['qty' => ['$in' => [1, 2]]], $this->compile(['qty' => ['1', 2]]));
        $this->assertSame(['qty' => ['$nin' => [1, null]]], $this->compile(['qty' => ['!=' => 1]]));
        $this->assertSame(['qty' => ['$in' => []]], $this->compile(['qty' => []]), 'empty IN matches nothing');
        $this->assertSame([], $this->compile(['qty' => ['not in' => []]]), 'empty NOT IN matches everything');
        $this->assertSame(['$and' => [['qty' => ['$gte' => 10]], ['qty' => ['$lt' => 100]]]], $this->compile(['qty' => ['>=' => '10', '<' => 100]]));
        $this->assertSame(['views' => ['$gt' => 5], 'name' => 'x'], $this->compile(['a.views' => ['>' => '5'], 'name' => 'x']));
    }

    public function testLike(): void
    {
        $filter = $this->compile(['title' => ['like' => 'PHP_8%']]);
        $this->assertInstanceOf(Regex::class, $filter['title']);
        $this->assertSame('^PHP.8.*$', $filter['title']->getPattern());
        $this->assertSame('i', $filter['title']->getFlags());

        $this->assertSame('^a\.b$', FilterCompiler::likeRegex('a.b')->getPattern(), 'regex specials are escaped');
        $this->assertInstanceOf(Regex::class, $this->compile(['title' => ['not like' => 'x%']])['title']['$not']);
    }

    public function testOrAndGroups(): void
    {
        $this->assertSame(
            ['qty' => 1, '$or' => [['name' => 'a'], ['name' => 'b', 'active' => true]]],
            $this->compile(['qty' => 1, Criteria::OR => [['name' => 'a'], ['name' => 'b', 'active' => true]]])
        );
        $this->assertSame(['_id' => ['$in' => []]], $this->compile([Criteria::OR => []]));
    }

    public function testCompileAllJoinsWithAnd(): void
    {
        $compiler = new FilterCompiler(fn($f) => $f, fn($f, $v) => [$v], fn($f, $v) => $v);
        $this->assertSame(
            ['$and' => [['a' => 1], ['b' => 2]]],
            $compiler->compileAll([new Criteria(['a' => 1]), new Criteria(['b' => 2])])
        );
        $this->assertSame([], $compiler->compileAll([]));
    }
}
