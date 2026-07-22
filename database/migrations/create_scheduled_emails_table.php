<?php

declare(strict_types=1);

use FinityLabs\FinMail\Models\EmailTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('fin-mail.table_names.scheduled') ?? 'scheduled_emails', function (Blueprint $table) {
            $table->id();

            // Template reference
            $table->foreignIdFor(EmailTemplate::class)
                ->nullable()
                ->constrained(config('fin-mail.table_names.templates') ?? 'email_templates')
                ->nullOnDelete();

            // Sender & display recipients (the full recipient list also lives in payload)
            $table->string('from_address', 255);
            $table->json('to');
            $table->string('subject', 255);

            // Full compose state, replayed verbatim through EmailSender when the
            // schedule fires (locale, body, cc/bcc, template_key, attachments, ...).
            $table->json('payload');

            // 'individual' | 'combined' | null — how a multi-recipient batch is split at send time
            $table->string('send_mode', 20)->nullable();

            // When it should be delivered
            $table->dateTime('scheduled_at')->index();

            // Status tracking
            $table->unsignedTinyInteger('status')->default(1)->index();
            $table->dateTime('sent_at')->nullable();
            $table->json('metadata')->nullable();

            // Who scheduled it
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('fin-mail.table_names.scheduled') ?? 'scheduled_emails');
    }
};
