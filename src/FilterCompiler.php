<?php

namespace Jam\Models\MongoDB;

use InvalidArgumentException;
use Jam\Models\Storage\Criteria;
use MongoDB\BSON\Regex;

/**
 * Перевод условий-массивов (Criteria) в фильтр MongoDB с семантикой, близкой к SQL:
 *
 *   ['user_id' => 5]                  -> ['user_id' => 5]
 *   ['status' => [1, 2]]              -> ['status' => ['$in' => [1, 2]]]
 *   ['deleted_at' => null]            -> ['deleted_at' => ['$eq' => null]] (null или поле отсутствует)
 *   ['views' => ['>=' => 10]]         -> ['views' => ['$gte' => 10]]
 *   ['title' => ['like' => 'PHP%']]   -> ['title' => Regex('^PHP.*$', 'i')]
 *   ['a' => ['!=' => 1]]              -> ['a' => ['$nin' => [1, null]]]   (как в SQL: NULL <> 1 — не истина)
 *   [Criteria::OR => [[...], [...]]]  -> ['$or' => [...]]
 *
 * Сравнение в SQL нестрогое ('5' = 5), а в Mongo — строгое. Поэтому значения приводятся
 * через $normalizeValue (модель знает типы полей), а для равенства/IN числовые строки
 * без известного типа сравниваются в обоих видах: ['$in' => ['5', 5]].
 */
final class FilterCompiler
{
    /**
     * @param callable(string): string $mapField              имя поля в документе (алиас, pk -> _id)
     * @param callable(string, mixed): array<int, mixed> $candidates варианты значения для равенства
     * @param callable(string, mixed): mixed $normalizeValue  значение для сравнений (>, <)
     */
    public function __construct(
        private $mapField,
        private $candidates,
        private $normalizeValue,
    ) {
    }

    /**
     * @param array<int, Criteria> $conditions Условия, соединяемые через AND
     * @return array<string, mixed>
     */
    public function compileAll(array $conditions): array
    {
        $filters = array_values(array_filter(array_map(fn(Criteria $c) => $this->compile($c->toArray()), $conditions)));
        return match (count($filters)) {
            0 => [],
            1 => $filters[0],
            default => ['$and' => $filters],
        };
    }

    /**
     * @param array<string, mixed> $conditions
     * @return array<string, mixed>
     */
    public function compile(array $conditions): array
    {
        $clauses = [];
        foreach ($conditions as $field => $value) {
            if ($field === Criteria::OR || $field === Criteria::AND) {
                $groups = array_map(fn(array $group) => $this->compile($group) ?: (object) [], $value);
                if (!$groups) {
                    // Пустое OR — ложь (как в SQL-компиляции), пустое AND — истина
                    if ($field === Criteria::OR) {
                        $clauses[] = ['_id' => ['$in' => []]];
                    }
                    continue;
                }
                $clauses[] = [$field => $groups];
                continue;
            }
            $column = ($this->mapField)($field);
            foreach ($this->pairs($value) as [$op, $operand]) {
                $clause = $this->comparison($field, $op, $operand);
                if ($clause !== null) {
                    $clauses[] = [$column => $clause];
                }
            }
        }
        return match (count($clauses)) {
            0 => [],
            1 => $clauses[0],
            default => $this->mergeClauses($clauses),
        };
    }

    /**
     * Условия по разным полям — в один документ, повторяющиеся поля — через $and.
     *
     * @param array<int, array<string, mixed>> $clauses
     * @return array<string, mixed>
     */
    private function mergeClauses(array $clauses): array
    {
        $keys = array_map(fn(array $clause) => array_key_first($clause), $clauses);
        if (count($keys) === count(array_unique($keys))) {
            return array_merge(...$clauses);
        }
        return ['$and' => $clauses];
    }

    /** @return array<int, array{0: string, 1: mixed}> */
    private function pairs(mixed $value): array
    {
        if (is_array($value) && !array_is_list($value)) {
            $pairs = [];
            foreach ($value as $op => $operand) {
                $pairs[] = [strtolower((string) $op), $operand];
            }
            return $pairs;
        }
        if (is_array($value)) {
            return [['in', $value]];
        }
        return [['=', $value]];
    }

    private function comparison(string $field, string $op, mixed $operand): mixed
    {
        if ($operand === null) {
            return match ($op) {
                '=' => ['$eq' => null],   // null или поле отсутствует — как IS NULL
                '!=', '<>' => ['$ne' => null],
                default => throw new InvalidArgumentException("Operator [$op] does not accept NULL"),
            };
        }

        return match ($op) {
            '=' => $this->equality($field, [$operand]),
            'in' => ['$in' => $this->expand($field, (array) $operand)],
            '!=', '<>' => ['$nin' => [...$this->expand($field, [$operand]), null]],
            'not in' => $operand ? ['$nin' => [...$this->expand($field, (array) $operand), null]] : null,
            '>' => ['$gt' => ($this->normalizeValue)($field, $operand)],
            '>=' => ['$gte' => ($this->normalizeValue)($field, $operand)],
            '<' => ['$lt' => ($this->normalizeValue)($field, $operand)],
            '<=' => ['$lte' => ($this->normalizeValue)($field, $operand)],
            'like' => self::likeRegex((string) $operand),
            'not like' => ['$not' => self::likeRegex((string) $operand)],
            default => throw new InvalidArgumentException("Unsupported operator [$op]"),
        };
    }

    /** @param array<int, mixed> $values */
    private function equality(string $field, array $values): mixed
    {
        $candidates = $this->expand($field, $values);
        return count($candidates) === 1 ? $candidates[0] : ['$in' => $candidates];
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, mixed>
     */
    private function expand(string $field, array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            foreach (($this->candidates)($field, $value) as $candidate) {
                if (!in_array($candidate, $result, true)) {
                    $result[] = $candidate;
                }
            }
        }
        return $result;
    }

    /** SQL LIKE (% и _) -> регулярное выражение, без учёта регистра (как collation MySQL по умолчанию) */
    public static function likeRegex(string $pattern): Regex
    {
        $regex = '';
        foreach (preg_split('//u', $pattern, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $regex .= match ($char) {
                '%' => '.*',
                '_' => '.',
                default => preg_quote($char, '/'),
            };
        }
        return new Regex('^' . $regex . '$', 'i');
    }
}
