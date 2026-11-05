<?php

namespace App\Repository\Admin\OtpLog;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

interface OtpLogInterface
{
    public function all(): Collection;

    public function paginate(Request $request): LengthAwarePaginator;
}
