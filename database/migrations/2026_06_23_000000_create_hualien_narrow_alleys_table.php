<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 花蓮縣窄巷資料表
 *
 * 與台北 narrow_alleys_temp 的差異：
 *   - 無 category（花蓮清冊未分紅區/黃區）
 *   - 無 road_width（單源資料，無雙源比對）
 *   - 無 snap_distance_m（OSM snap 距離，由 pipeline 填入）
 *   - width_m 拆分為 width_m_min / width_m_max（清冊含範圍值）
 *   - 增加 township（鄉鎮市區）與 fire_station（管轄消防局）
 *   - risk_level 由後端 BaseController::calculateRiskLevel() 以 width_m_min 計算
 */
return new class extends Migration
{
    public function up(): void
    {
        // 啟用 PostGIS（若尚未啟用）
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        Schema::create('hualien_narrow_alleys', function (Blueprint $table) {
            $table->id();
            $table->string('alley_name');           // 巷道名稱
            $table->string('township', 30);         // 鄉鎮市區（如：花蓮市、吉安鄉）
            $table->string('fire_station', 60)->nullable(); // 管轄消防局
            $table->string('length_raw', 30)->nullable();   // 原始長度字串，保留供除錯
            $table->decimal('width_m_min', 5, 2);   // 路寬下限（公尺）
            $table->decimal('width_m_max', 5, 2);   // 路寬上限（公尺）
            $table->string('risk_level', 10);        // 極高風險 / 高風險 / 一般
            $table->decimal('snap_distance_m', 8, 2)->nullable(); // OSM snap 距離（公尺）
            $table->timestamps();
        });

        // geom 欄位需用原生 SQL 建立（Laravel Blueprint 不支援 PostGIS 型別）
        DB::statement(
            'ALTER TABLE hualien_narrow_alleys ADD COLUMN geom geometry(LineString, 3826)'
        );

        // 空間索引
        DB::statement(
            'CREATE INDEX hualien_narrow_alleys_geom_idx ON hualien_narrow_alleys USING GIST (geom)'
        );

        // 查詢常用索引
        DB::statement(
            'CREATE INDEX hualien_narrow_alleys_township_idx ON hualien_narrow_alleys (township)'
        );
        DB::statement(
            'CREATE INDEX hualien_narrow_alleys_risk_level_idx ON hualien_narrow_alleys (risk_level)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('hualien_narrow_alleys');
    }
};
