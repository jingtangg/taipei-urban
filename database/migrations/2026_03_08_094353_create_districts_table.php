<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 行政區界底圖資料表
     * 資料來源：臺北市區界圖 G97_A_CADIST_P.shp（民政局）
     * 座標系統：EPSG:3826 TWD97
     * 用途：對應 功能3 區域統計底圖
     *   1. 地圖底圖圖層（行政區邊界）
     *   2. 各功能密度計算的分母（area_m2）
     *   3. 全系統 district_name 的 JOIN 基準
     * 注意：district_name 統一格式為「北投區」（不含「臺北市」）
     */
    public function up(): void
    {
        Schema::create('districts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('district_name', 20)->unique()->comment('行政區名稱，如「北投區」，全系統 JOIN 基準，統一不含「臺北市」前綴');
            $table->decimal('area_m2', 16, 4)->comment('行政區面積(平方公尺)，密度公式分母，換算km²請除以1000000');
            $table->timestamps();
        });
    DB::statement("SELECT AddGeometryColumn('districts', 'geom', 3826, 'POLYGON', 2)");
    DB::statement('CREATE INDEX districts_geom_idx ON districts USING GIST (geom)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('districts');
    }
};
