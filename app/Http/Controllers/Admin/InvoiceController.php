<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Repository\Admin\Invoice\InvoiceInterface;
use Illuminate\Http\Request;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

use App\Models\InstallmentPlan;

class InvoiceController extends Controller
{
    protected $invoiceRepository;

    public function __construct(InvoiceInterface $invoiceRepository)
    {
        $this->invoiceRepository = $invoiceRepository;
    }

    /**
     * جلب الفاتورة مقيّدة بالفروع المسموح بها للمستخدم الحالي.
     */
    private function findAccessibleInvoice($id, array $with = []): ?Invoice
    {
        return Invoice::with($with)
            ->inAllowedBranches(request()->attributes->get('allowed_branch_ids'))
            ->find($id);
    }

    public function index(Request $request)
    {
        $result = $this->invoiceRepository->index($request);
        return response()->json($result, $result['status'] ? 200 : 500);
    }

    public function store(Request $request)
    {
        $result = $this->invoiceRepository->store($request);
        return response()->json($result, $result['status'] ? 201 : 500);
    }

    public function show($id)
    {
        $result = $this->invoiceRepository->show($id);
        return response()->json($result, $result['status'] ? 200 : ($result['message'] === __('messages.not_found') ? 404 : 500));
    }

    public function update(Request $request, $id)
    {
        $invoice = $this->findAccessibleInvoice($id);
        if (!$invoice) {
            return response()->json([
                'status'  => false,
                'message' => __('messages.not_found'),
            ], 404);
        }

        $result = $this->invoiceRepository->update($request, $invoice);
        return response()->json($result, $result['status'] ? 200 : 500);
    }

    public function destroy($id)
    {
        $invoice = $this->findAccessibleInvoice($id);
        if (!$invoice) {
            return response()->json([
                'status'  => false,
                'message' => __('messages.not_found'),
            ], 404);
        }

        $result = $this->invoiceRepository->destroy($invoice);
        return response()->json($result, $result['status'] ? 200 : 500);
    }

    public function dashboardStats(Request $request)
    {
        $result = $this->invoiceRepository->getDashboardStats($request->attributes->get('selected_branch_id'));
        return response()->json($result, $result['status'] ? 200 : 500);
    }

    public function overdueInvoices(Request $request)
    {
        $result = $this->invoiceRepository->getOverdueInvoices($request->attributes->get('selected_branch_id'));
        return response()->json($result, $result['status'] ? 200 : 500);
    }

    public function recentInvoices(Request $request)
    {
        $limit = $request->get('limit', 10);
        $branchId = $request->attributes->get('selected_branch_id');
        $result = $this->invoiceRepository->getRecentInvoices($limit, $branchId);
        return response()->json($result, $result['status'] ? 200 : 500);
    }

    public function downloadPDF($id)
    {
        $invoice = $this->findAccessibleInvoice($id);
        if (!$invoice) {
            return response()->json([
                'status'  => false,
                'message' => __('messages.not_found'),
            ], 404);
        }

        $result = $this->invoiceRepository->generatePDF($invoice);
        return response()->json($result, $result['status'] ? 200 : 500);
    }

    public function duplicate($id)
    {
        $invoice = $this->findAccessibleInvoice($id);
        if (!$invoice) {
            return response()->json([
                'status'  => false,
                'message' => __('messages.not_found'),
            ], 404);
        }

        $result = $this->invoiceRepository->duplicate($invoice);
        return response()->json($result, $result['status'] ? 201 : 500);
    }

    public function generatePDF($id)
    {
        $invoice = $this->findAccessibleInvoice($id);
        if (!$invoice) {
            return response()->json([
                'status'  => false,
                'message' => __('messages.not_found'),
            ], 404);
        }

        $result = $this->invoiceRepository->generatePDF($invoice);
        return response()->json($result, $result['status'] ? 200 : 500);
    }

    public function markAsPaid(Request $request, $id)
    {
        $invoice = $this->findAccessibleInvoice($id);
        if (!$invoice) {
            return response()->json([
                'status'  => false,
                'message' => __('messages.not_found'),
            ], 404);
        }

        $result = $this->invoiceRepository->markAsPaid($request, $invoice);
        return response()->json($result, $result['status'] ? 200 : 500);
    }

    public function send($id)
    {
        $invoice = $this->findAccessibleInvoice($id);
        if (!$invoice) {
            return response()->json([
                'status'  => false,
                'message' => __('messages.not_found'),
            ], 404);
        }

        $result = $this->invoiceRepository->sendInvoice($invoice);
        return response()->json($result, $result['status'] ? 200 : 500);
    }

    /**
     * ✅ إضافة: الحصول على خطة الأقساط المرتبطة بالفاتورة
     */
    public function getInstallmentPlan($id)
    {
        try {
            $invoice = $this->findAccessibleInvoice($id, ['installmentPlan.installments', 'client']);

            if (!$invoice) {
                return response()->json([
                    'status'  => false,
                    'message' => __('messages.not_found'),
                ], 404);
            }

            $plan = $invoice->installmentPlan;

            if (!$plan) {
                return response()->json([
                    'status'  => false,
                    'message' => 'no_installment_plan',
                ], 404);
            }

            return response()->json([
                'status'  => true,
                'message' => 'fetched',
                'data'    => $plan,
            ], 200);
        } catch (\Exception $e) {
            Log::error('InvoiceController getInstallmentPlan error', [
                'invoice_id' => $id,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => __('messages.operation_failed'),
            ], 500);
        }
    }

    public function notificationCounts()
    {
        $counts = \App\Helpers\InvoiceNotificationHelper::getCounts();

        return response()->json([
            'status' => true,
            'message' => 'Notification counts fetched',
            'data' => [
                'unpaid'       => $counts['unpaid'],
                'overdue'      => $counts['overdue'],
                'due_soon'     => $counts['due_soon'],
                'open_tickets' => $counts['open_tickets'],
                'total'        => $counts['total'],
            ],
        ]);
    }
}
