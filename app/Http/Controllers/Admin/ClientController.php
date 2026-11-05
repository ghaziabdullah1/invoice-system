<?php

namespace App\Http\Controllers\Admin;

use App\Traits\ResponseTrait;
use App\Http\Controllers\Controller;
use App\Constants\Constants;
use App\Helpers\PermissionHelper;
use App\Repository\Admin\Client\ClientInterface;
use App\Http\Requests\Admin\Client\StoreClientRequest;
use App\Http\Requests\Admin\Client\UpdateClientRequest;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    use ResponseTrait;

    protected $client;

    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
    }

    public function index(Request $request)
    {
        if (!PermissionHelper::checkPermission(Constants::VIEW_CLIENTS)) {
            return $this->failureResponse(
                __('messages.no_permission'),
                null,
                Constants::RESPONSE_FORBIDDEN
            );
        }

        $data = $this->client->index($request);

        if ($data['status']) {
            return $this->successResponse(
                __('messages.clients_fetched'),
                $data['data']
            );
        }

        return $this->failureResponse($data['message'], $data['data']);
    }

    public function show($id)
    {
        if (!PermissionHelper::checkPermission(Constants::VIEW_CLIENTS)) {
            return $this->failureResponse(
                __('messages.no_permission'),
                null,
                Constants::RESPONSE_FORBIDDEN
            );
        }

        $data = $this->client->show($id);

        if ($data['status']) {
            return $this->successResponse(
                __('messages.client_fetched'),
                $data['data']
            );
        }

        return $this->failureResponse($data['message'], $data['data']);
    }

    public function store(StoreClientRequest $request)
    {
        if (!PermissionHelper::checkPermission(Constants::CREATE_CLIENT)) {
            return $this->failureResponse(
                __('messages.no_permission'),
                null,
                Constants::RESPONSE_FORBIDDEN
            );
        }

        $data = $this->client->store($request);

        if ($data['status']) {
            return $this->successResponse(
                __('messages.client_created'),
                $data['data'],
                Constants::RESPONSE_CREATED
            );
        }

        return $this->failureResponse($data['message'], $data['data']);
    }

    public function update(UpdateClientRequest $request, $id)
    {
        if (!PermissionHelper::checkPermission(Constants::EDIT_CLIENT)) {
            return $this->failureResponse(
                __('messages.no_permission'),
                null,
                Constants::RESPONSE_FORBIDDEN
            );
        }

        $data = $this->client->show($id);

        if (!$data['status']) {
            return $this->failureResponse($data['message'], $data['data']);
        }

        $updateData = $this->client->update($request, $data['data']);

        if ($updateData['status']) {
            return $this->successResponse(
                __('messages.client_updated'),
                $updateData['data']
            );
        }

        return $this->failureResponse($updateData['message'], $updateData['data']);
    }

    public function destroy($id)
    {
        if (!PermissionHelper::checkPermission(Constants::DELETE_CLIENT)) {
            return $this->failureResponse(
                __('messages.no_permission'),
                null,
                Constants::RESPONSE_FORBIDDEN
            );
        }

        $data = $this->client->show($id);

        if (!$data['status']) {
            return $this->failureResponse($data['message'], $data['data']);
        }

        $deleteData = $this->client->destroy($data['data']);

        if ($deleteData['status']) {
            return $this->successResponse(
                __('messages.client_deleted'),
                $deleteData['data']
            );
        }

        return $this->failureResponse($deleteData['message'], $deleteData['data']);
    }

    public function stats($id)
    {
        if (!PermissionHelper::checkPermission(Constants::VIEW_CLIENTS)) {
            return $this->failureResponse(
                __('messages.no_permission'),
                null,
                Constants::RESPONSE_FORBIDDEN
            );
        }

        $data = $this->client->show($id);

        if (!$data['status']) {
            return $this->failureResponse($data['message'], $data['data']);
        }

        $statsData = $this->client->getClientStats($data['data']);

        if ($statsData['status']) {
            return $this->successResponse(
                __('messages.client_stats_fetched'),
                $statsData['data']
            );
        }

        return $this->failureResponse($statsData['message'], $statsData['data']);
    }

    public function search(Request $request)
    {
        if (!PermissionHelper::checkPermission(Constants::VIEW_CLIENTS)) {
            return $this->failureResponse(
                __('messages.no_permission'),
                null,
                Constants::RESPONSE_FORBIDDEN
            );
        }

        $data = $this->client->searchClients($request);

        if ($data['status']) {
            return $this->successResponse(
                __('messages.clients_fetched'),
                $data['data']
            );
        }

        return $this->failureResponse($data['message'], $data['data']);
    }

    public function invoices($id)
    {
        if (!PermissionHelper::checkPermission(Constants::VIEW_CLIENTS)) {
            return $this->failureResponse(
                __('messages.no_permission'),
                null,
                Constants::RESPONSE_FORBIDDEN
            );
        }

        $data = $this->client->show($id);

        if (!$data['status']) {
            return $this->failureResponse($data['message'], $data['data']);
        }

        $invoicesData = $this->client->getClientInvoices($data['data']);

        if ($invoicesData['status']) {
            return $this->successResponse(
                __('messages.client_invoices_fetched'),
                $invoicesData['data']
            );
        }

        return $this->failureResponse($invoicesData['message'], $invoicesData['data']);
    }

    public function statement(\Illuminate\Http\Request $request, $id)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date|after_or_equal:from',
        ]);

        $allowed = $request->attributes->get('allowed_branch_ids');
        $client = \App\Models\Client::inAllowedBranches($allowed)->find($id);
        if (!$client) {
            return response()->json(['status' => false, 'message' => __('messages.not_found')], 404);
        }

        $from = $request->filled('from') ? \Carbon\Carbon::parse($request->input('from'))->toDateString() : null;
        $to   = $request->filled('to') ? \Carbon\Carbon::parse($request->input('to'))->toDateString() : null;

        $invoices = \App\Models\Invoice::inAllowedBranches($allowed)
            ->where('client_id', $client->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', \App\Constants\Constants::INVOICE_STATUS_DRAFT)
            ->get(['id', 'invoice_number', 'invoice_date', 'payment_date', 'paid_at', 'status', 'total', 'currency']);

        $rows = [];
        foreach ($invoices as $inv) {
            if (!$inv->invoice_date) {
                continue;
            }
            $cur   = $inv->currency ?: ($client->currency ?: '');
            $total = (float) $inv->total;

            $rows[$cur][] = [
                'date' => $inv->invoice_date->toDateString(), 'order' => 0, 'type' => 'invoice',
                'ref' => $inv->invoice_number, 'invoice_id' => $inv->id, 'debit' => $total, 'credit' => 0.0,
            ];

            if ($inv->status === \App\Constants\Constants::INVOICE_STATUS_PAID) {
                $paidOn = ($inv->payment_date ?? $inv->paid_at ?? $inv->invoice_date)->toDateString();
                $rows[$cur][] = [
                    'date' => $paidOn, 'order' => 1, 'type' => 'payment',
                    'ref' => $inv->invoice_number, 'invoice_id' => $inv->id, 'debit' => 0.0, 'credit' => $total,
                ];
            }
        }

        $sections = [];
        foreach ($rows as $cur => $list) {
            usort($list, fn ($a, $b) => [$a['date'], $a['order'], $a['invoice_id']] <=> [$b['date'], $b['order'], $b['invoice_id']]);

            $opening = 0.0;
            foreach ($list as $r) {
                if ($from && $r['date'] < $from) {
                    $opening += $r['debit'] - $r['credit'];
                }
            }

            $balance = $opening;
            $debit = 0.0;
            $credit = 0.0;
            $entries = [];
            foreach ($list as $r) {
                if (($from && $r['date'] < $from) || ($to && $r['date'] > $to)) {
                    continue;
                }
                $balance += $r['debit'] - $r['credit'];
                $debit   += $r['debit'];
                $credit  += $r['credit'];
                $entries[] = [
                    'date' => $r['date'], 'type' => $r['type'], 'ref' => $r['ref'], 'invoice_id' => $r['invoice_id'],
                    'debit' => round($r['debit'], 3), 'credit' => round($r['credit'], 3), 'balance' => round($balance, 3),
                ];
            }

            $sections[] = [
                'currency'     => (string) $cur,
                'opening'      => round($opening, 3),
                'total_debit'  => round($debit, 3),
                'total_credit' => round($credit, 3),
                'closing'      => round($balance, 3),
                'entries'      => $entries,
            ];
        }

        return response()->json([
            'status' => true,
            'data'   => [
                'client'   => ['id' => $client->id, 'name' => $client->name, 'company_name' => $client->company_name, 'currency' => $client->currency],
                'from'     => $from,
                'to'       => $to,
                'sections' => $sections,
            ],
        ]);
    }
}
