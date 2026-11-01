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
    public function sendLink(Request $request, $linkId)
    {
        $link = PaymentLink::whereHas('invoice', fn ($q) => $q->inAllowedBranches(request()->attributes->get('allowed_branch_ids')))
            ->find($linkId);

        if (!$link) {
            return response()->json([
                'status'  => false,
                'message' => __('messages.not_found'),
            ], 404);
        }

        $method = $request->input('method', 'email');
        if (!in_array($method, ['email', 'sms', 'whatsapp'], true)) {
            return response()->json(['status' => false, 'message' => 'invalid_method'], 422);
        }

        $result = $this->paymentLinkService->sendLinkToClient($link, $method);

        return response()->json([
            'status'  => $result['status'],
            'message' => $result['message'],
            'data'    => $result['data'] ?? null,
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

    /**
     * بيانات محدودة لصفحة الدفع العامة (بدون token أو metadata)
     * GET /api/payment-links/{token}/public
     */
    public function publicInfo($token)
    {
        $link = PaymentLink::with(['invoice:id,invoice_number,status', 'client:id,name'])
            ->where('token', $token)
            ->first();

        if (!$link) {
            return response()->json(['status' => false, 'message' => 'not_found'], 404);
        }

        if ($link->status === 'active' && $link->isExpired()) {
            $link->markAsExpired();
        }

        return response()->json([
            'status' => true,
            'data'   => [
                'state'          => $link->status,
                'amount'         => (float) $link->amount,
                'currency'       => $link->currency,
                'invoice_number' => $link->invoice->invoice_number ?? null,
                'client_name'    => $link->client->name ?? null,
                'expires_at'     => optional($link->expires_at)->toIso8601String(),
            ],
        ]);
    }

    /**
     * إنشاء جلسة Stripe من الرابط (عام)
     * POST /api/payment-links/{token}/checkout
     */
    public function checkout($token)
    {
        $result = $this->paymentLinkService->createCheckoutForLink($token);

        return response()->json([
            'status'  => $result['status'],
            'message' => $result['message'],
            'data'    => $result['data'],
        ], $result['status'] ? 200 : 422);
    }
}
