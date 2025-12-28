<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('highlights', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bundle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->integer('page_number');

            // Rectangle position
            $table->float('x');
            $table->float('y');
            $table->float('width');
            $table->float('height');

            $table->text('text');

            // Color data
            $table->string('color_name');
            $table->string('color_hex', 7);
            $table->json('color_rgb');
            $table->float('opacity');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('highlights');
    }
};
