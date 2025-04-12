<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('user_ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('month'); // Format: YYYY-MM
            $table->integer('tokens_used')->default(0);
            $table->decimal('estimated_cost', 10, 6)->default(0);
            $table->timestamps();
            
            $table->unique(['user_id', 'month']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_ai_usage');
    }
};
