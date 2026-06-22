<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\FireHydrantRequest;
use App\Repositories\FireHydrantRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Exception;

class FireHydrantController extends BaseController
{
    protected bool $debug;

    public function __construct(private FireHydrantRepository $repository)
    {
        $this->debug = App::hasDebugModeEnabled();
    }

    /**
     * 回傳消防栓點位（含 GeoJSON 幾何）
     * 用於地圖圖層顯示
     * 可透過 ?district=大同區 篩選特定行政區
     */
    public function index(FireHydrantRequest $request): JsonResponse
    {
        try {
            $hydrants = $this->repository->getFiltered(
                $request->validated()['district'] ?? null
            );

            $typed = $hydrants->map(fn($h) => [
                'id'       => (string) $h->id,
                'wpid'     => (string) $h->wpid,
                'type'     => (string) $h->type,
                'district' => (string) $h->district,
                'geometry' => json_decode($h->geometry),
            ]);

            return $this->sendResponse(
                $this->toFeatureCollection($typed, 'geometry'),
                '獲取消防栓資料成功!'
            );

        } catch (Exception $e) {
            report($e);
            return $this->debug
                ? $this->sendError($e->getMessage(), ['error' => $e->getMessage()])
                : $this->sendError('獲取消防栓資料錯誤,錯誤代碼「FH011」,請通知管理員!!', ['error' => '獲取消防栓資料錯誤,錯誤代碼「FH011」,請通知管理員!!']);
        }
    }
}
