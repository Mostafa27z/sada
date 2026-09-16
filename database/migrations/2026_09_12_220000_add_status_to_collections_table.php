<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->string('status', 30)->default('pending')->after('color');
        });

        // Set existing collections with articles to completed
        DB::table('collections')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('collection_articles')
                    ->whereColumn('collection_articles.collection_id', 'collections.id');
            })
            ->update(['status' => 'completed']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
