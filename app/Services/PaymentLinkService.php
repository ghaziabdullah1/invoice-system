<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Constants\Constants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PaymentLinkService
{
    public function createPaymentLink(Invoice $invoice, array $options = []): PaymentLink
    {
        $expiresIn = $options['expires_in_days'] ?? 7;
        $amount = $options['amount'] ?? $invoice->total;
        PaymentLink::where('invoice_id', $invoice->id)->where('status', 'active')->update(['status' => 'cancelled']);
        return PaymentLink::create([
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'amount' => $amount,
            'currency' => $invoice->currency ?? Constants::CURRENCY_SAR,
            'expires_at' => now()->addDays($expiresIn),
            'metadata' => array_merge(
                ['invoice_number' => $invoice->invoice_number, 'client_name' => $invoice->client->name ?? null, 'created_by' => auth()->id()],
                $options['metadata'] ?? []
            ),
        ]);
    }

    public function createInstallmentPaymentLink(Invoice $invoice, int $installmentNumber): PaymentLink
    {
        $plan = $invoice->installmentPlan;
        if (!$plan) throw new \InvalidArgumentException('Invoice does not have an installment plan');
        $installment = $plan->installments()->where('installment_number', $installmentNumber)->where('status', 'pending')->first();
        if (!$installment) throw new \InvalidArgumentException('Installment not found or already paid');
        return $this->createPaymentLink($invoice, [
            'amount' => $installment->amount,
            'expires_in_days' => 3,
            'metadata' => ['installment_number' => $installmentNumber],
        ]);
    }

    public function validateLink(string $token): ?PaymentLink
    {
        $link = PaymentLink::where('token', $token)->first();
        if (!$link) return null;
        if (!$link->isActive()) {
            if ($link->status === 'active' && $link->isExpired()) $link->markAsExpired();
            return null;
        }
        return $link;
    }

    public function processPayment(PaymentLink $link, array $paymentData): void
    {
        $method = $paymentData['payment_method'] ?? 'card';
        $link->markAsPaid($method);
        $invoice = $link->invoice;

        if ($invoice->installmentPlan) {
            // رابط القسط يسدّد قسطه بالضبط، وغيره يسدّد أول قسط معلّق
            $query = $invoice->installmentPlan->installments()->where('status', 'pending');
            $number = $link->metadata['installment_number'] ?? null;
            $installment = $number
                ? $query->where('installment_number', $number)->first()
                : $query->orderBy('installment_number')->first();
            if ($installment) $installment->markAsPaid($method);
        } elseif ((float) $link->amount >= (float) $invoice->total) {
            $invoice->markAsPaid();
        } else {
            // دفعة جزئية على فاتورة بلا أقساط: لا نسدّد الفاتورة كلها
            Log::warning('Partial payment link paid; invoice left unchanged', [
                'link_id' => $link->id, 'invoice_id' => $invoice->id, 'amount' => $link->amount, 'total' => $invoice->total,
            ]);
        }
    }

    // المبلغ بأصغر وحدة تقبلها Stripe حسب العملة
    public function toStripeAmount(float $amount, string $currency): int
    {
        $c = strtolower($currency);
        if (in_array($c, ['bif','clp','djf','gnf','jpy','kmf','krw','mga','pyg','rwf','ugx','vnd','vuv','xaf','xof','xpf'], true)) {
            return (int) round($amount);
        }
        if (in_array($c, ['bhd','jod','kwd','omr','tnd'], true)) {
            return (int) (round($amount * 100) * 10); // 3 خانات ومن مضاعفات 10
        }
        return (int) round($amount * 100);
    }

    // يرجع ['status' => bool, 'message' => string, 'data' => ?array]
    public function createCheckoutForLink(string $token): array
    {
        $fail = fn (string $m) => ['status' => false, 'message' => $m, 'data' => null];
        try {
            $link = $this->validateLink($token);
            if (!$link) return $fail('الرابط غير صالح أو منتهي الصلاحية');

            $invoice = $link->invoice;
            if (!$invoice->enable_stripe_checkout) return $fail('الدفع الإلكتروني غير مفعّل لهذه الفاتورة');
            if ($invoice->status === Constants::INVOICE_STATUS_PAID) return $fail('الفاتورة مدفوعة بالكامل');

            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            $currency = strtolower($link->currency ?: 'sar');
            $front = rtrim((string) config('app.frontend_url'), '/');

            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $this->toStripeAmount((float) $link->amount, $currency),
                        'product_data' => ['name' => 'Invoice #' . $invoice->invoice_number],
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => $front . '/pay/' . $link->token . '?payment=success',
                'cancel_url' => $front . '/pay/' . $link->token . '?payment=cancelled',
                'metadata' => [
                    'payment_link_id' => $link->id,
                    'invoice_id' => $invoice->id,
                    'client_id' => $invoice->client_id,
                ],
            ]);

            $link->update(['stripe_session_id' => $session->id]);

            // سجل الدفعة اختياري: إتمام الدفع لا يعتمد عليه
            try {
                Payment::create([
                    'invoice_id' => $invoice->id,
                    'client_id' => $invoice->client_id,
                    'user_id' => null,
                    'amount' => $link->amount,
                    'currency' => strtoupper($currency),
                    'status' => Constants::PAYMENT_STATUS_PENDING,
                    'payment_gateway' => 'stripe',
                    'stripe_checkout_session_id' => $session->id,
                    'metadata' => ['session_id' => $session->id, 'payment_link_id' => $link->id],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Public checkout: payment row not created', ['error' => $e->getMessage()]);
            }

            return ['status' => true, 'message' => 'ok', 'data' => ['url' => $session->url]];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Public checkout Stripe error', ['error' => $e->getMessage()]);
            return $fail('تعذر إنشاء جلسة الدفع، حاول لاحقاً');
        } catch (\Throwable $e) {
            Log::error('Public checkout error', ['error' => $e->getMessage()]);
            return $fail('حدث خطأ غير متوقع');
        }
    }

    // يُستدعى من الـ webhook عند checkout.session.completed لجلسات الروابط. آمن للتكرار.
    public function completeFromSession(object $session): void
    {
        $linkId = $session->metadata->payment_link_id ?? null;
        if (!$linkId || ($session->payment_status ?? null) !== 'paid') return;

        DB::transaction(function () use ($session, $linkId) {
            $link = PaymentLink::lockForUpdate()->find($linkId);
            if (!$link || $link->status === 'paid') return;

            $payment = Payment::where('stripe_checkout_session_id', $session->id)->first();
            if ($payment) {
                $done = defined(Constants::class . '::PAYMENT_STATUS_COMPLETED')
                    ? constant(Constants::class . '::PAYMENT_STATUS_COMPLETED') : 'completed';
                $payment->update(['status' => $done, 'stripe_payment_intent_id' => $session->payment_intent ?? null]);
            }

            $this->processPayment($link, ['payment_method' => 'card']);
        });
    }

    // يرجع ['status' => bool, 'message' => string, 'data' => ?array]
    public function sendLinkToClient(PaymentLink $link, string $method = 'email'): array
    {
        try {
            $link->loadMissing('client');
            $client = $link->client;
            $url = $link->public_url;
            $amount = number_format((float) $link->amount, 2) . ' ' . $link->currency;
            $number = (string) ($link->metadata['invoice_number'] ?? '');
            $data = null;

            if ($method === 'email') {
                if (empty($client?->email)) {
                    return ['status' => false, 'message' => 'العميل ليس لديه بريد إلكتروني', 'data' => null];
                }
                $html = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif">'
                    . '<p>مرحباً ' . e($client->name ?? '') . '،</p>'
                    . '<p>رابط الدفع للفاتورة ' . e($number) . ' بمبلغ ' . e($amount) . ':</p>'
                    . '<p><a href="' . e($url) . '">' . e($url) . '</a></p>'
                    . '<p>ينتهي الرابط في: ' . e(optional($link->expires_at)->format('Y-m-d')) . '</p></div>';
                Mail::html($html, function ($m) use ($client, $number) {
                    $m->to($client->email)->subject('رابط دفع الفاتورة ' . $number);
                });
            } elseif ($method === 'whatsapp') {
                $text = 'رابط دفع الفاتورة ' . $number . ' بمبلغ ' . $amount . ': ' . $url;
                $phone = preg_replace('/\D+/', '', (string) ($client->phone ?? ''));
                $data = ['whatsapp_url' => 'https://wa.me/' . $phone . '?text=' . rawurlencode($text)];
            } else {
                return ['status' => false, 'message' => 'الإرسال عبر SMS غير مفعّل', 'data' => null];
            }

            $link->update(['sent_via' => $method, 'sent_at' => now()]);
            return ['status' => true, 'message' => 'sent', 'data' => $data];
        } catch (\Throwable $e) {
            Log::error('Failed to send payment link', ['link_id' => $link->id, 'method' => $method, 'error' => $e->getMessage()]);
            return ['status' => false, 'message' => 'فشل الإرسال: ' . $e->getMessage(), 'data' => null];
        }
    }
}
