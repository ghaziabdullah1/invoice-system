<?php

namespace App\Repository\Admin\Client;

use App\Models\Client;
use App\Models\ActivityLog;
use App\Constants\Constants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientRepository implements ClientInterface
{
    public function index($request)
    {
        try {
            $query = Client::withCount('invoices')
                ->withSum('invoices', 'total')
                ->withSum(['invoices as paid_amount' => function ($q) {
                    $q->where('status', Constants::INVOICE_STATUS_PAID);
                }], 'total')
                ->with('creator:id,name')
                ->when($request->attributes->get('selected_branch_id'), fn($q, $branchId) => $q->where('branch_id', $branchId))
                ->orderBy('created_at', 'desc');

            if ($request->has('is_active') && $request->is_active !== '') {
                $query->where('is_active', (bool) $request->is_active);
            }

            if ($request->has('search') && !empty($request->search)) {
                $search = substr(trim($request->search), 0, 100);
                $query->search($search);
            }

            if ($request->has('per_page')) {
                $perPage = (int) $request->per_page;
                $perPage = min($perPage, Constants::MAX_PER_PAGE);
                $perPage = max($perPage, Constants::MIN_PER_PAGE);
                $clients = $query->paginate($perPage);
            } else {
                $clients = $query->paginate(Constants::DEFAULT_PER_PAGE);
            }

            $clients->getCollection()->transform(function ($client) {
                $client->total_invoiced = (float) ($client->invoices_sum_total ?? 0);
                $client->total_paid     = (float) ($client->paid_amount ?? 0);
                $client->total_due      = $client->total_invoiced - $client->total_paid;
                return $client;
            });

            return [
                'status'  => true,
                'message' => __('messages.clients_fetched'),
                'data'    => $clients,
            ];
        } catch (\Exception $e) {
            Log::error('ClientRepository index error', ['error' => $e->getMessage()]);

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
            if (!is_numeric($id) || (int) $id <= 0) {
                return [
                    'status'  => false,
                    'message' => __('messages.client_not_found'),
                    'data'    => null,
                ];
            }

            $client = Client::withCount('invoices')
                ->withSum('invoices', 'total')
                ->withSum(['invoices as paid_amount' => function ($q) {
                    $q->where('status', Constants::INVOICE_STATUS_PAID);
                }], 'total')
                ->withSum(['invoices as pending_amount' => function ($q) {
                    $q->where('status', Constants::INVOICE_STATUS_SENT);
                }], 'total')
                ->withSum(['invoices as overdue_amount' => function ($q) {
                    $q->where('status', Constants::INVOICE_STATUS_OVERDUE);
                }], 'total')
                ->with('creator:id,name')
                ->inAllowedBranches(request()->attributes->get('allowed_branch_ids'))
                ->find((int) $id);

            if (!$client) {
                return [
                    'status'  => false,
                    'message' => __('messages.client_not_found'),
                    'data'    => null,
                ];
            }

            $client->total_invoiced  = (float) ($client->invoices_sum_total ?? 0);
            $client->total_paid      = (float) ($client->paid_amount ?? 0);
            $client->total_due       = $client->total_invoiced - $client->total_paid;
            $client->pending_amount  = (float) ($client->pending_amount ?? 0);
            $client->overdue_amount  = (float) ($client->overdue_amount ?? 0);

            return [
                'status'  => true,
                'message' => __('messages.client_fetched'),
                'data'    => $client,
            ];
        } catch (\Exception $e) {
            Log::error('ClientRepository show error', ['id' => $id, 'error' => $e->getMessage()]);

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
            if (Client::where('email', strtolower(trim($request->email)))->exists()) {
                return [
                    'status'  => false,
                    'message' => __('messages.email_already_registered'),
                    'data'    => null,
                ];
            }

            // ✅ الفرع: من الطلب صراحة إن وُجد، وإلا الفرع المختار حالياً؛
            // ونتحقق أن المستخدم فعلاً يملك صلاحية الوصول لهذا الفرع
            $branchId = $request->has('branch_id') && $request->branch_id
                ? (int) $request->branch_id
                : $request->attributes->get('selected_branch_id');

            $allowedBranchIds = $request->attributes->get('allowed_branch_ids');
            if ($branchId && is_array($allowedBranchIds) && !in_array((int) $branchId, $allowedBranchIds, true)) {
                DB::rollBack();

                return [
                    'status'  => false,
                    'message' => 'ليس لديك صلاحية لإضافة عميل لهذا الفرع',
                    'data'    => null,
                ];
            }

            $client = Client::create([
                'name'          => $request->name,
                'email'         => strtolower(trim($request->email)),
                'phone'         => $request->phone,
                'address'       => $request->address,
                'company_name'  => $request->company_name,
                'tax_number'    => $request->tax_number,
                'payment_terms' => $request->payment_terms ?? Constants::PAYMENT_TERM_NET_30,
                'credit_limit'  => $request->credit_limit,
                'currency'      => $request->currency      ?? Constants::CURRENCY_SAR,
                'notes'         => $request->notes,
                'is_active'     => $request->is_active ?? true,
                'branch_id'     => $branchId ? (int) $branchId : null,
                'created_by'    => auth()->id(),
            ]);

            ActivityLog::log('CREATE', __('messages.client_created') . ': ' . $client->name, $client);

            DB::commit();

            return [
                'status'  => true,
                'message' => __('messages.client_created'),
                'data'    => $client->load('creator:id,name'),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ClientRepository store error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function update($request, $client)
    {
        DB::beginTransaction();

        try {
            $oldValues = $client->toArray();

            if ($request->has('email') && strtolower(trim($request->email)) !== $client->email) {
                if (Client::where('email', strtolower(trim($request->email)))->where('id', '!=', $client->id)->exists()) {
                    return [
                        'status'  => false,
                        'message' => __('messages.email_already_registered'),
                        'data'    => null,
                    ];
                }
            }

            $allowedFields = [
                'name',
                'email',
                'phone',
                'address',
                'company_name',
                'tax_number',
                'payment_terms',
                'credit_limit',
                'currency',
                'notes',
                'is_active',
                'branch_id',
            ];

            $updateData = [];
            foreach ($allowedFields as $field) {
                if ($request->has($field)) {
                    $updateData[$field] = $request->$field;
                }
            }

            if (isset($updateData['is_active'])) {
                $updateData['is_active'] = (bool) $updateData['is_active'];
            }
            if (isset($updateData['email'])) {
                $updateData['email'] = strtolower(trim($updateData['email']));
            }

            // ✅ نقل العميل لفرع آخر - العميل فقط، فواتيره القديمة تبقى
            // على فرعها الأصلي (لا تنتقل معه تلقائيًا)
            if (array_key_exists('branch_id', $updateData)) {
                $newBranchId = $updateData['branch_id'] ? (int) $updateData['branch_id'] : null;
                $allowedBranchIds = $request->attributes->get('allowed_branch_ids');

                if ($newBranchId && is_array($allowedBranchIds) && !in_array($newBranchId, $allowedBranchIds, true)) {
                    return [
                        'status'  => false,
                        'message' => 'ليس لديك صلاحية لنقل العميل لهذا الفرع',
                        'data'    => null,
                    ];
                }

                $updateData['branch_id'] = $newBranchId;
            }

            $client->update($updateData);

            ActivityLog::log('UPDATE', __('messages.client_updated') . ': ' . $client->name, $client, $oldValues, $client->fresh()->toArray());

            DB::commit();

            $client = Client::withCount('invoices')
                ->withSum('invoices', 'total')
                ->withSum(['invoices as paid_amount' => fn($q) => $q->where('status', Constants::INVOICE_STATUS_PAID)], 'total')
                ->with('creator:id,name')
                ->find($client->id);

            $client->total_invoiced = (float) ($client->invoices_sum_total ?? 0);
            $client->total_paid     = (float) ($client->paid_amount ?? 0);
            $client->total_due      = $client->total_invoiced - $client->total_paid;

            return [
                'status'  => true,
                'message' => __('messages.client_updated'),
                'data'    => $client,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ClientRepository update error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function destroy($client)
    {
        DB::beginTransaction();

        try {
            $client->loadCount('invoices');

            if ($client->invoices_count > 0) {
                return [
                    'status'  => false,
                    'message' => __('messages.client_has_invoices'),
                    'data'    => null,
                ];
            }

            $clientName = $client->name;
            $clientId   = $client->id;

            ActivityLog::log('DELETE', __('messages.client_deleted') . ': ' . $clientName, $client);
            $client->delete();

            DB::commit();

            return [
                'status'  => true,
                'message' => __('messages.client_deleted'),
                'data'    => ['id' => $clientId],
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ClientRepository destroy error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function getClientStats($client)
    {
        try {
            $stats = $client->invoices()
                ->selectRaw('
                    COUNT(*) as total_invoices,
                    SUM(total) as total_amount,
                    SUM(CASE WHEN status = ? THEN total ELSE 0 END) as paid_amount,
                    SUM(CASE WHEN status = ? THEN total ELSE 0 END) as pending_amount,
                    SUM(CASE WHEN status = ? THEN total ELSE 0 END) as overdue_amount
                ', [
                    Constants::INVOICE_STATUS_PAID,
                    Constants::INVOICE_STATUS_SENT,
                    Constants::INVOICE_STATUS_OVERDUE,
                ])
                ->first();

            return [
                'status'  => true,
                'message' => __('messages.client_stats_fetched'),
                'data'    => [
                    'total_invoices'  => (int)   ($stats->total_invoices  ?? 0),
                    'total_amount'    => (float)  ($stats->total_amount    ?? 0),
                    'paid_amount'     => (float)  ($stats->paid_amount     ?? 0),
                    'pending_amount'  => (float)  ($stats->pending_amount  ?? 0),
                    'overdue_amount'  => (float)  ($stats->overdue_amount  ?? 0),
                    'currency'        => $client->currency ?? Constants::CURRENCY_SAR,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('ClientRepository getClientStats error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function searchClients($request)
    {
        try {
            $search = substr(trim($request->search ?? ''), 0, 100);

            $clients = Client::select(['id', 'name', 'email', 'phone', 'company_name', 'is_active', 'branch_id'])
                ->when($request->attributes->get('selected_branch_id'), fn($q, $branchId) => $q->where('branch_id', $branchId))
                ->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                })
                ->limit(10)
                ->get();

            return [
                'status'  => true,
                'message' => __('messages.client_search_fetched'),
                'data'    => $clients,
            ];
        } catch (\Exception $e) {
            Log::error('ClientRepository searchClients error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function getClientInvoices($client)
    {
        try {
            // ملاحظة: بلا فلترة فرع عمداً - صفحة العميل تعرض سجله الكامل
            // حتى لو جزء منه يعود لفرع نُقل منه لاحقًا
            $invoices = $client->invoices()
                ->with(['items'])
                ->orderBy('created_at', 'desc')
                ->paginate(Constants::DEFAULT_PER_PAGE);

            return [
                'status'  => true,
                'message' => __('messages.client_invoices_fetched'),
                'data'    => $invoices,
            ];
        } catch (\Exception $e) {
            Log::error('ClientRepository getClientInvoices error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }
}