<?php

namespace App\Repository\Admin\User;

use App\Models\User;
use App\Models\Invoice;
use App\Models\ActivityLog;
use App\Constants\Constants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class UserRepository implements UserInterface
{
    public function index($request)
    {
        try {
            $query = User::with(['adminGroup', 'branches', 'properties'])
                ->orderBy('created_at', 'desc');

            // Apply filters
            if ($request->has('is_active')) {
                $query->where('is_active', $request->is_active);
            }

            if ($request->has('admin_group_id')) {
                $query->where('admin_group_id', $request->admin_group_id);
            }

            if ($request->has('search')) {
                $query->where(function($q) use ($request) {
                    $q->where('name', 'like', "%{$request->search}%")
                      ->orWhere('email', 'like', "%{$request->search}%");
                });
            }

            // Get paginated or all results
            if ($request->has('per_page')) {
                $users = $query->paginate(
                    min($request->per_page, Constants::MAX_PER_PAGE)
                );
            } else {
                $users = $query->get();
            }

            return [
                'status' => true,
                'message' => __('messages.users_fetched'),
                'data' => $users
            ];

        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => __('messages.error') . ': ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    public function show($id)
    {
        try {
            $user = User::with(['adminGroup', 'branches', 'properties'])->find($id);

            if (!$user) {
                return [
                    'status' => false,
                    'message' => __('messages.user_not_found'),
                    'data' => null
                ];
            }

            return [
                'status' => true,
                'message' => __('messages.user_fetched'),
                'data' => $user
            ];

        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => __('messages.error') . ': ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    public function store($request)
    {
        DB::beginTransaction();

        try {
            // Check if email already exists
            if (User::where('email', $request->email)->exists()) {
                return [
                    'status' => false,
                    'message' => __('messages.email_already_registered'),
                    'data' => null
                ];
            }

            // Create user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'address' => $request->address,
                'company_name' => $request->company_name,
                'tax_number' => $request->tax_number,
                'is_active' => $request->is_active ?? true,
                'admin_group_id' => $request->admin_group_id
            ]);

            $this->syncBranches($user, $request->input('branch_ids'));

            $this->syncProperties($user, $request);

            // Log activity
            ActivityLog::log(
                Constants::ACTIVITY_CREATE,
                __('messages.user_created_log', ['name' => $user->name]),
                $user
            );

            DB::commit();

            return [
                'status' => true,
                'message' => __('messages.user_created'),
                'data' => $user
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'status' => false,
                'message' => __('messages.operation_failed') . ': ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    public function update($request, $user)
    {
        DB::beginTransaction();

        try {
            $oldValues = $user->toArray();

            // Check if email already exists for other users
            if ($request->has('email') && $request->email !== $user->email) {
                if (User::where('email', $request->email)->where('id', '!=', $user->id)->exists()) {
                    return [
                        'status' => false,
                        'message' => __('messages.email_already_registered'),
                        'data' => null
                    ];
                }
            }

            $updateData = [
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'address' => $request->address,
                'company_name' => $request->company_name,
                'tax_number' => $request->tax_number,
                'is_active' => $request->is_active ?? $user->is_active,
                'admin_group_id' => $request->admin_group_id ?? $user->admin_group_id
            ];

            // Update password if provided
            if ($request->has('password') && $request->password) {
                $updateData['password'] = Hash::make($request->password);
            }

            // Update user
            $user->update($updateData);

            $this->syncProperties($user, $request);

            if ($request->has('branch_ids')) {
                $this->syncBranches($user, $request->input('branch_ids'));
            }

            // Log activity
            ActivityLog::log(
                Constants::ACTIVITY_UPDATE,
                __('messages.user_updated_log', ['name' => $user->name]),
                $user,
                $oldValues,
                $user->fresh()->toArray()
            );

            DB::commit();

            return [
                'status' => true,
                'message' => __('messages.user_updated'),
                'data' => $user
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'status' => false,
                'message' => __('messages.operation_failed') . ': ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    public function destroy($user)
    {
        DB::beginTransaction();

        try {
            // Prevent deleting self
            if ($user->id === auth()->id()) {
                return [
                    'status' => false,
                    'message' => __('messages.cannot_delete_own_account'),
                    'data' => null
                ];
            }

            // Prevent deleting super admin
            if ($user->admin_group_id === Constants::SUPER_ADMIN_GROUP_ID) {
                return [
                    'status' => false,
                    'message' => __('messages.cannot_delete_super_admin'),
                    'data' => null
                ];
            }

            $userName = $user->name;
            $userId = $user->id;

            // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† ÙˆØ¬ÙˆØ¯ ÙÙˆØ§ØªÙŠØ± Ù…Ø±ØªØ¨Ø·Ø© Ø¨Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù…
            // Ø§Ø³ØªØ®Ø¯Ù… created_by Ø¨Ø¯Ù„Ø§Ù‹ Ù…Ù† user_id Ø¥Ø°Ø§ Ù„Ù… ÙŠÙƒÙ† Ù…ÙˆØ¬ÙˆØ¯Ø§Ù‹
            $hasInvoices = false;

            // ØªØ­Ù‚Ù‚ Ø£ÙˆÙ„Ø§Ù‹ Ø¥Ø°Ø§ ÙƒØ§Ù† Ø¹Ù…ÙˆØ¯ user_id Ù…ÙˆØ¬ÙˆØ¯ ÙÙŠ Ø¬Ø¯ÙˆÙ„ invoices
            if (Schema::hasColumn('invoices', 'user_id')) {
                // Ø§Ø³ØªØ®Ø¯Ù… user_id Ø¥Ø°Ø§ ÙƒØ§Ù† Ù…ÙˆØ¬ÙˆØ¯Ø§Ù‹
                $hasInvoices = Invoice::where('user_id', $user->id)->exists();
            } else {
                // Ø§Ø³ØªØ®Ø¯Ù… created_by Ø¥Ø°Ø§ Ù„Ù… ÙŠÙƒÙ† user_id Ù…ÙˆØ¬ÙˆØ¯Ø§Ù‹
                $hasInvoices = Invoice::where('created_by', $user->id)->exists();
            }

            if ($hasInvoices) {
                return [
                    'status' => false,
                    'message' => __('messages.cannot_delete_user_with_invoices'),
                    'data' => null
                ];
            }

            // ØªØ­Ù‚Ù‚ Ù…Ù† ÙˆØ¬ÙˆØ¯ Ø£Ù†Ø´Ø·Ø© Ù…Ø±ØªØ¨Ø·Ø© Ø¨Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù…
            if ($user->activities()->count() > 0) {
                // ÙŠÙ…ÙƒÙ†Ùƒ Ø­Ø°Ù Ø§Ù„Ø£Ù†Ø´Ø·Ø© Ø£ÙˆÙ„Ø§Ù‹ Ø£Ùˆ Ù…Ù†Ø¹ Ø§Ù„Ø­Ø°Ù
                $user->activities()->delete();
            }

            // Log activity before deletion
            ActivityLog::log(
                Constants::ACTIVITY_DELETE,
                __('messages.user_deleted_log', ['name' => $userName]),
                $user
            );

            // Delete user
            $user->delete();

            DB::commit();

            return [
                'status' => true,
                'message' => __('messages.user_deleted'),
                'data' => ['id' => $userId]
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'status' => false,
                'message' => __('messages.operation_failed') . ': ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    public function updateProfile($request)
    {
        try {
            $user = auth()->user();
            $oldValues = $user->toArray();

            $updateData = [
                'name' => $request->name,
                'phone' => $request->phone,
                'address' => $request->address,
                'company_name' => $request->company_name,
                'tax_number' => $request->tax_number
            ];

            // Update email if provided and different
            if ($request->has('email') && $request->email !== $user->email) {
                // Check if email already exists
                if (User::where('email', $request->email)->where('id', '!=', $user->id)->exists()) {
                    return [
                        'status' => false,
                        'message' => __('messages.email_already_registered'),
                        'data' => null
                    ];
                }
                $updateData['email'] = $request->email;
            }

            $user->update($updateData);

            // Log activity
            ActivityLog::log(
                'UPDATE_PROFILE',
                __('messages.profile_updated_log'),
                $user,
                $oldValues,
                $user->fresh()->toArray()
            );

            return [
                'status' => true,
                'message' => __('messages.profile_updated'),
                'data' => $user
            ];

        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => __('messages.operation_failed') . ': ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    public function changePassword($request)
    {
        try {
            $user = auth()->user();

            // Verify current password
            if (!Hash::check($request->current_password, $user->password)) {
                return [
                    'status' => false,
                    'message' => __('messages.password_invalid'),
                    'data' => null
                ];
            }

            // Update password
            $user->update([
                'password' => Hash::make($request->new_password)
            ]);

            // Log activity
            ActivityLog::log(
                'CHANGE_PASSWORD',
                __('messages.password_changed_log'),
                $user
            );

            return [
                'status' => true,
                'message' => __('messages.password_changed'),
                'data' => null
            ];

        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => __('messages.operation_failed') . ': ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    public function updateStatus($user, $status)
    {
        try {
            // Prevent deactivating self
            if ($user->id === auth()->id() && !$status) {
                return [
                    'status' => false,
                    'message' => __('messages.cannot_deactivate_own_account'),
                    'data' => null
                ];
            }

            $oldStatus = $user->is_active;
            $user->update(['is_active' => $status]);

            // Log activity
            ActivityLog::log(
                'UPDATE_STATUS',
                __('messages.user_status_updated_log', [
                    'name' => $user->name,
                    'old_status' => $oldStatus ? __('messages.active') : __('messages.inactive'),
                    'new_status' => $status ? __('messages.active') : __('messages.inactive')
                ]),
                $user
            );

            return [
                'status' => true,
                'message' => __('messages.user_status_updated'),
                'data' => $user
            ];

        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => __('messages.operation_failed') . ': ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    public function getStaffUsers()
    {
        try {
            $staff = User::with(['adminGroup', 'branches'])
                ->whereIn('admin_group_id', [Constants::SUPER_ADMIN_GROUP_ID, Constants::ADMIN_GROUP_ID])
                ->where('is_active', true)
                ->get();

            return [
                'status' => true,
                'message' => __('messages.staff_users_fetched'),
                'data' => $staff
            ];

        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => __('messages.error') . ': ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    public function getClientUsers()
    {
        try {
            $clients = User::with(['adminGroup', 'branches'])
                ->where('admin_group_id', Constants::CLIENT_GROUP_ID)
                ->where('is_active', true)
                ->get();

            return [
                'status' => true,
                'message' => __('messages.client_users_fetched'),
                'data' => $clients
            ];

        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => __('messages.error') . ': ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    private function syncBranches(User $user, $branchIds): void
    {
        $branchIds = array_values(array_unique(array_map('intval', $branchIds ?? [])));

        $sync = [];
        foreach ($branchIds as $i => $branchId) {
            $sync[$branchId] = ['is_default' => $i === 0];
        }

        $user->branches()->sync($sync);
    }

    private function syncProperties(User $user, $request): void
    {
        if (!$request->has('property_ids')) {
            return;
        }

        if (!\App\Helpers\PermissionHelper::checkPermission(Constants::ASSIGN_PROPERTIES)) {
            return;
        }

        $ids = array_values(array_unique(array_map('intval', (array) $request->input('property_ids', []))));

        $sync = [];
        foreach ($ids as $i => $id) {
            $sync[$id] = ['is_default' => $i === 0];
        }

        $user->properties()->sync($sync);
    }
}
