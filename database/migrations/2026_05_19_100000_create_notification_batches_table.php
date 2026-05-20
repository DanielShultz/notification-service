<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("notification_batches", static function (Blueprint $table): void {
            $table->uuid("id")->primary();
            $table->string("idempotency_key")->unique();
            $table->string("channel", 16);
            $table->string("traffic_type", 32);
            $table->text("message");
            $table->unsignedInteger("recipient_count");
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("notification_batches");
    }
};

