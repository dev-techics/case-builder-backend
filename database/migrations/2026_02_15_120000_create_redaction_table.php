<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('redactions', function (Blueprint $table) {
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

            // Style data
            $table->string('name');
            $table->string('fill_hex', 7);
            $table->float('opacity');
            $table->string('border_hex', 7);
            $table->float('border_width');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redactions');
    }
};
