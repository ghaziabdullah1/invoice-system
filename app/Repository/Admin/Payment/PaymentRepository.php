<?php

namespace App\Repository\Admin\Payment;

use App\Models\Invoice;
use App\Models\Payment;
use App\Constants\Constants;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\Webhook;
use Stripe\Refund;

class PaymentRepository implements PaymentInterface
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    public function createCheckoutSession(Invoice $invoice): array
    {
        try {
            if (!$invoice->enable_stripe_checkout) {
                return [
                    'status'  => false,
                    'message' => 'Stripe checkout is not enabled for this invoice',
                    'data'    => null,
                ];
            }

            if ($invoice->status === Constants::INVOICE_STATUS_PAID) {
                return [
                    'status'  => false,
                    'message' => 'Invoice is already paid',
                    'data'    => null,
                ];
            }

            $amountInCents = (int) round($invoice->total * 100);

            $session = Session::create([
                'payment_method_types' => ['card'],
                'line_items'           => [[
                    'price_data' => [
                        'currency'     => strtolower($invoice->currency ?? 'sar'),
                        'unit_amount'  => $amountInCents,
                        'product_data' => [
                            'name'        => 'Invoice #' . $invoice->invoice_number,
                            'description' => 'Payment for invoice ' . $invoice->invoice_number,
                        ],
                    ],
                    'quantity' => 1,
                ]],
                'mode'        => 'payment',
                'success_url' => config('app.frontend_url') . '/invoices/' . $invoice->id . '?payment=success',
                'cancel_url'  => config('app.frontend_url') . '/invoices/' . $invoice->id . '?payment=cancelled',
                'metadata'    => [
                    'invoice_id' => $invoice->id,
                    'client_id'  => $invoice->client_id,
                ],
            ]);

            Payment::create([
                'invoice_id'                 => $invoice->id,
                'client_id'                  => $invoice->client_id,
                'user_id'                    => auth()->id(),
                'amount'                     => $invoice->total,
                'currency'                   => $invoice->currency ?? 'SAR',
                'status'                     => Constants::PAYMENT_STATUS_PENDING,
                'payment_gateway'            => 'stripe',
                'stripe_checkout_session_id' => $session->id,
                'metadata'                   => ['session_id' => $session->id],
            ]);

            return [
                'status'  => true,
                'message' => 'Checkout session created successfully',
                'data'    => [
                    'session_id' => $session->id,
                    'url'        => $session->url,
                    'invoice_id' => $invoice->id,
                    'amount'     => $invoice->total,
                    'currency'   => $invoice->currency ?? 'kwd',
                ],
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe API Error: ' . $e->getMessage());
            return ['status' => false, 'message' => 'Stripe error: ' . $e->getMessage(), 'data' => null];
        } catch (\Exception $e) {
            Log::error('Payment session error: ' . $e->getMessage());
            return ['status' => false, 'message' => 'Unexpected error', 'data' => null];
        }
    }

    public function handleWebhook(string $payload, ?string $sigHeader): array
    {
        try {
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                config('services.stripe.webhook_secret')
            );

            switch ($event->type) {
                case 'checkout.session.completed':
                    $this->handleCheckoutCompleted($event->data->object);
                    break;

                case 'payment_intent.payment_failed':
                    $this->handlePaymentFailed($event->data->object);
                    break;
            }

            return ['status' => true, 'message' => 'Webhook handled'];
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Webhook signature failed: ' . $e->getMessage());
            return ['status' => false, 'message' => 'Invalid signature'];
        } catch (\Exception $e) {
            Log::error('Webhook error: ' . $e->getMessage());
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    private function handleCheckoutCompleted(object $session): void
    {
        $payment = Payment::where('stripe_checkout_session_id', $session->id)->first();

        if ($payment) {
            /*
            |------------------------------------------------------------------
            | الإصلاح: حفظ payment_intent_id هنا
            |------------------------------------------------------------------
            | في الكود القديم كان هذا السطر مفقوداً تماماً.
            | بدونه، stripe_payment_intent_id يبقى null في قاعدة البيانات،
            | وعندما تحاول refundPayment() تسترجع المبلغ تفشل لأنها
            | تبحث عن stripe_payment_intent_id ولا تجده.
            */
            $payment->update([
                'stripe_payment_intent_id' => $session->payment_intent,
            ]);

            $payment->markAsCompleted($session->payment_method_types[0] ?? 'card');
        }
    }

    private function handlePaymentFailed(object $paymentIntent): void
    {
        $payment = Payment::where('stripe_payment_intent_id', $paymentIntent->id)->first();
        if ($payment) {
            $payment->markAsFailed($paymentIntent->last_payment_error?->message);
        }
    }

    public function getPayment(int $id): array
    {
        try {
            $payment = Payment::with(['invoice', 'client'])
                ->inAllowedBranches(request()->attributes->get('allowed_branch_ids'))
                ->findOrFail($id);
            return ['status' => true, 'message' => 'Payment retrieved', 'data' => $payment];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => 'Payment not found', 'data' => null];
        }
    }

    public function getPayments(Request $request): array
    {
        try {
            $query = Payment::with(['invoice', 'client'])
                ->inAllowedBranches(request()->attributes->get('allowed_branch_ids'));

            if ($request->search) {
                $query->search($request->search);
            }

            if ($request->status) {
                $query->where('status', $request->status);
            }

            /*
            |------------------------------------------------------------------
            | الإصلاح: إضافة فلتر التاريخ
            |------------------------------------------------------------------
            | في الكود القديم كان date_from و date_to موجودَين في Vue store
            | كـ filters لكن الـ Repository لا يعالجهما — يتجاهلهما تماماً.
            | النتيجة: المستخدم يختار تاريخ ويضغط فلتر ولا يحدث شيء.
            */
            if ($request->date_from) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->date_to) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $payments = $query->latest()->paginate($request->per_page ?? 15);

            return ['status' => true, 'message' => 'Payments retrieved', 'data' => $payments];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    public function refundPayment(int $id, ?float $amount): array
    {
        try {
            $payment = Payment::inAllowedBranches(request()->attributes->get('allowed_branch_ids'))
                ->findOrFail($id);

            if (!$payment->stripe_payment_intent_id) {
                return ['status' => false, 'message' => 'No payment intent found', 'data' => null];
            }

            $refundData = ['payment_intent' => $payment->stripe_payment_intent_id];
            if ($amount) {
                $refundData['amount'] = (int) round($amount * 100);
            }

            Refund::create($refundData);

            $payment->update(['status' => Constants::PAYMENT_STATUS_REFUNDED]);

            return ['status' => true, 'message' => 'Refund processed', 'data' => $payment];
        } catch (\Exception $e) {
            Log::error('Refund error: ' . $e->getMessage());
            return ['status' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }
}
