<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('article_category', function (Blueprint $table) {
            $table->foreignId('article_id');
            $table->foreignId('category_id');
            $table->primary(['article_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::drop('article_category');
    }
};
