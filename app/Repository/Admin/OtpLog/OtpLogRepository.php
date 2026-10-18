<?php

namespace App\Repository\Admin\OtpLog;

use App\Constants\Constants;
use App\Models\OtpLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class OtpLogRepository implements OtpLogInterface
{
    public function all(): Collection
    {
        return OtpLog::with('user:id,name,email')
            ->orderByDesc('created_at')
            ->get();
    }

    public function paginate(Request $request): LengthAwarePaginator
    {
        $query = OtpLog::with('user:id,name,email')
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', '%' . $search . '%')
                    ->orWhere('ip_address', 'like', '%' . $search . '%');
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $perPage = min((int) ($request->per_page ?: Constants::DEFAULT_PER_PAGE), 100);

        return $query->paginate($perPage);
    }
}
