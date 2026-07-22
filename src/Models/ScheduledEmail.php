<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Models;

use FinityLabs\FinMail\Enums\ScheduledEmailStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $email_template_id
 * @property string $from_address
 * @property array<int, string> $to
 * @property string $subject
 * @property array<string, mixed> $payload
 * @property 'individual'|'combined'|null $send_mode
 * @property Carbon $scheduled_at
 * @property ScheduledEmailStatus $status
 * @property Carbon|null $sent_at
 * @property array<string, mixed>|null $metadata
 * @property int|null $sent_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EmailTemplate|null $template
 */
class ScheduledEmail extends Model
{
    protected $fillable = [
        'email_template_id',
        'from_address',
        'to',
        'subject',
        'payload',
        'send_mode',
        'scheduled_at',
        'status',
        'sent_at',
        'metadata',
        'sent_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'to' => 'array',
            'payload' => 'array',
            'scheduled_at' => 'datetime',
            'status' => ScheduledEmailStatus::class,
            'sent_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The user who scheduled this email.
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'sent_by');
    }

    /**
     * The template used for this email (if any).
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Pending schedules whose time has arrived.
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query
            ->where('status', ScheduledEmailStatus::Pending)
            ->where('scheduled_at', '<=', now());
    }

    public function scopeStatus(Builder $query, ScheduledEmailStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get a comma-separated list of "To" recipients.
     */
    public function getRecipientsDisplayAttribute(): string
    {
        return implode(', ', $this->to ?? []);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isCancellable(): bool
    {
        return $this->status === ScheduledEmailStatus::Pending;
    }

    public function markAsSent(): void
    {
        $this->update([
            'status' => ScheduledEmailStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    public function markAsCancelled(): void
    {
        $this->update(['status' => ScheduledEmailStatus::Cancelled]);
    }

    public function markAsFailed(?string $error = null): void
    {
        $this->update([
            'status' => ScheduledEmailStatus::Failed,
            'metadata' => array_merge($this->metadata ?? [], [
                'error' => $error,
                'failed_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    public function getTable(): string
    {
        return config('fin-mail.table_names.scheduled', 'scheduled_emails');
    }
}
