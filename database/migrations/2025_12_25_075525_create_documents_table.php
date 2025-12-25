<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bundle_id');
            $table->unsignedBigInteger('parent_id')->nullable(); // folder nesting
            $table->string('name');
            $table->enum('type', ['file', 'folder']);
            $table->string('mime_type')->nullable(); // application/pdf
            $table->string('storage_path')->nullable(); // files only
            $table->integer('order')->default(0); // drag & drop ordering
            $table->json('metadata')->nullable(); // page count, size, etc
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('bundle_id')->references('id')->on('bundles')->cascadeOnDelete();
            $table->foreign('parent_id')->references('id')->on('documents')->cascadeOnDelete();

            $table->index(['bundle_id', 'parent_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
