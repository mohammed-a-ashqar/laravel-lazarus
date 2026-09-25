<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Models;

use Alashqar\Lazarus\Context\IncidentContext;
use Alashqar\Lazarus\Enums\IncidentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $fingerprint
 * @property string $exception_class
 * @property string $message
 * @property string|null $file
 * @property int|null $line
 * @property int $occurrences
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property IncidentStatus $status
 * @property string|null $failure_reason
 * @property string|null $pr_url
 * @property string|null $report_path
 * @property int $tokens_used
 * @property string $cost
 * @property array<string, mixed>|null $context
 */
class Incident extends Model
{
    protected $table = 'lazarus_incidents';

    protected $guarded = ['id'];

    protected $attributes = [
        'occurrences' => 1,
        'status' => 'captured',
        'tokens_used' => 0,
        'cost' => 0,
    ];

    /**
     * Find an incident by its numeric id or by a unique fingerprint prefix.
     */
    public static function findByReference(string $reference): ?self
    {
        if (ctype_digit($reference)) {
            return self::query()->find((int) $reference);
        }

        /** @var Builder<self> $query */
        $query = self::query()->where('fingerprint', 'like', $reference.'%');

        return $query->count() === 1 ? $query->first() : null;
    }

    public function shortFingerprint(): string
    {
        return substr($this->fingerprint, 0, 10);
    }

    public function location(): string
    {
        return $this->file === null ? 'unknown location' : $this->file.':'.($this->line ?? '?');
    }

    public function shortClass(): string
    {
        $position = strrpos($this->exception_class, '\\');

        return $position === false ? $this->exception_class : substr($this->exception_class, $position + 1);
    }

    public function incidentContext(): IncidentContext
    {
        return IncidentContext::fromArray($this->context ?? []);
    }

    public function transitionTo(IncidentStatus $status, ?string $reason = null): void
    {
        $this->status = $status;
        $this->failure_reason = $reason;
        $this->save();
    }

    public function addUsage(int $tokens, float $cost): void
    {
        $this->tokens_used += $tokens;
        $this->cost = number_format((float) $this->cost + $cost, 4, '.', '');
    }

    protected function casts(): array
    {
        return [
            'status' => IncidentStatus::class,
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'occurrences' => 'integer',
            'line' => 'integer',
            'tokens_used' => 'integer',
            'cost' => 'decimal:4',
            'context' => 'array',
        ];
    }
}
