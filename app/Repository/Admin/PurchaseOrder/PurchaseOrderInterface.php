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
    public function approve($request, $order);
    public function reject($request, $order);
    public function send($order);
    public function receive($request, $order);
    public function cancel($request, $order);

    // المرفقات والتوقيعات
    public function attachments($order);
    public function uploadAttachment($file, $order);
    public function deleteAttachmentForOrder($order, int $attachmentId);
}
