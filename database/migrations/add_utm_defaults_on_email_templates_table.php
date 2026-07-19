<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('fin-mail.table_names.templates') ?? 'email_templates', function (Blueprint $table) {
            $table->string('utm_source')->nullable()->after('token_schema');
            $table->string('utm_medium')->nullable()->after('utm_source');
            $table->string('utm_campaign')->nullable()->after('utm_medium');
        });
    }

    public function down(): void
    {
        Schema::table(config('fin-mail.table_names.templates') ?? 'email_templates', function (Blueprint $table) {
            $table->dropColumn(['utm_source', 'utm_medium', 'utm_campaign']);
        });
    }
};
