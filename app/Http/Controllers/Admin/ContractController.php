<?php

namespace App\Http\Controllers\Admin;

use App\Constants\Constants;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Contract\GenerateContractInvoiceRequest;
use App\Http\Requests\Admin\Contract\RenewContractRequest;
use App\Http\Requests\Admin\Contract\StoreContractRequest;
use App\Http\Requests\Admin\Contract\TerminateContractRequest;
use App\Http\Requests\Admin\Contract\UpdateContractRequest;
use App\Http\Requests\Admin\Contract\UploadContractDocumentRequest;
use App\Repository\Admin\Contract\ContractInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Services\ContractInvoiceService;

class ContractController extends Controller
{
    use ResponseTrait;

    protected $contract;

    public function __construct(ContractInterface $contract)
    {
        $this->contract = $contract;
    }

    public function index(Request $request)
    {
        if (!PermissionHelper::checkPermission(Constants::VIEW_CONTRACTS)) {
            return $this->denied();
        }

        return $this->respond($this->contract->index($request), __('contracts.fetched'));
    }

    public function show($id)
    {
        if (!PermissionHelper::checkPermission(Constants::VIEW_CONTRACTS)) {
            return $this->denied();
        }

        return $this->respond($this->contract->show($id), __('contracts.fetched_one'));
    }

    public function store(StoreContractRequest $request)
    {
        if (!PermissionHelper::checkPermission(Constants::CREATE_CONTRACT)) {
            return $this->denied();
        }

        return $this->respond($this->contract->store($request), __('contracts.created'), Constants::RESPONSE_CREATED);
    }

    public function update(UpdateContractRequest $request, $id)
    {
        if (!PermissionHelper::checkPermission(Constants::EDIT_CONTRACT)) {
            return $this->denied();
        }

        $found = $this->contract->show($id);
        if (!$found['status']) {
            return $this->respond($found, '');
        }

        return $this->respond($this->contract->update($request, $found['data']), __('contracts.updated'));
    }

    public function destroy($id)
    {
        if (!PermissionHelper::checkPermission(Constants::DELETE_CONTRACT)) {
            return $this->denied();
        }

        $found = $this->contract->show($id);
        if (!$found['status']) {
            return $this->respond($found, '');
        }

        return $this->respond($this->contract->destroy($found['data']), __('contracts.deleted'));
    }

    public function renew(RenewContractRequest $request, $id)
    {
        if (!PermissionHelper::checkPermission(Constants::RENEW_CONTRACT)) {
            return $this->denied();
        }

        $found = $this->contract->show($id);
        if (!$found['status']) {
            return $this->respond($found, '');
        }

        return $this->respond($this->contract->renew($request, $found['data']), __('contracts.renewed'), Constants::RESPONSE_CREATED);
    }

    public function terminate(TerminateContractRequest $request, $id)
    {
        if (!PermissionHelper::checkPermission(Constants::TERMINATE_CONTRACT)) {
            return $this->denied();
        }

        $found = $this->contract->show($id);
        if (!$found['status']) {
            return $this->respond($found, '');
        }

        return $this->respond($this->contract->terminate($request, $found['data']), __('contracts.terminated'));
    }

    public function uploadDocument(UploadContractDocumentRequest $request, $id)
    {
        if (!PermissionHelper::checkPermission(Constants::EDIT_CONTRACT)) {
            return $this->denied();
        }

        $found = $this->contract->show($id);
        if (!$found['status']) {
            return $this->respond($found, '');
        }

        return $this->respond(
            $this->contract->uploadDocument($request->file('document'), $found['data']),
            __('contracts.document_uploaded')
        );
    }

    public function downloadDocument($id)
    {
        if (!PermissionHelper::checkPermission(Constants::VIEW_CONTRACTS)) {
            return $this->denied();
        }

        $found = $this->contract->show($id);
        if (!$found['status']) {
            return $this->respond($found, '');
        }

        $document = $this->contract->document($found['data']);
        if (!$document['status']) {
            return $this->respond($document, '');
        }

        $file = $document['data'];

        return Storage::disk($file['disk'])->download($file['path'], $file['name']);
    }

    public function schedule($id)
    {
        if (!PermissionHelper::checkPermission(Constants::VIEW_CONTRACTS)) {
            return $this->denied();
        }

        $found = $this->contract->show($id);
        if (!$found['status']) {
            return $this->respond($found, '');
        }

        return $this->successResponse(__('contracts.schedule_fetched'), app(ContractInvoiceService::class)->schedule($found['data']));
    }

    public function generateInvoice(GenerateContractInvoiceRequest $request, $id, $number)
    {
        if (!PermissionHelper::canAll([Constants::VIEW_CONTRACTS, 'create_invoice'])) {
            return $this->denied();
        }

        if (!is_numeric($number) || (int) $number < 1) {
            return $this->failureResponse(__('contracts.period_not_found'), null, Constants::RESPONSE_NOT_FOUND);
        }

        $found = $this->contract->show($id);
        if (!$found['status']) {
            return $this->respond($found, '');
        }

        $result = app(ContractInvoiceService::class)->generate(
            $found['data'],
            (int) $number,
            auth()->id(),
            $request->filled('client_id') ? (int) $request->client_id : null
        );

        return $this->respond($result, __('contracts.invoice_created'), Constants::RESPONSE_CREATED);
    }

    private function denied()
    {
        return $this->failureResponse(__('messages.no_permission'), null, Constants::RESPONSE_FORBIDDEN);
    }

    private function respond(array $result, string $message, $code = Constants::RESPONSE_SUCCESS)
    {
        if ($result['status']) {
            return $this->successResponse($message, $result['data'], $code);
        }

        return $this->failureResponse($result['message'], $result['data'], $result['code'] ?? Constants::RESPONSE_SERVER_ERROR);
    }
}
