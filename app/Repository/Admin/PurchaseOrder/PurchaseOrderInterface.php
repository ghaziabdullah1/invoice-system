<?php

namespace App\Repository\Admin\PurchaseOrder;

interface PurchaseOrderInterface
{
    public function index($request);
    public function show($id);
    public function store($request);
    public function update($request, $order);
    public function destroy($order);
    public function submit($order);
    public function approve($order);
    public function reject($request, $order);
    public function send($order);
    public function receive($request, $order);
    public function cancel($request, $order);
}
