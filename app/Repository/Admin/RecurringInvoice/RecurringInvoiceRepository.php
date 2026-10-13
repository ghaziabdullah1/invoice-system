<?php

namespace App\Repository\Admin\RecurringInvoice;

use App\Models\Client;
use App\Models\RecurringInvoiceTemplate;
use App\Models\RecurringInvoiceItem;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecurringInvoiceRepository implements RecurringInvoiceInterface
{
    private const ALLOWED_FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'yearly'];

    public function index($request)
    {
        try {
            $query = RecurringInvoiceTemplate::with(['client', 'items'])
                ->inAllowedBranches(request()->attributes->get('allowed_branch_ids'))
                ->orderBy('created_at', 'desc');

            if ($request->has('status') && !empty($request->status)) {
                $query->where('status', $request->status);
            }

            if ($request->has('client_id') && !empty($request->client_id)) {
                $query->where('client_id', (int) $request->client_id);
            }

            $perPage = min((int) ($request->per_page ?? 20), 100);
            $templates = $query->paginate($perPage);

            return [
                'status'  => true,
                'message' => 'تم جلب القوالب بنجاح',
                'data'    => $templates,
            ];
        } catch (\Exception $e) {
            Log::error('RecurringInvoiceRepository index error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function show($id)
    {
        try {
            $template = RecurringInvoiceTemplate::with(['client', 'items', 'generatedInvoices'])
                ->inAllowedBranches(request()->attributes->get('allowed_branch_ids'))
                ->find((int) $id);

            if (!$template) {
                return [
                    'status'  => false,
                    'message' => 'القالب غير موجود',
                    'data'    => null,
                ];
            }

            return [
                'status'  => true,
                'message' => 'تم جلب القالب بنجاح',
                'data'    => $template,
            ];
        } catch (\Exception $e) {
            Log::error('RecurringInvoiceRepository show error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function store($request)
    {
        DB::beginTransaction();

        try {
            $frequency = $request->frequency;
            if (!in_array($frequency, self::ALLOWED_FREQUENCIES, true)) {
                return [
                    'status'  => false,
                    'message' => 'نوع التكرار غير صالح',
                    'data'    => null,
                ];
            }

            $occurrencesTotal = (int) $request->occurrences_total;
            if ($occurrencesTotal < 1) {
                return [
                    'status'  => false,
                    'message' => 'عدد التكرارات يجب أن يكون 1 على الأقل',
                    'data'    => null,
                ];
            }

            $items = $request->items ?? [];
            if (empty($items)) {
                return [
                    'status'  => false,
                    'message' => 'يجب إضافة عنصر واحد على الأقل للقالب',
                    'data'    => null,
                ];
            }

            $clientIsAccessible = Client::inAllowedBranches(request()->attributes->get('allowed_branch_ids'))
                ->whereKey((int) $request->client_id)
                ->exists();

            if (!$clientIsAccessible) {
                DB::rollBack();

                return [
                    'status'  => false,
                    'message' => __('messages.client_not_found'),
                    'data'    => null,
                ];
            }

            $userId = auth()->id();

            $template = RecurringInvoiceTemplate::create([
                'client_id'         => (int) $request->client_id,
                'user_id'           => $userId,
                'frequency'         => $frequency,
                'start_date'        => $request->start_date,
                'next_run_date'     => $request->start_date,
                'due_days'          => (int) ($request->due_days ?? 30),
                'occurrences_total' => $occurrencesTotal,
                'currency'          => strtoupper($request->currency ?? 'SAR'),
                'status'            => 'active',
                'notes'             => $request->notes,
                'terms'             => $request->terms,
                'footer'            => $request->footer,
                'created_by'        => $userId,
            ]);

            $subtotal = 0;
            foreach ($items as $item) {
                $quantity  = max(0.01, (float) ($item['quantity'] ?? 0));
                $unitPrice = max(0, (float) ($item['unit_price'] ?? 0));
                $taxRate   = min(100, max(0, (float) ($item['tax_rate'] ?? 0)));
                $lineTotal = round($quantity * $unitPrice * (1 + $taxRate / 100), 2);

                RecurringInvoiceItem::create([
                    'recurring_invoice_template_id' => $template->id,
                    'description'                   => substr(trim($item['description'] ?? ''), 0, 500),
                    'quantity'                       => $quantity,
                    'unit_price'                     => $unitPrice,
                    'tax_rate'                       => $taxRate,
                    'total'                          => $lineTotal,
                    'item_type'                      => $item['item_type'] ?? 'product',
                    'notes'                          => $item['notes'] ?? null,
                ]);

                $subtotal += $lineTotal;
            }

            $taxAmount      = max(0, (float) ($request->tax_amount ?? 0));
            $discountAmount = max(0, (float) ($request->discount_amount ?? 0));

            $template->update([
                'subtotal'        => $subtotal,
                'tax_amount'      => $taxAmount,
                'discount_amount' => $discountAmount,
                'total'           => $subtotal + $taxAmount - $discountAmount,
            ]);

            ActivityLog::log('CREATE', 'إنشاء قالب فاتورة متكررة #' . $template->id, $template);

            DB::commit();

            return [
                'status'  => true,
                'message' => 'تم إنشاء قالب الفاتورة المتكررة بنجاح',
                'data'    => $template->load(['client', 'items']),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('RecurringInvoiceRepository store error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function update($request, $template)
    {
        DB::beginTransaction();

        try {
            $updateData = [];

            if ($request->has('frequency') && in_array($request->frequency, self::ALLOWED_FREQUENCIES, true)) {
                $updateData['frequency'] = $request->frequency;
            }
            if ($request->has('due_days')) {
                $updateData['due_days'] = (int) $request->due_days;
            }
            if ($request->has('notes')) {
                $updateData['notes'] = $request->notes;
            }
            if ($request->has('terms')) {
                $updateData['terms'] = $request->terms;
            }
            if ($request->has('footer')) {
                $updateData['footer'] = $request->footer;
            }

            if ($request->has('items') && is_array($request->items)) {
                $template->items()->delete();

                $subtotal = 0;
                foreach ($request->items as $item) {
                    $quantity  = max(0.01, (float) ($item['quantity'] ?? 0));
                    $unitPrice = max(0, (float) ($item['unit_price'] ?? 0));
                    $taxRate   = min(100, max(0, (float) ($item['tax_rate'] ?? 0)));
                    $lineTotal = round($quantity * $unitPrice * (1 + $taxRate / 100), 2);

                    RecurringInvoiceItem::create([
                        'recurring_invoice_template_id' => $template->id,
                        'description'                   => substr(trim($item['description'] ?? ''), 0, 500),
                        'quantity'                       => $quantity,
                        'unit_price'                     => $unitPrice,
                        'tax_rate'                       => $taxRate,
                        'total'                          => $lineTotal,
                        'item_type'                      => $item['item_type'] ?? 'product',
                        'notes'                          => $item['notes'] ?? null,
                    ]);

                    $subtotal += $lineTotal;
                }

                $taxAmount      = max(0, (float) ($request->tax_amount ?? $template->tax_amount));
                $discountAmount = max(0, (float) ($request->discount_amount ?? $template->discount_amount));

                $updateData['subtotal']        = $subtotal;
                $updateData['tax_amount']      = $taxAmount;
                $updateData['discount_amount'] = $discountAmount;
                $updateData['total']           = $subtotal + $taxAmount - $discountAmount;
            }

            $template->update($updateData);

            ActivityLog::log('UPDATE', 'تحديث قالب فاتورة متكررة #' . $template->id, $template);

            DB::commit();

            return [
                'status'  => true,
                'message' => 'تم تحديث القالب بنجاح',
                'data'    => $template->load(['client', 'items']),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('RecurringInvoiceRepository update error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function destroy($template)
    {
        DB::beginTransaction();

        try {
            $templateId = $template->id;

            ActivityLog::log('DELETE', 'حذف قالب فاتورة متكررة #' . $templateId, $template);

            $template->items()->delete();
            $template->delete();

            DB::commit();

            return [
                'status'  => true,
                'message' => 'تم حذف القالب بنجاح',
                'data'    => ['id' => $templateId],
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('RecurringInvoiceRepository destroy error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function generateNow($template)
    {
        try {
            $invoice = $template->generateInvoice();

            ActivityLog::log('CREATE', 'توليد يدوي لفاتورة من القالب #' . $template->id, $invoice);

            return [
                'status'  => true,
                'message' => 'تم توليد الفاتورة بنجاح',
                'data'    => $invoice,
            ];
        } catch (\Exception $e) {
            return [
                'status'  => false,
                'message' => $e->getMessage(),
                'data'    => null,
            ];
        }
    }

    public function cancel($template)
    {
        try {
            $template->cancel();

            ActivityLog::log('UPDATE', 'إلغاء قالب فاتورة متكررة #' . $template->id, $template);

            return [
                'status'  => true,
                'message' => 'تم إلغاء القالب بنجاح',
                'data'    => $template->fresh(),
            ];
        } catch (\Exception $e) {
            Log::error('RecurringInvoiceRepository cancel error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }
}