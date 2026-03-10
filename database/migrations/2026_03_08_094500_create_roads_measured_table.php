<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * 實測道路資料表
     * 資料來源：臺北市寬度超過8公尺道路 Road.shp（工務局）
     * 座標系統：EPSG:3826 TWD97
     * 用途：功能1.1 
     *   1. roads_planned 疊加比對實際值(功能1.1) vs 計畫值(功能1.2)
     *   2. 區域道路統計:平均寬度、總長度
     * 注意：
     *   - 只含 >8m 道路，非全台北市完整資料
     *   - ROADWIDTH = 0 的路口交叉幾何已於 import 時過濾
     */
    public function up(): void
    {
        Schema::create('roads_measured', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('road_name', 100)->nullable()->comment('道路名稱，popup 顯示用，原始資料偶有 null');
            $table->string('district', 20)->comment('行政區名稱，如「萬華區」，對應 districts.district_name');
            $table->decimal('measured_width', 8, 4)->comment('實測路寬(公尺)，import 時已過濾 ROADWIDTH=0 的路口資料');
            $table->decimal('avg_width', 8, 4)->nullable()->comment('路段平均寬度(公尺)，統計面板用');
            $table->decimal('road_length', 10, 4)->nullable()->comment('路段長度(公尺)，區域統計總長度計算用');
            $table->timestamps();
        });
        DB::statement("SELECT AddGeometryColumn('roads_measured', 'geom', 3826, 'POLYGON', 2)");
        DB::statement('CREATE INDEX roads_measured_geom_idx ON roads_measured USING GIST (geom)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roads_measured');
    }
};
