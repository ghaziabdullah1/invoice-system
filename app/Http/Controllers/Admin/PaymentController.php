<?php

namespace App\Http\Controllers\Admin;

use App\Traits\ResponseTrait;
use App\Http\Controllers\Controller;
use App\Constants\Constants;
use App\Helpers\PermissionHelper;
use App\Repository\Admin\Payment\PaymentInterface;
use App\Models\Invoice;
use Illuminate\Http\Request;
use App\Helpers\InvoiceNotificationHelper;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    use ResponseTrait;

    public $payment;

    public function __construct(PaymentInterface $payment)
    {
        $this->payment = $payment;
    }

    public function createCheckoutSession(Request $request, $invoiceId)
    {
        if (!PermissionHelper::checkPermission(Constants::CREATE_PAYMENT)) {
            return $this->failureResponse(
                __('messages.no_permission'),
                null,
                Constants::RESPONSE_FORBIDDEN
            );
        }

        $invoice = Invoice::with('client')
            ->inAllowedBranches(request()->attributes->get('allowed_branch_ids'))
            ->find($invoiceId);

        if (!$invoice) {
            return $this->failureResponse(
                __('messages.invoice_not_found'),
                null,
                Constants::RESPONSE_NOT_FOUND
            );
        }

        $data = $this->payment->createCheckoutSession($invoice);

        if ($data['status']) {
            return $this->successResponse(
                $data['message'],
                $data['data']
            );
        }

        return $this->failureResponse($data['message'], $data['data']);
    }

    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        $data = $this->payment->handleWebhook($payload, $sigHeader);

        if ($data['status']) {
            InvoiceNotificationHelper::broadcastNotification();
            return response()->json(['status' => 'success']);
        }

        return response()->json(['error' => $data['message']], 400);
    }

    public function show($id)
    {
        if (!PermissionHelper::checkPermission(Constants::VIEW_PAYMENTS)) {
            return $this->failureResponse(
                __('messages.no_permission'),
                null,
                Constants::RESPONSE_FORBIDDEN
            );
        }

        $data = $this->payment->getPayment($id);

        if ($data['status']) {
            return $this->successResponse(
                $data['message'],
                $data['data']
            );
        }

        return $this->failureResponse($data['message'], $data['data']);
    }

    public function index(Request $request)
    {
        if (!PermissionHelper::checkPermission(Constants::VIEW_PAYMENTS)) {
            return $this->failureResponse(
                __('messages.no_permission'),
                null,
                Constants::RESPONSE_FORBIDDEN
            );
        }

        $data = $this->payment->getPayments($request);

        if ($data['status']) {
            return $this->successResponse(
                $data['message'],
                $data['data']
            );
        }

        return $this->failureResponse($data['message'], $data['data']);
    }

    public function refund(Request $request, $id)
    {
        if (!PermissionHelper::checkPermission(Constants::REFUND_PAYMENT)) {
            return $this->failureResponse(
                __('messages.no_permission'),
                null,
                Constants::RESPONSE_FORBIDDEN
            );
        }

        $amount = $request->get('amount');
        $data = $this->payment->refundPayment($id, $amount);

        if ($data['status']) {
            return $this->successResponse(
                $data['message'],
                $data['data']
            );
        }

        return $this->failureResponse($data['message'], $data['data']);
    }
}
