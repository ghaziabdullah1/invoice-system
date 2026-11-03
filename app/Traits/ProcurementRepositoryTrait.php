<?php

namespace App\Traits;

use App\Constants\Constants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait ProcurementRepositoryTrait
{
    protected function ok($data = null, string $message = ''): array
    {
        return ['status' => true, 'message' => $message, 'data' => $data];
    }

    protected function fail($message, $code = Constants::RESPONSE_VALIDATION_ERROR): array
    {
        return ['status' => false, 'message' => $message, 'data' => null, 'code' => $code];
    }

    // يتراجع عن المعاملة الحالية ثم يرجع خطأ
    protected function bail($message, $code = Constants::RESPONSE_VALIDATION_ERROR): array
    {
        DB::rollBack();

        return $this->fail($message, $code);
    }

    protected function failure(\Throwable $e, string $where): array
    {
        Log::error('Procurement ' . $where . ' error', ['error' => $e->getMessage()]);
        $detail = config('app.debug') ? ' (' . $e->getMessage() . ')' : '';

        return $this->fail(__('procurement.server_error') . $detail, Constants::RESPONSE_SERVER_ERROR);
    }

    protected function pagination($page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
            'per_page'     => $page->perPage(),
            'total'        => $page->total(),
            'from'         => $page->firstItem(),
            'to'           => $page->lastItem(),
        ];
    }

    // ترقيم تسلسلي: PO-2026-0001 (أو SUP-0001 بلا سنة). يُستدعى داخل transaction. يشمل المحذوف منطقياً.
    protected function nextNumber(string $table, string $column, string $prefix, bool $withYear = true): string
    {
        $p = $prefix . '-' . ($withYear ? date('Y') . '-' : '');

        $last = DB::table($table)
            ->where($column, 'like', $p . '%')
            ->orderByDesc($column)
            ->lockForUpdate()
            ->value($column);

        $next = $last ? ((int) substr($last, strlen($p))) + 1 : 1;

        return $p . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
