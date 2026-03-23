<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('narrow_alleys_temp', function (Blueprint $table) {
            $table->id();

            // 原始資料欄位
            $table->integer('seq_number')->nullable()->comment('序號');
            $table->string('code', 50)->nullable()->comment('編號');
            $table->string('category', 10)->nullable()->comment('分類（紅區/黃區）');
            $table->string('district', 50)->nullable()->comment('行政區');
            $table->string('fire_station_division', 100)->nullable()->comment('分隊名稱');
            $table->string('alley_name', 200)->nullable()->comment('巷道名稱');
            $table->decimal('width_m', 5, 2)->nullable()->comment('寬度（公尺）');

            // Geocoding 階段欄位
            $table->decimal('latitude', 10, 7)->nullable()->comment('Geocoding 緯度');
            $table->decimal('longitude', 10, 7)->nullable()->comment('Geocoding 經度');
            $table->string('geocode_status', 20)->default('pending')->comment('Geocoding 狀態：pending/success/failed');
            $table->text('geocode_error')->nullable()->comment('Geocoding 錯誤訊息');

            // Snapping 階段欄位
            $table->decimal('snapped_lat', 10, 7)->nullable()->comment('Snapping 後緯度');
            $table->decimal('snapped_lng', 10, 7)->nullable()->comment('Snapping 後經度');
            $table->integer('matched_road_id')->nullable()->comment('匹配到的 roads_planned ID');
            $table->decimal('snap_distance_m', 10, 2)->nullable()->comment('偏移距離（公尺）');

            $table->timestamps();

            $table->index('geocode_status');
            $table->index('district');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('narrow_alleys_temp');
    }
};