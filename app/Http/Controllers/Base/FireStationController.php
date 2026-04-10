<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\FireStationRequest;
use App\Repositories\FireStationRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Exception;

class FireStationController extends BaseController
{
    protected bool $debug;

    public function __construct(private FireStationRepository $repository)
    {
        $this->debug = App::hasDebugModeEnabled();
    }

    /**
     * 回傳消防隊點位（含 GeoJSON 幾何）
     * 用於地圖圖層顯示
     * 可透過 ?district=大同區 篩選特定行政區（空間過濾）
     */
    public function index(FireStationRequest $request): JsonResponse
    {
        try {
            $stations = $this->repository->getFiltered(
                $request->validated()['district'] ?? null
            );

            $tableList = $stations->map(function ($station) {
                return [
                    'id'       => (string) $station->id,
                    'name'     => (string) $station->name,
                    'address'  => (string) $station->address,
                    'geometry' => json_decode($station->geometry, true),
                ];
            })->toArray();

            return $this->sendResponse([
                'tableList' => array_values($tableList),
                'total'     => count($tableList),
            ], '獲取消防局資料成功!');

        } catch (Exception $e) {
            report($e);
            return $this->debug
                ? $this->sendError($e->getMessage(), ['error' => $e->getMessage()])
                : $this->sendError('獲取消防局資料錯誤,錯誤代碼「FS011」,請通知管理員!!', ['error' => '獲取消防局資料錯誤,錯誤代碼「FS011」,請通知管理員!!']);
        }
    }
}
