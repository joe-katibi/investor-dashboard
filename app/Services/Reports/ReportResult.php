<?php

namespace App\Services\Reports;

use Closure;

/**
 * A tabular report. Rows are produced lazily so exports of large periods stream through
 * memory instead of loading every outage at once.
 */
final class ReportResult
{
    /**
     * @param  array<string, string>  $columns  key => heading
     * @param  Closure(?int): iterable<array<string, scalar|null>>  $rows
     * @param  array<string, scalar|null>  $summary  headline figures shown above the table
     */
    public function __construct(
        public readonly string $title,
        public readonly array $columns,
        private readonly Closure $rows,
        public readonly array $summary = [],
        public readonly ?string $description = null,
    ) {}

    /** @return iterable<array<string, scalar|null>> */
    public function rows(?int $limit = null): iterable
    {
        return ($this->rows)($limit);
    }

    /** @return list<array<string, scalar|null>> */
    public function rowsArray(?int $limit = null): array
    {
        $out = [];
        foreach ($this->rows($limit) as $row) {
            $out[] = $row;
        }

        return $out;
    }
}
