<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\RoadPlannedRequest;
use App\Repositories\RoadPlannedRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Exception;

class RoadPlannedController extends BaseController
{
    protected bool $debug;

    public function __construct(private RoadPlannedRepository $repository)
    {
        $this->debug = App::hasDebugModeEnabled();
    }

    /**
     * 回傳計畫道路列表（含 GeoJSON 幾何）
     * 用於地圖圖層顯示
     * 可透過 ?district=大同區 篩選特定行政區（空間過濾）
     * 可透過 ?category=narrow|mid|wide 篩選寬度分級
     */
    public function index(RoadPlannedRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $results   = $this->repository->getFiltered(
                $validated['district'] ?? null,
                $validated['category'] ?? null,
            );

            $tableList = $results->map(function ($road) {
                return [
                    'id'             => (string) $road->id,
                    'road_width'     => (string) $road->road_width,
                    'width_m'        => (float) $road->width_m,
                    'width_category' => (string) $road->width_category,
                    'geometry'       => json_decode($road->geometry, true),
                ];
            })->toArray();

            return $this->sendResponse([
                'tableList' => array_values($tableList),
                'total'     => count($tableList),
            ], '獲取計畫道路資料成功!');

        } catch (Exception $e) {
            report($e);
            return $this->debug
                ? $this->sendError($e->getMessage(), ['error' => $e->getMessage()])
                : $this->sendError('獲取計畫道路資料錯誤,錯誤代碼「RP011」,請通知管理員!!', ['error' => '獲取計畫道路資料錯誤,錯誤代碼「RP011」,請通知管理員!!']);
        }
    }
}
