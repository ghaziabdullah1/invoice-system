<?php

namespace App\Repository\Admin\InstallmentPlan;

use App\Models\Installment;
use App\Models\InstallmentInterestTier;
use App\Models\InstallmentPlan;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InstallmentPlanRepository implements InstallmentPlanInterface
{
    public function findForInvoice(Invoice $invoice): ?InstallmentPlan
    {
        return InstallmentPlan::where('invoice_id', $invoice->id)
            ->with('installments')
            ->latest()
            ->first();
    }

    /**
     * نسبة الفائدة المقترحة من الجدول الافتراضي حسب عدد الأقساط.
     * ترجع 0 إذا ما فيه تعريف مسبق لهذا العدد (تصير القيمة يدوية بالكامل).
     */
    public function suggestInterestRate(int $numberOfInstallments): float
    {
        $tier = InstallmentInterestTier::active()
            ->where('number_of_installments', $numberOfInstallments)
            ->first();

        return $tier ? (float) $tier->interest_rate : 0.0;
    }

    /**
     * إنشاء خطة أقساط لفاتورة.
     *
     * حساب الفائدة: فائدة بسيطة على المبلغ الأصلي (نسبة% تُضاف مرة واحدة
     * ثم تُقسّم بالتساوي على عدد الأقساط) — حسب القرار المعتمد للمشروع.
     * القسط الأخير يمتص أي فرق تقريب (كسور) عشان مجموع الأقساط يطابق
     * total_amount تماماً دون فلس زيادة أو نقصان.
     *
     * @param array{
     *   number_of_installments: int,
     *   interest_rate?: float|null,
     *   start_date: string,
     *   frequency?: string,
     *   notes?: string|null,
     *   created_by?: int|null
     * } $data
     */
    public function create(Invoice $invoice, array $data): InstallmentPlan
    {
        $numberOfInstallments = (int) $data['number_of_installments'];

        if ($numberOfInstallments < 2) {
            throw ValidationException::withMessages([
                'number_of_installments' => 'يجب أن تكون خطة الأقساط قسطين على الأقل.',
            ]);
        }

        if ($invoice->isPaid()) {
            throw ValidationException::withMessages([
                'invoice' => 'لا يمكن إنشاء خطة أقساط لفاتورة مدفوعة بالكامل.',
            ]);
        }

        $existingActivePlan = InstallmentPlan::where('invoice_id', $invoice->id)
            ->where('status', 'active')
            ->exists();

        if ($existingActivePlan) {
            throw ValidationException::withMessages([
                'invoice' => 'يوجد بالفعل خطة أقساط فعّالة لهذه الفاتورة.',
            ]);
        }

        // إن لم تُرسل نسبة يدوياً، نستخدم الجدول الافتراضي (وقد تكون 0 إن لم يوجد تعريف)
        $interestRate = array_key_exists('interest_rate', $data) && $data['interest_rate'] !== null
            ? (float) $data['interest_rate']
            : $this->suggestInterestRate($numberOfInstallments);

        if ($interestRate < 0) {
            throw ValidationException::withMessages([
                'interest_rate' => 'نسبة الفائدة لا يمكن أن تكون سالبة.',
            ]);
        }

        $originalAmount = (float) $invoice->total;
        $interestAmount = round($originalAmount * $interestRate / 100, 2);
        $totalAmount    = round($originalAmount + $interestAmount, 2);

        $frequency = $data['frequency'] ?? 'monthly';
        $startDate = Carbon::parse($data['start_date']);

        return DB::transaction(function () use (
            $invoice,
            $numberOfInstallments,
            $interestRate,
            $originalAmount,
            $interestAmount,
            $totalAmount,
            $startDate,
            $frequency,
            $data
        ) {
            $plan = InstallmentPlan::create([
                'invoice_id'              => $invoice->id,
                'number_of_installments'  => $numberOfInstallments,
                'interest_rate'           => $interestRate,
                'original_amount'         => $originalAmount,
                'interest_amount'         => $interestAmount,
                'total_amount'            => $totalAmount,
                'start_date'              => $startDate->toDateString(),
                'frequency'               => $frequency,
                'status'                  => 'active',
                'notes'                   => $data['notes'] ?? null,
                'created_by'              => $data['created_by'] ?? null,
            ]);

            $this->generateInstallments($plan);

            return $plan->load('installments');
        });
    }

    /**
     * توليد جدول الأقساط: مبلغ متساوٍ لكل قسط، والقسط الأخير يمتص فرق التقريب.
     */
    private function generateInstallments(InstallmentPlan $plan): void
    {
        $baseAmount = floor(($plan->total_amount / $plan->number_of_installments) * 100) / 100;
        $allocated  = round($baseAmount * ($plan->number_of_installments - 1), 2);
        $lastAmount = round($plan->total_amount - $allocated, 2);

        $dueDate = Carbon::parse($plan->start_date);

        for ($i = 1; $i <= $plan->number_of_installments; $i++) {
            $isLast = $i === $plan->number_of_installments;

            Installment::create([
                'installment_plan_id' => $plan->id,
                'installment_number'  => $i,
                'due_date'            => $dueDate->toDateString(),
                'amount'              => $isLast ? $lastAmount : $baseAmount,
                'status'              => 'pending',
            ]);

            $dueDate = $plan->frequency === 'weekly'
                ? $dueDate->copy()->addWeek()
                : $dueDate->copy()->addMonthNoOverflow();
        }
    }

    public function payInstallment(int $installmentId, string $paymentMethod = 'cash')
    {
        return DB::transaction(function () use ($installmentId, $paymentMethod) {
            $installment = Installment::with('plan.invoice')
                ->whereHas('plan.invoice', fn ($q) => $q->inAllowedBranches(request()->attributes->get('allowed_branch_ids')))
                ->lockForUpdate()
                ->findOrFail($installmentId);

            if ($installment->status === 'paid') {
                throw ValidationException::withMessages([
                    'installment' => 'هذا القسط مدفوع بالفعل.',
                ]);
            }

            if ($installment->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'installment' => 'لا يمكن دفع قسط ضمن خطة ملغاة.',
                ]);
            }

            $payment = $installment->markAsPaid($paymentMethod);

            $plan = $installment->plan;
            if ($plan->isFullyPaid()) {
                $plan->markAsCompleted();
                $plan->invoice->markAsPaid();
            }

            return $installment->fresh(['payment', 'plan']);
        });
    }

    public function cancel(InstallmentPlan $plan): InstallmentPlan
    {
        if ($plan->status !== 'active') {
            throw ValidationException::withMessages([
                'plan' => 'لا يمكن إلغاء خطة غير فعّالة.',
            ]);
        }

        $plan->cancel();

        return $plan->fresh('installments');
    }
}
