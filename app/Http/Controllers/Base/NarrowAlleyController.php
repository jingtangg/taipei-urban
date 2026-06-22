<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\NarrowAlleyRequest;
use App\Repositories\NarrowAlleyRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Exception;

class NarrowAlleyController extends BaseController
{
    protected bool $debug;

    public function __construct(private NarrowAlleyRepository $repository)
    {
        $this->debug = App::hasDebugModeEnabled();
    }

    /**
     * 回傳窄巷資料（含 GeoJSON 幾何）
     * 用於地圖圖層顯示
     * 可透過 ?district=大安區 篩選特定行政區
     * 可透過 ?category=紅區 篩選紅區/黃區
     */
    public function index(NarrowAlleyRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $alleys    = $this->repository->getFiltered(
                $validated['district'] ?? null,
                $validated['category'] ?? null,
            );

            $typed = $alleys->map(fn($a) => [
                'id'              => (string) $a->id,
                'alley_name'      => (string) $a->alley_name,
                'district'        => (string) $a->district,
                'category'        => (string) $a->category,
                'width_m'         => (float) $a->width_m,
                'road_width'      => $a->road_width !== null ? (float) $a->road_width : null,
                'snap_distance_m' => $a->snap_distance_m !== null ? (float) $a->snap_distance_m : null,
                'geometry'        => json_decode($a->geometry),
            ]);

            return $this->sendResponse(
                $this->toFeatureCollection($typed, 'geometry'),
                '獲取窄巷資料成功!'
            );

        } catch (Exception $e) {
            report($e);
            return $this->debug
                ? $this->sendError($e->getMessage(), ['error' => $e->getMessage()])
                : $this->sendError('獲取窄巷資料錯誤,錯誤代碼「NA011」,請通知管理員!!', ['error' => '獲取窄巷資料錯誤,錯誤代碼「NA011」,請通知管理員!!']);
        }
    }
}
