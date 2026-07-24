<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('help_text')->nullable();    // plain-language guidance (impl delta)
            // paper Table 31 fields
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(true);
            $table->integer('file_size_max_mb')->default(10);
            $table->string('accepted_formats')->default('pdf,jpg,jpeg,png');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
