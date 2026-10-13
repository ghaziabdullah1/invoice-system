<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentLink;
use App\Services\PaymentLinkService;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class PaymentLinkController extends Controller
{
    protected $paymentLinkService;

    public function __construct(PaymentLinkService $paymentLinkService)
    {
        $this->paymentLinkService = $paymentLinkService;
    }

    /**
     * عرض كل روابط الدفع الخاصة بفاتورة
     * GET /api/admin/invoices/{invoiceId}/payment-links
     */
    public function index($invoiceId)
    {
        $invoice = Invoice::inAllowedBranches(request()->attributes->get('allowed_branch_ids'))->find($invoiceId);

        if (!$invoice) {
            return response()->json([
                'status'  => false,
                'message' => __('messages.not_found'),
            ], 404);
        }

        $links = PaymentLink::where('invoice_id', $invoiceId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'data'   => $links,
        ]);
    }

    /**
     * إنشاء رابط دفع للفاتورة كاملة
     * POST /api/admin/invoices/{invoiceId}/payment-links
     */
    public function createForInvoice(Request $request, $invoiceId)
    {
        $invoice = Invoice::inAllowedBranches(request()->attributes->get('allowed_branch_ids'))->find($invoiceId);

        if (!$invoice) {
            return response()->json([
                'status'  => false,
                'message' => __('messages.not_found'),
            ], 404);
        }

        try {
            $link = $this->paymentLinkService->createPaymentLink($invoice, [
                'amount'          => $request->input('amount'),
                'expires_in_days' => $request->input('expires_in_days', 7),
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'created',
                'data'    => $link,
            ], 201);
        } catch (\Exception $e) {
            Log::error('PaymentLink create error', [
                'invoice_id' => $invoiceId,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * إنشاء رابط دفع لقسط محدد
     * POST /api/admin/invoices/{invoiceId}/installments/{installmentNumber}/payment-links
     */
    public function createForInstallment(Request $request, $invoiceId, $installmentNumber)
    {
        $invoice = Invoice::inAllowedBranches(request()->attributes->get('allowed_branch_ids'))->find($invoiceId);

        if (!$invoice) {
            return response()->json([
                'status'  => false,
                'message' => __('messages.not_found'),
            ], 404);
        }

        try {
            $link = $this->paymentLinkService->createInstallmentPaymentLink(
                $invoice,
                (int) $installmentNumber
            );

            return response()->json([
                'status'  => true,
                'message' => 'created',
                'data'    => $link,
            ], 201);
        } catch (\Exception $e) {
            Log::error('PaymentLink installment error', [
                'invoice_id'        => $invoiceId,
                'installment_number' => $installmentNumber,
                'error'             => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * إرسال رابط الدفع للعميل
     * POST /api/admin/payment-links/{linkId}/send
     */
    public function sendLink($linkId)
    {
        $link = PaymentLink::whereHas('invoice', fn ($q) => $q->inAllowedBranches(request()->attributes->get('allowed_branch_ids')))
            ->find($linkId);

        if (!$link) {
            return response()->json([
                'status'  => false,
                'message' => __('messages.not_found'),
            ], 404);
        }

        $result = $this->paymentLinkService->sendLinkToClient($link);

        return response()->json([
            'status'  => $result,
            'message'   => $result ? 'created' : 'failed',
        ]);
    }

    /**
     * التحقق من صحة رابط الدفع (للعميل - بدون تسجيل)
     * GET /api/payment-links/{token}/validate
     */
    public function validateLink($token)
    {
        $link = $this->paymentLinkService->validateLink($token);

        if (!$link) {
            return response()->json([
                'status'  => false,
                'message' => 'invalid_or_expired',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data'   => $link,
        ]);
    }
}
