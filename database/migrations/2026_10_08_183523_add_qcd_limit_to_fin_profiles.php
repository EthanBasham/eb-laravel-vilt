<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The most a year's qualified charitable distributions may come to, as the
 * profile sets it. Null is the built-in figure (config `finance.qcd.limit`),
 * like the standard deduction beside it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fin_profiles', function (Blueprint $table) {
            $table->decimal('qcd_limit', 15, 2)->nullable()->after('standard_deduction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fin_profiles', function (Blueprint $table) {
            $table->dropColumn('qcd_limit');
        });
    }
};
