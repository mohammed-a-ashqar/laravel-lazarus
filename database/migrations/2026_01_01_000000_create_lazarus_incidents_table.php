<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lazarus_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('exception_class');
            $table->text('message');
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->string('status', 20)->default('captured')->index();
            $table->text('failure_reason')->nullable();
            $table->string('pr_url')->nullable();
            $table->string('report_path')->nullable();
            $table->unsignedInteger('tokens_used')->default(0);
            $table->decimal('cost', 10, 4)->default(0);
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lazarus_incidents');
    }
};
