<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Repository\Admin\Report\ReportInterface;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\Report\ReportFilterRequest;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\InvoiceReportExport;
use App\Exports\ClientReportExport;
use App\Exports\RevenueReportExport;
use App\Exports\OverdueReportExport;
use Illuminate\Support\Facades\Log;

class ReportController extends Controller
{
    protected $reportRepository;

    public function __construct(ReportInterface $reportRepository)
    {
        $this->reportRepository = $reportRepository;
    }

    /**
     * تقرير الفواتير
     */
    public function invoices(ReportFilterRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $filters['branch_id'] = $request->attributes->get('selected_branch_id');
            $report = $this->reportRepository->getInvoiceReport($filters);

            return response()->json([
                'success' => true,
                'message' => 'تم تحميل تقرير الفواتير بنجاح',
                'data' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في تحميل التقرير: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * تقرير العملاء
     */
    public function clients(ReportFilterRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $filters['branch_id'] = $request->attributes->get('selected_branch_id');
            $report = $this->reportRepository->getClientReport($filters);

            return response()->json([
                'success' => true,
                'message' => 'تم تحميل تقرير العملاء بنجاح',
                'data' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في تحميل التقرير: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * تقرير الإيرادات
     */
    public function revenue(ReportFilterRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $filters['branch_id'] = $request->attributes->get('selected_branch_id');
            $report = $this->reportRepository->getRevenueReport($filters);

            return response()->json([
                'success' => true,
                'message' => 'تم تحميل تقرير الإيرادات بنجاح',
                'data' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في تحميل التقرير: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * تقرير المتأخرات
     */
    public function overdue(ReportFilterRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $filters['branch_id'] = $request->attributes->get('selected_branch_id');
            $report = $this->reportRepository->getOverdueReport($filters);

            return response()->json([
                'success' => true,
                'message' => 'created',
                'data' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'failed to create report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * تقرير أعمار الديون
     */
    public function aging(ReportFilterRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $filters['branch_id'] = $request->attributes->get('selected_branch_id');
            $report = $this->reportRepository->getAgingReport($filters);

            return response()->json([
                'success' => true,
                'message' => 'created',
                'data' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'failed to create report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * تصدير التقرير
     */
    // public function export(Request $request, string $type): JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    // {
    //     try {
    //         if (in_array($request->input('lang'), ['ar', 'en'], true)) {
    //             app()->setLocale($request->input('lang'));
    //         }

    //         $filters = $request->all();
    //         unset($filters['lang']);
    //         $filters['branch_id'] = $request->attributes->get('selected_branch_id');

    //         if (empty($filters['start_date'])) {
    //             $filters['start_date'] = now()->subDays(30)->format('Y-m-d');
    //         }
    //         if (empty($filters['end_date'])) {
    //             $filters['end_date'] = now()->format('Y-m-d');
    //         }

    //         $reportData = $this->getReportData($type, $filters);

    //         if (empty($reportData['items'])) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => __('reports.no_data')
    //             ], 404);
    //         }

    //         $exportClass = $this->getExportClass($type, $reportData);

    //         if ($request->has('download') && $request->input('download') === '1') {
    //             return Excel::download($exportClass, $this->getFileName($type));
    //         }

    //         $fileName = $this->getFileName($type, true);
    //         $filePath = 'exports/' . $fileName;
    //         Excel::store($exportClass, $filePath, 'public');

    //         return response()->json([
    //             'success' => true,
    //             'message' => __('messages.export_success'),
    //             'data' => [
    //                 'file_path' => $filePath,
    //                 'file_name' => $fileName,
    //                 'url' => asset('storage/' . $filePath)
    //             ]
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('Export failed', [
    //             'type' => $type,
    //             'message' => $e->getMessage(),
    //             'trace' => $e->getTraceAsString()
    //         ]);

    //         return response()->json([
    //             'success' => false,
    //             'message' => __('messages.export_failed') . ': ' . $e->getMessage()
    //         ], 500);
    //     }
    // }

    /**
     * تصدير التقرير
     */
    public function export(Request $request, string $type): JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        try {
            if (in_array($request->input('lang'), ['ar', 'en'], true)) {
                app()->setLocale($request->input('lang'));
            }

            $filters = $request->except(['lang', 'download']);
            $filters['branch_id'] = $request->attributes->get('selected_branch_id');

            if (empty($filters['start_date'])) {
                $filters['start_date'] = now()->subDays(30)->format('Y-m-d');
            }

            if (empty($filters['end_date'])) {
                $filters['end_date'] = now()->format('Y-m-d');
            }

            $reportData = $this->getReportData($type, $filters);

            Log::info('Report export result', [
                'type' => $type,
                'filters' => $filters,
                'items_count' => count($reportData['items'] ?? []),
            ]);

            if (empty($reportData['items'])) {
                return response()->json([
                    'success' => false,
                    'message' => __('reports.no_data'),
                ], 404);
            }

            $exportClass = $this->getExportClass($type, $reportData);

            if ($request->boolean('download')) {
                return Excel::download(
                    $exportClass,
                    $this->getFileName($type)
                );
            }

            $fileName = $this->getFileName($type, true);
            $filePath = 'exports/' . $fileName;

            Excel::store($exportClass, $filePath, 'public');

            return response()->json([
                'success' => true,
                'message' => __('messages.export_success'),
                'data' => [
                    'file_path' => $filePath,
                    'file_name' => $fileName,
                    'url' => asset('storage/' . $filePath),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Export failed', [
                'type' => $type,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('messages.export_failed') . ': ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * جلب بيانات التقرير حسب النوع
     */
    private function getReportData(string $type, array $filters): array
    {
        switch ($type) {
            case 'invoices':
                return $this->reportRepository->getInvoiceReport($filters);
            case 'clients':
                return $this->reportRepository->getClientReport($filters);
            case 'revenue':
                return $this->reportRepository->getRevenueReport($filters);
            case 'overdue':
                return $this->reportRepository->getOverdueReport($filters);
            default:
                throw new \InvalidArgumentException("report type not found: {$type}");
        }
    }

    /**
     * الحصول على كلاس التصدير المناسب
     */
    private function getExportClass(string $type, array $data)
    {
        switch ($type) {
            case 'invoices':
                return new InvoiceReportExport($data);
            case 'clients':
                return new ClientReportExport($data);
            case 'revenue':
                return new RevenueReportExport($data);
            case 'overdue':
                return new OverdueReportExport($data);
            default:
                throw new \InvalidArgumentException("report type not found: {$type}");
        }
    }

    /**
     * توليد اسم الملف
     */
    private function getFileName(string $type, bool $withTimestamp = false): string
    {
        $names = [
            'invoices' => 'invoice_report',
            'clients' => 'client_report',
            'revenue' => 'revenue_report',
            'overdue' => 'overdue_report',
        ];

        $baseName = $names[$type] ?? 'report';
        $timestamp = $withTimestamp ? '_' . date('Y_m_d_His') : '';

        return $baseName . $timestamp . '.xlsx';
    }

    /**
     * تحميل التقرير مباشرة
     */
    private function downloadReport(string $type, array $filters): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $export = $this->reportRepository->exportReport($type, $filters);
        $filePath = storage_path('app/public/' . $export['file_path']);

        return response()->download($filePath, $export['file_name']);
    }

    /**
     * الحصول على الملفات المصدرة
     */
    public function exportedFiles(): JsonResponse
    {
        try {
            $exportsPath = storage_path('app/public/exports');
            $files = [];

            if (file_exists($exportsPath)) {
                $fileList = scandir($exportsPath);

                foreach ($fileList as $file) {
                    if ($file !== '.' && $file !== '..') {
                        $filePath = $exportsPath . '/' . $file;
                        $files[] = [
                            'name' => $file,
                            'size' => $this->formatSize(filesize($filePath)),
                            'modified' => date('Y-m-d H:i:s', filemtime($filePath)),
                            'url' => asset('storage/exports/' . $file)
                        ];
                    }
                }
            }

            usort($files, function ($a, $b) {
                return strtotime($b['modified']) - strtotime($a['modified']);
            });

            return response()->json([
                'success' => true,
                'message' => 'failed to create report: ' . $e->getMessage(),
                'data' => $files
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'failed to create report: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * حذف ملف مصدر
     */
    public function deleteExportedFile(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'file_name' => ['required', 'string', 'regex:/^[A-Za-z0-9_]+\.xlsx$/'],
            ]);

            $filePath = storage_path('app/public/exports/' . $request->input('file_name'));

            if (!is_file($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'file_not_found'
                ], 404);
            }

            if (unlink($filePath)) {
                return response()->json([
                    'success' => true,
                    'message' => 'file_deleted_successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'failed_to_delete_file'
                ], 500);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'failed to delete file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * إرسال تذكير
     */
    public function sendReminder(Request $request, int $invoiceId): JsonResponse
    {
        try {
            $result = $this->reportRepository->sendInvoiceReminder($invoiceId);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $result['invoice']
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'failed to send reminder: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * تسديد فاتورة
     */
    public function markAsPaid(Request $request, int $invoiceId): JsonResponse
    {
        try {
            $result = $this->reportRepository->markInvoiceAsPaid($invoiceId);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $result['invoice']
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'failed to mark invoice as paid: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * تنسيق حجم الملف
     */
    private function formatSize($bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            return $bytes . ' bytes';
        } elseif ($bytes == 1) {
            return $bytes . ' byte';
        } else {
            return '0 bytes';
        }
    }
}
