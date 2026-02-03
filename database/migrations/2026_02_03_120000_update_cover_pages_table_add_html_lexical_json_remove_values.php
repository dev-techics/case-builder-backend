<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cover_pages', function (Blueprint $table) {
            $table->longText('html')->nullable();
            $table->json('lexical_json')->nullable();
            $table->dropColumn('values');
        });
    }

    public function down(): void
    {
        Schema::table('cover_pages', function (Blueprint $table) {
            $table->json('values')->nullable();
            $table->dropColumn(['html', 'lexical_json']);
        });
    }
};
