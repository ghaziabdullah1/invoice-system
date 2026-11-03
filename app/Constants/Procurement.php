<?php

namespace App\Constants;

class Procurement
{
    const VIEW_SUPPLIERS   = 'view_suppliers';
    const CREATE_SUPPLIER  = 'create_supplier';
    const EDIT_SUPPLIER    = 'edit_supplier';
    const DELETE_SUPPLIER  = 'delete_supplier';

    const VIEW_PO    = 'view_purchase_orders';
    const CREATE_PO  = 'create_purchase_order';
    const EDIT_PO    = 'edit_purchase_order';
    const DELETE_PO  = 'delete_purchase_order';
    const APPROVE_PO = 'approve_purchase_order';
    const SEND_PO    = 'send_purchase_order';
    const RECEIVE_PO = 'receive_purchase_order';
    const CANCEL_PO  = 'cancel_purchase_order';

    const STATUS_DRAFT              = 'draft';
    const STATUS_PENDING_APPROVAL   = 'pending_approval';
    const STATUS_APPROVED           = 'approved';
    const STATUS_REJECTED           = 'rejected';
    const STATUS_SENT               = 'sent';
    const STATUS_PARTIALLY_RECEIVED = 'partially_received';
    const STATUS_RECEIVED           = 'received';
    const STATUS_CANCELLED          = 'cancelled';

    const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_SENT,
        self::STATUS_PARTIALLY_RECEIVED,
        self::STATUS_RECEIVED,
        self::STATUS_CANCELLED,
    ];

    const EDITABLE_STATUSES   = [self::STATUS_DRAFT, self::STATUS_REJECTED];
    const RECEIVABLE_STATUSES = [self::STATUS_APPROVED, self::STATUS_SENT, self::STATUS_PARTIALLY_RECEIVED];
    const OPEN_STATUSES       = [self::STATUS_APPROVED, self::STATUS_SENT, self::STATUS_PARTIALLY_RECEIVED];
    const CANCELLABLE_STATUSES = [
        self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL, self::STATUS_APPROVED,
        self::STATUS_REJECTED, self::STATUS_SENT,
    ];
    const DELETABLE_STATUSES  = [self::STATUS_DRAFT, self::STATUS_REJECTED, self::STATUS_CANCELLED];
}
