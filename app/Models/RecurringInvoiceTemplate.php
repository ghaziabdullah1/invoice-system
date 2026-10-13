<?php

namespace App\Models;

use App\Constants\Constants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RecurringInvoiceTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'user_id',
        'frequency',
        'start_date',
        'next_run_date',
        'due_days',
        'occurrences_total',
        'occurrences_generated',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total',
        'currency',
        'status',
        'notes',
        'terms',
        'footer',
        'created_by',
    ];

    protected $casts = [
        'client_id'              => 'integer',
        'user_id'                => 'integer',
        'created_by'             => 'integer',
        'start_date'             => 'date',
        'next_run_date'          => 'date',
        'due_days'               => 'integer',
        'occurrences_total'      => 'integer',
        'occurrences_generated'  => 'integer',
        'subtotal'               => 'decimal:2',
        'tax_amount'             => 'decimal:2',
        'discount_amount'        => 'decimal:2',
        'total'                  => 'decimal:2',
    ];

    // ================================================================
    // Scopes
    // ================================================================

    /**
     * تقييد القوالب بالفروع المسموحة عبر فرع العميل (لا يوجد branch_id في القوالب).
     */
    public function scopeInAllowedBranches($query, ?array $branchIds)
    {
        return $query->when(
            is_array($branchIds),
            fn ($q) => $q->whereHas('client', fn ($client) => $client->inAllowedBranches($branchIds))
        );
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDue($query)
    {
        return $query->where('status', 'active')
            ->whereDate('next_run_date', '<=', now()->toDateString());
    }

    // ================================================================
    // Relations
    // ================================================================

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(RecurringInvoiceItem::class);
    }

    public function generatedInvoices()
    {
        return $this->hasMany(Invoice::class, 'recurring_invoice_template_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ================================================================
    // Methods
    // ================================================================

    public function remainingOccurrences(): int
    {
        return max(0, $this->occurrences_total - $this->occurrences_generated);
    }

    public function isCompleted(): bool
    {
        return $this->occurrences_generated >= $this->occurrences_total;
    }

    /**
     * يولّد فاتورة جديدة (Invoice + InvoiceItem) من هذا القالب.
     * يستخدم نفس فحص حد الائتمان الموجود بـ InvoiceRepository::store().
     * يرمي Exception لو القالب غير نشط، أو وصل الحد الأقصى، أو تجاوز حد الائتمان.
     */
    public function generateInvoice(): Invoice
    {
        if ($this->status !== 'active') {
            throw new \Exception('قالب الفاتورة المتكررة غير نشط');
        }

        if ($this->isCompleted()) {
            $this->update(['status' => 'completed']);
            throw new \Exception('تم الوصول للحد الأقصى من التكرارات');
        }

        $client = $this->client;
        if ($client && $client->exceedsCreditLimit((float) $this->total)) {
            throw new \Exception(__('messages.credit_limit_exceeded', [
                'limit' => number_format((float) $client->credit_limit, 2),
            ]));
        }

        return DB::transaction(function () {
            $invoiceModel  = new Invoice();
            $invoiceNumber = $invoiceModel->generateInvoiceNumber();

            $invoiceDate = now()->format('Y-m-d');
            $dueDate     = now()->addDays($this->due_days)->format('Y-m-d');

            $invoice = Invoice::create([
                'client_id'                     => $this->client_id,
                'user_id'                       => $this->user_id,
                'recurring_invoice_template_id' => $this->id,
                'invoice_number'                => $invoiceNumber,
                'invoice_date'                  => $invoiceDate,
                'due_date'                      => $dueDate,
                'status'                        => Constants::INVOICE_STATUS_DRAFT,
                'subtotal'                      => $this->subtotal,
                'tax_amount'                    => $this->tax_amount,
                'discount_amount'               => $this->discount_amount,
                'total'                         => $this->total,
                'currency'                      => $this->currency,
                'notes'                         => $this->notes,
                'terms'                         => $this->terms,
                'footer'                        => $this->footer,
                'created_by'                    => $this->created_by,
                'is_active'                     => true,
            ]);

            foreach ($this->items as $templateItem) {
                InvoiceItem::create([
                    'invoice_id'  => $invoice->id,
                    'description' => $templateItem->description,
                    'quantity'    => $templateItem->quantity,
                    'unit_price'  => $templateItem->unit_price,
                    'tax_rate'    => $templateItem->tax_rate,
                    'total'       => 0,
                    'item_type'   => $templateItem->item_type,
                    'notes'       => $templateItem->notes,
                ]);
            }

            $invoice->calculateTotals();

            $newOccurrences = $this->occurrences_generated + 1;
            $updateData = [
                'occurrences_generated' => $newOccurrences,
                'next_run_date'         => $this->calculateNextRunDate(),
            ];

            if ($newOccurrences >= $this->occurrences_total) {
                $updateData['status'] = 'completed';
            }

            $this->update($updateData);

            return $invoice->load(['client', 'items']);
        });
    }

    public function cancel(): void
    {
        $this->update(['status' => 'cancelled']);
    }

    private function calculateNextRunDate(): string
    {
        $base = Carbon::parse($this->next_run_date);

        return match ($this->frequency) {
            'weekly'    => $base->addWeek()->format('Y-m-d'),
            'monthly'   => $base->addMonth()->format('Y-m-d'),
            'quarterly' => $base->addMonths(3)->format('Y-m-d'),
            'yearly'    => $base->addYear()->format('Y-m-d'),
            default     => $base->addMonth()->format('Y-m-d'),
        };
    }
}