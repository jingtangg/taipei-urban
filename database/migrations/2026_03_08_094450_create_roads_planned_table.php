<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * 都市計畫道路資料表（計畫值）
     * 資料來源：臺北市道路寬度 roadsize2.shp（都市發展局）
     * 座標系統：EPSG:3826 TWD97
     * 用途：
     *   1. 主要：功能1.2 窄巷地圖圖層（紅/黃/綠顏色分級）
     *   2. 次要：功能1.1 與 roads_measured 疊加比對計畫值 vs 實際值
     * 注意：這是計畫值，非實測
     */
    public function up(): void
    {
        Schema::create('roads_planned', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('road_width', 10)->comment('原始計畫路寬字串，如「8M」，保留原始值供 popup 顯示');
            $table->decimal('width_m', 6, 2)->comment('從 road_width 解析出的數值，如 8.00，供分級查詢用');
            $table->string('width_category', 10)->comment('寬度分級：narrow(<3.5m) / mid(3.5-6m) / wide(>=6m)，地圖顏色分級依據');
            $table->timestamps();
        });
        DB::statement("SELECT AddGeometryColumn('roads_planned', 'geom', 3826, 'LINESTRING', 2)");
        DB::statement('CREATE INDEX roads_planned_geom_idx ON roads_planned USING GIST (geom)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roads_planned');
    }
};
