<?php

namespace App\Domain\Import;

/**
 * Where a statement has a running balance, every row must satisfy: previous balance + amount = this balance (in the
 * statement's own currency).
 * Files can be oldest-first or newest-first, so both orders are tried.
 */
final class BalanceCheck
{
    /** @param list<NormalizedRow> $rows @return array{status:string, notes:?string} */
    public static function run(array $rows): array
    {
        $withBalance = array_values(array_filter($rows, fn (NormalizedRow $r) => $r->statementBalance() !== null));
        if (count($withBalance) < 2 || count($withBalance) !== count($rows)) {
            return ['status' => 'not_available', 'notes' => count($withBalance) ? 'Some rows have no balance.' : null];
        }

        foreach ([$withBalance, array_reverse($withBalance)] as $ordered) {
            $mismatch = self::firstMismatch($ordered);
            if ($mismatch === null) {
                return ['status' => 'passed', 'notes' => null];
            }
            $mismatches[] = $mismatch;
        }

        return ['status' => 'failed', 'notes' => "The running balance doesn't add up at line {$mismatches[0]}. A row may be missing or changed."];
    }

    private static function firstMismatch(array $ordered): ?int
    {
        for ($i = 1; $i < count($ordered); $i++) {
            if ($ordered[$i - 1]->statementBalance() + $ordered[$i]->statementAmount() !== $ordered[$i]->statementBalance()) {
                return $ordered[$i]->line;
            }
        }

        return null;
    }
}
