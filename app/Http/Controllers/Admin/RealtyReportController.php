<?php

namespace App\Http\Controllers\Admin;

use App\Exports\RealtyReportExport;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\RealtyReportFilterRequest;
use App\Repository\Admin\Report\RealtyReportRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class RealtyReportController extends Controller
{
    public function __construct(private RealtyReportRepository $repo)
    {
    }

    // التقارير المسموحة لهذا المستخدم + العقارات المسموحة (لقائمة الفلتر)
    public function meta(): JsonResponse
    {
        $reports = array_values(array_filter(
            array_keys(RealtyReportRepository::PERMISSIONS),
            fn ($k) => PermissionHelper::checkPermission(RealtyReportRepository::PERMISSIONS[$k])
        ));

        return response()->json([
            'success' => true,
            'data'    => [
                'reports'    => $reports,
                'properties' => $reports ? $this->repo->visibleProperties() : [],
            ],
        ]);
    }

    public function show(RealtyReportFilterRequest $request, string $key): JsonResponse
    {
        if ($deny = $this->deny($key)) {
            return $deny;
        }
        $this->applyLocale($request);

        try {
            return response()->json([
                'success' => true,
                'message' => 'ok',
                'data'    => $this->repo->build($key, $request->validated()),
            ]);
        } catch (\Throwable $e) {
            Log::error('Realty report failed', ['key' => $key, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'فشل في تحميل التقرير: ' . $e->getMessage()], 500);
        }
    }

    public function export(RealtyReportFilterRequest $request, string $key)
    {
        if ($deny = $this->deny($key)) {
            return $deny;
        }
        $this->applyLocale($request);

        try {
            $data = $this->repo->build($key, $request->validated());
            $rows = collect($data['items'])->toArray();

            if (empty($rows)) {
                return response()->json(['success' => false, 'message' => __('reports.no_data')], 404);
            }

            return Excel::download(new RealtyReportExport($key, $rows), 'realty_' . $key . '_' . date('Y_m_d_His') . '.xlsx');
        } catch (\Throwable $e) {
            Log::error('Realty report export failed', ['key' => $key, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'فشل في تصدير التقرير: ' . $e->getMessage()], 500);
        }
    }

    private function deny(string $key): ?JsonResponse
    {
        $perm = RealtyReportRepository::PERMISSIONS[$key] ?? null;

        if (!$perm || !PermissionHelper::checkPermission($perm)) {
            return response()->json(['success' => false, 'message' => 'ليس لديك صلاحية لعرض هذا التقرير'], 403);
        }

        return null;
    }

    private function applyLocale(RealtyReportFilterRequest $request): void
    {
        if (in_array($request->input('lang'), ['ar', 'en'], true)) {
            app()->setLocale($request->input('lang'));
        }
    }
}
