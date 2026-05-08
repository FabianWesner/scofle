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
        Schema::create('attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversion_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('n');
            $table->string('status', 16);
            $table->string('display_filename')->nullable();
            $table->string('input_mime', 32)->nullable();
            $table->string('input_ext', 8)->nullable();
            $table->unsignedBigInteger('input_bytes')->nullable();
            $table->unsignedBigInteger('input_pixels')->nullable();
            $table->unsignedBigInteger('pptx_bytes')->nullable();
            $table->unsignedBigInteger('pdf_bytes')->nullable();
            $table->string('pptx_sha256', 64)->nullable();
            $table->string('failure_code', 32)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['conversion_id', 'n']);
            $table->index(['status', 'heartbeat_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attempts');
    }
};
