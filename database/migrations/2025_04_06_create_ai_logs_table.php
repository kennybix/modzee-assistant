<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ai_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->text('prompt');
            $table->text('response');
            $table->string('model')->nullable();
            $table->unsignedInteger('tokens_used')->nullable(); // Use unsigned integer
            $table->decimal('cost', 10, 6)->nullable();
            $table->string('persona')->default('general');
            $table->json('context')->nullable();
            $table->timestamps();
            
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('ai_logs');
    }
};
