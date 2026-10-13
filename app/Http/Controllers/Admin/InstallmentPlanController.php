<?php

namespace App\Http\Controllers\Admin;

use App\Constants\Constants;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InstallmentPlan\PayInstallmentRequest;
use App\Http\Requests\Admin\InstallmentPlan\StoreInstallmentPlanRequest;
use App\Models\Invoice;
use App\Models\InstallmentPlan;
use App\Repository\Admin\InstallmentPlan\InstallmentPlanInterface;
use App\Traits\ResponseTrait;
use Illuminate\Validation\ValidationException;

class InstallmentPlanController extends Controller
{
    use ResponseTrait;

    protected InstallmentPlanInterface $installmentPlanRepository;

    public function __construct(InstallmentPlanInterface $installmentPlanRepository)
    {
        $this->installmentPlanRepository = $installmentPlanRepository;
    }

    /**
     * عرض خطة الأقساط الحالية (إن وجدت) لفاتورة معيّنة.
     */
    public function show(Invoice $invoice)
    {
        if (!PermissionHelper::checkPermission(Constants::VIEW_INVOICES)) {
            return $this->failureResponse(__('messages.no_permission'), null, Constants::RESPONSE_FORBIDDEN);
        }

        if (!$invoice->isInAllowedBranches(request()->attributes->get('allowed_branch_ids'))) {
            return $this->notFoundResponse();
        }

        $plan = $this->installmentPlanRepository->findForInvoice($invoice);

        if (!$plan) {
            return $this->notFoundResponse('no_installment_plan');
        }

        return $this->successResponse('fetched', $plan);
    }

    /**
     * اقتراح نسبة الفائدة من الجدول الافتراضي حسب عدد الأقساط —
     * تُستخدم بالواجهة لملء الحقل تلقائياً قبل الإرسال، مع إمكانية التعديل يدوياً.
     */
    public function suggestRate(int $numberOfInstallments)
    {
        $rate = $this->installmentPlanRepository->suggestInterestRate($numberOfInstallments);

        return $this->successResponse('fetched', ['interest_rate' => $rate]);
    }

    /**
     * إنشاء خطة أقساط جديدة لفاتورة.
     */
    public function store(StoreInstallmentPlanRequest $request, Invoice $invoice)
    {
        if (!PermissionHelper::checkPermission(Constants::CREATE_INSTALLMENT)) {
            return $this->failureResponse(__('messages.no_permission'), null, Constants::RESPONSE_FORBIDDEN);
        }

        if (!$invoice->isInAllowedBranches(request()->attributes->get('allowed_branch_ids'))) {
            return $this->notFoundResponse();
        }

        try {
            $plan = $this->installmentPlanRepository->create($invoice, [
                ...$request->validated(),
                'created_by' => auth('sanctum')->id(),
            ]);

            return $this->successResponse('created', $plan, Constants::RESPONSE_CREATED);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        }
    }

    /**
     * تسجيل سداد قسط معيّن.
     */
    public function payInstallment(PayInstallmentRequest $request, int $installmentId)
    {
        if (!PermissionHelper::checkPermission(Constants::EDIT_INVOICE)) {
            return $this->failureResponse(__('messages.no_permission'), null, Constants::RESPONSE_FORBIDDEN);
        }

        try {
            $installment = $this->installmentPlanRepository->payInstallment(
                $installmentId,
                $request->input('payment_method', 'cash')
            );

            return $this->successResponse('paid', $installment);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        }
    }

    /**
     * إلغاء خطة أقساط فعّالة.
     */
    public function cancel(InstallmentPlan $installmentPlan)
    {
        if (!PermissionHelper::checkPermission(Constants::EDIT_INVOICE)) {
            return $this->failureResponse(__('messages.no_permission'), null, Constants::RESPONSE_FORBIDDEN);
        }

        if (!$installmentPlan->invoice?->isInAllowedBranches(request()->attributes->get('allowed_branch_ids'))) {
            return $this->notFoundResponse();
        }

        try {
            $plan = $this->installmentPlanRepository->cancel($installmentPlan);

            return $this->successResponse('cancelled', $plan);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        }
    }
}
