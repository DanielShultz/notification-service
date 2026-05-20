<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("notifications", static function (Blueprint $table): void {
            $table->uuid("id")->primary();
            $table->uuid("batch_id");
            $table->string("recipient_id");
            $table->string("channel", 16);
            $table->string("traffic_type", 32);
            $table->string("status", 32);
            $table->unsignedSmallInteger("attempts")->default(0);
            $table->timestampTz("sent_at")->nullable();
            $table->timestampTz("delivered_at")->nullable();
            $table->timestampTz("dropped_at")->nullable();
            $table->text("last_error")->nullable();
            $table->timestamps();

            $table->foreign("batch_id")->references("id")->on("notification_batches")->cascadeOnDelete();
            $table->unique(["batch_id", "recipient_id"]);
            $table->index(["recipient_id", "created_at"]);
            $table->index(["batch_id"]);
            $table->index(["status"]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("notifications");
    }
};

