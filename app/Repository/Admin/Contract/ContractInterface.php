<?php

namespace App\Repository\Admin\Contract;

interface ContractInterface
{
    public function index($request);
    public function show($id);
    public function store($request);
    public function update($request, $contract);
    public function destroy($contract);
    public function renew($request, $contract);
    public function terminate($request, $contract);
    public function uploadDocument($file, $contract);
    public function document($contract);
}
