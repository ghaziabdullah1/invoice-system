<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Repository\Admin\RecurringInvoice\RecurringInvoiceInterface;
use App\Models\RecurringInvoiceTemplate;
use Illuminate\Http\Request;

class RecurringInvoiceController extends Controller
{
    protected $repository;

    public function __construct(RecurringInvoiceInterface $repository)
    {
        $this->repository = $repository;
    }

    public function index(Request $request)
    {
        $result = $this->repository->index($request);
        return response()->json($result, $result['status'] ? 200 : 500);
    }

    public function show($id)
    {
        $result = $this->repository->show($id);
        return response()->json($result, $result['status'] ? 200 : 404);
    }

    public function store(Request $request)
    {
        $result = $this->repository->store($request);
        return response()->json($result, $result['status'] ? 201 : 422);
    }

    public function update(Request $request, $id)
    {
        $template = RecurringInvoiceTemplate::inAllowedBranches(request()->attributes->get('allowed_branch_ids'))->find($id);
        if (!$template) {
            return response()->json(['status' => false, 'message' =>'not_found'], 404);
        }

        $result = $this->repository->update($request, $template);
        return response()->json($result, $result['status'] ? 200 : 500);
    }

    public function destroy($id)
    {
        $template = RecurringInvoiceTemplate::inAllowedBranches(request()->attributes->get('allowed_branch_ids'))->find($id);
        if (!$template) {
            return response()->json(['status' => false, 'message' =>'not_found'], 404);
        }

        $result = $this->repository->destroy($template);
        return response()->json($result, $result['status'] ? 200 : 500);
    }

    public function generateNow($id)
    {
        $template = RecurringInvoiceTemplate::inAllowedBranches(request()->attributes->get('allowed_branch_ids'))->find($id);
        if (!$template) {
            return response()->json(['status' => false, 'message' =>'not_found'], 404);
        }

        $result = $this->repository->generateNow($template);
        return response()->json($result, $result['status'] ? 201 : 422);
    }

    public function cancel($id)
    {
        $template = RecurringInvoiceTemplate::inAllowedBranches(request()->attributes->get('allowed_branch_ids'))->find($id);
        if (!$template) {
            return response()->json(['status' => false, 'message' =>'not_found'], 404);
        }

        $result = $this->repository->cancel($template);
        return response()->json($result, $result['status'] ? 200 : 500);
    }
}
