<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\HualienNarrowAlleyRequest;
use App\Repositories\HualienNarrowAlleyRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Exception;

class HualienNarrowAlleyController extends BaseController
{
    protected bool $debug;

    public function __construct(private HualienNarrowAlleyRepository $repository)
    {
        $this->debug = App::hasDebugModeEnabled();
    }

    /**
     * 回傳花蓮縣窄巷點位（含 GeoJSON 幾何）
     * 用於地圖圖層顯示
     * 可透過 ?township=花蓮市 篩選特定鄉鎮市區
     *
     * 與台北 narrow-alleys 差異：
     *   - 無 category（花蓮清冊未分紅區/黃區）
     *   - 無 road_width（單源資料，不做雙源比對）
     *   - width_m 以 width_m_min/width_m_max 表示（清冊含範圍值）
     *   - risk_level 已由 pipeline 以 width_m_min 預計算寫入 DB
     */
    public function index(HualienNarrowAlleyRequest $request): JsonResponse
    {
        try {
            $alleys = $this->repository->getFiltered(
                $request->validated()['township'] ?? null
            );

            $typed = $alleys->map(fn($a) => [
                'id'              => (string) $a->id,
                'alley_name'      => (string) $a->alley_name,
                'township'        => (string) $a->township,
                'fire_station'    => $a->fire_station ? (string) $a->fire_station : null,
                'width_m_min'     => (float) $a->width_m_min,
                'width_m_max'     => (float) $a->width_m_max,
                'risk_level'      => (string) $a->risk_level,
                'snap_distance_m' => $a->snap_distance_m !== null ? (float) $a->snap_distance_m : null,
                'geometry'        => json_decode($a->geometry),
            ]);

            return $this->sendResponse(
                $this->toFeatureCollection($typed, 'geometry'),
                '獲取花蓮窄巷資料成功!'
            );

        } catch (Exception $e) {
            report($e);
            return $this->debug
                ? $this->sendError($e->getMessage(), ['error' => $e->getMessage()])
                : $this->sendError('獲取花蓮窄巷資料錯誤,錯誤代碼「HNA011」,請通知管理員!!', ['error' => '獲取花蓮窄巷資料錯誤,錯誤代碼「HNA011」,請通知管理員!!']);
        }
    }
}
