<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * 消防隊分布資料表
     * 資料來源：臺北市政府消防局各單位通訊錄 CSV（消防局）
     * 座標系統：EPSG:3826 TWD97
     * 用途：
     *   1. 功能2.2 消防隊點位地圖圖層
     *   2. popup 顯示分隊名稱與地址
     * 注意：
     *   - 原始 CSV 欄位名稱為「經度」「緯度」但值為 TWD97 整數格式
     *   - 全部 46 筆皆為臺北市，不需過濾
     *   - county_code 欄位（63000）不具分析價值，import 時捨棄
     */
    public function up(): void
    {
        Schema::create('fire_stations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 50)->comment('消防分隊名稱，如「華山分隊」，popup 主要顯示資訊');
            $table->text('address')->nullable()->comment('分隊地址，popup 次要資訊');
            $table->timestamps();
        });
    DB::statement("SELECT AddGeometryColumn('fire_stations', 'geom', 3826, 'POINT', 2)");
    DB::statement('CREATE INDEX fire_stations_geom_idx ON fire_stations USING GIST (geom)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fire_stations');
    }
};
