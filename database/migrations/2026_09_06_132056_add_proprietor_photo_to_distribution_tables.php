<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('depots', function (Blueprint $table) {
            $table->string('proprietor_photo')
                ->nullable()
                ->after('proprietor_name');
        });

        Schema::table('depot_applications', function (Blueprint $table) {
            $table->string('proprietor_photo')
                ->nullable()
                ->after('proprietor_name');
        });

        Schema::table('dealers', function (Blueprint $table) {
            $table->string('proprietor_photo')
                ->nullable()
                ->after('proprietor_name');
        });

        Schema::table('dealer_applications', function (Blueprint $table) {
            $table->string('proprietor_photo')
                ->nullable()
                ->after('proprietor_name');
        });

        Schema::table('sellers', function (Blueprint $table) {
            $table->string('proprietor_photo')
                ->nullable()
                ->after('proprietor_name');
        });
    }

    public function down(): void
    {
        Schema::table('depots', function (Blueprint $table) {
            $table->dropColumn('proprietor_photo');
        });

        Schema::table('depot_applications', function (Blueprint $table) {
            $table->dropColumn('proprietor_photo');
        });

        Schema::table('dealers', function (Blueprint $table) {
            $table->dropColumn('proprietor_photo');
        });

        Schema::table('dealer_applications', function (Blueprint $table) {
            $table->dropColumn('proprietor_photo');
        });

        Schema::table('sellers', function (Blueprint $table) {
            $table->dropColumn('proprietor_photo');
        });
    }
};
