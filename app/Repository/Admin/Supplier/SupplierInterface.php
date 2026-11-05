<?php

namespace App\Repository\Admin\Supplier;

interface SupplierInterface
{
    public function index($request);
    public function show($id);
    public function store($request);
    public function update($request, $supplier);
    public function destroy($supplier);
}
