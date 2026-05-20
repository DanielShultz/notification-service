<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("notification_status_history", static function (Blueprint $table): void {
            $table->id();
            $table->uuid("notification_id");
            $table->string("status", 32);
            $table->text("reason")->nullable();
            $table->timestampTz("created_at");

            $table->foreign("notification_id")->references("id")->on("notifications")->cascadeOnDelete();
            $table->index(["notification_id", "created_at"]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("notification_status_history");
    }
};

