<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Context;

use Illuminate\Database\Events\QueryExecuted;

/**
 * Keeps the last few SQL statements of the current request, without their bindings.
 */
final class QueryRecorder
{
    /** @var list<string> */
    private array $queries = [];

    public function __construct(private readonly int $limit = 10) {}

    public function record(QueryExecuted $event): void
    {
        $this->queries[] = sprintf('%s (%.1f ms, %s)', $event->sql, $event->time, $event->connectionName);

        if (count($this->queries) > $this->limit) {
            array_shift($this->queries);
        }
    }

    /**
     * @return list<string>
     */
    public function recent(): array
    {
        return $this->queries;
    }

    public function flush(): void
    {
        $this->queries = [];
    }
}
