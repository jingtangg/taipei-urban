<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * 消防栓位置資料表
     * 資料來源：大臺北地區消防栓分布點位圖 CSV（台北自來水事業處）
     * 座標系統：EPSG:3826 TWD97（原始 CSV 有雙座標，統一取 97X/97Y）
     * 用途：
     *   1. 功能2.1 消防栓點位地圖圖層
     *   2. 功能2.1 各行政區消防栓密度計算
     *   3. 功能2.1 理論平均服務半徑計算
     * 注意：
     *   - 原始資料含新北市，import 時已過濾只保留臺北市
     *   - district 從「臺北市萬華區...」截取，統一格式為「萬華區」
     */
    public function up(): void
    {
        Schema::create('fire_hydrants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('wpid', 30)->unique()->comment('消防栓官方編號，唯一識別碼，對應原始 WPID 欄位');
            $table->string('type', 20)->comment('消防栓型式：地下式消防栓 / 地上式消防栓，地圖圖示區分用');
            $table->string('district', 20)->comment('行政區名稱，如「萬華區」，對應 districts.district_name，密度計算用');
            $table->timestamps();
        });
        DB::statement("SELECT AddGeometryColumn('fire_hydrants', 'geom', 3826, 'POINT', 2)");
        DB::statement('CREATE INDEX fire_hydrants_geom_idx ON fire_hydrants USING GIST (geom)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fire_hydrants');
    }
};
