<?php

namespace App\Nodes;

/**
 * Evaluasi kondisi (untuk node IF / Filter).
 */
trait ConditionEvaluator
{
    /**
     * Evaluasi semua kondisi terhadap satu item.
     *
     * @return array daftar bool hasil per kondisi
     */
    protected function evaluateConditions(array $conditions, array $item, WorkflowContext $context): array
    {
        $results = [];
        foreach ($conditions as $cond) {
            $left     = $context->resolve($cond['left'] ?? '', $item);
            $right    = $context->resolve($cond['right'] ?? '', $item);
            $results[] = $this->compare($left, $cond['operator'] ?? '==', $right);
        }

        return $results;
    }

    protected function compare($left, string $operator, $right): bool
    {
        switch ($operator) {
            case '==':
            case '=':
                return $left == $right;
            case '!=':
                return $left != $right;
            case '===':
                return $left === $right;
            case '!==':
                return $left !== $right;
            case '>':
                return $left > $right;
            case '>=':
                return $left >= $right;
            case '<':
                return $left < $right;
            case '<=':
                return $left <= $right;
            case 'contains':
                return is_string($left) && strpos($left, (string) $right) !== false;
            case 'not contains':
                return is_string($left) && strpos($left, (string) $right) === false;
            case 'startsWith':
                return is_string($left) && strpos($left, (string) $right) === 0;
            case 'endsWith':
                return is_string($left) && substr($left, -strlen((string) $right)) === (string) $right;
            case 'empty':
                return empty($left);
            case 'not empty':
                return ! empty($left);
            case 'regex':
                return (bool) preg_match((string) $right, (string) $left);
            default:
                return false;
        }
    }
}
