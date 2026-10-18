<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlist_items', function (Blueprint $table) {
            $table->string('current_image')->nullable();
            $table->string('render_fingerprint', 64)->nullable();
            $table->timestamp('rendered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('playlist_items', function (Blueprint $table) {
            $table->dropColumn(['current_image', 'render_fingerprint', 'rendered_at']);
        });
    }
};
