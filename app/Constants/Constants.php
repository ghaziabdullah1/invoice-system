<?php

namespace App\Constants;

class Constants
{
    const ACTIVE = 1;
    const INACTIVE = 0;
    const YES = 1;
    const NO = 0;

    const SUPER_ADMIN_GROUP_ID = 1;
    const ADMIN_GROUP_ID = 2;
    const ACCOUNTANT_GROUP_ID = 3;
    const SALES_GROUP_ID = 4;
    const CLIENT_GROUP_ID = 5;

    const VIEW_DASHBOARD = 'view_dashboard';
    const VIEW_INVOICES = 'view_invoices';
    const CREATE_INVOICE = 'create_invoice';
    const EDIT_INVOICE = 'edit_invoice';
    const DELETE_INVOICE = 'delete_invoice';
    const CREATE_INSTALLMENT = 'create_installment';
    const SEND_INVOICE = 'send_invoice';
    const DOWNLOAD_INVOICE = 'download_invoice';
    const VIEW_CLIENTS = 'view_clients';
    const CREATE_CLIENT = 'create_client';
    const EDIT_CLIENT = 'edit_client';
    const DELETE_CLIENT = 'delete_client';
    const MANAGE_BRANCHES = 'manage_branches';
    const VIEW_USERS = 'view_users';
    const CREATE_USER = 'create_user';
    const EDIT_USER = 'edit_user';
    const DELETE_USER = 'delete_user';
    const VIEW_ADMIN_GROUPS = 'view_admin_groups';
    const MANAGE_ADMIN_GROUPS = 'manage_admin_groups';
    const VIEW_REPORTS = 'view_reports';
    const EXPORT_REPORTS = 'export_reports';
    const MANAGE_PERMISSIONS = 'manage_permissions';
    const MANAGE_USER_GROUPS = 'manage_user_groups';
    const VIEW_PERMISSIONS = 'view_permissions';
    const RESPONSE_TOO_MANY_REQUESTS = 429;

    // Pagination
    const DEFAULT_PER_PAGE = 10;
    const MAX_PER_PAGE = 100;
    const MIN_PER_PAGE = 1;

    // Invoice Statuses
    const INVOICE_STATUS_DRAFT = 'draft';
    const INVOICE_STATUS_SENT = 'sent';
    const INVOICE_STATUS_PAID = 'paid';
    const INVOICE_STATUS_OVERDUE = 'overdue';
    const INVOICE_STATUS_CANCELLED = 'cancelled';

    // Invoice Payment Terms
    const PAYMENT_TERM_NET_7 = 'net_7';
    const PAYMENT_TERM_NET_15 = 'net_15';
    const PAYMENT_TERM_NET_30 = 'net_30';
    const PAYMENT_TERM_NET_60 = 'net_60';

    // Currency
    const CURRENCY_SAR = 'SAR';
    const CURRENCY_USD = 'USD';
    const CURRENCY_EUR = 'EUR';
    const CURRENCY_GBP = 'GBP';
    const CURRENCY_AED = 'AED';

    // Response Status Codes
    const RESPONSE_SUCCESS = 200;
    const RESPONSE_CREATED = 201;
    const RESPONSE_ACCEPTED = 202;
    const RESPONSE_NO_CONTENT = 204;
    const RESPONSE_BAD_REQUEST = 400;
    const RESPONSE_UNAUTHORIZED = 401;
    const RESPONSE_FORBIDDEN = 403;
    const RESPONSE_NOT_FOUND = 404;
    const RESPONSE_METHOD_NOT_ALLOWED = 405;
    const RESPONSE_VALIDATION_ERROR = 422;
    const RESPONSE_SERVER_ERROR = 500;

    // Activity Types
    const ACTIVITY_CREATE = 'CREATE';
    const ACTIVITY_UPDATE = 'UPDATE';
    const ACTIVITY_DELETE = 'DELETE';
    const ACTIVITY_LOGIN = 'LOGIN';
    const ACTIVITY_LOGOUT = 'LOGOUT';


    // Cache Times (in seconds)
    const CACHE_5_MINUTES = 300;
    const CACHE_10_MINUTES = 600;
    const CACHE_30_MINUTES = 1800;
    const CACHE_1_HOUR = 3600;
    const CACHE_1_DAY = 86400;

    // Date Formats
    const DATE_FORMAT = 'Y-m-d';
    const DATETIME_FORMAT = 'Y-m-d H:i:s';
    const TIME_FORMAT = 'H:i:s';

    // File Upload
    const MAX_FILE_SIZE = 2048; // 2MB in KB
    const ALLOWED_FILE_TYPES = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];

    // Validation Rules
    const PASSWORD_MIN_LENGTH = 8;
    const PHONE_MAX_LENGTH = 20;
    const NAME_MAX_LENGTH = 255;
    const EMAIL_MAX_LENGTH = 255;

    // System Settings
    const SYSTEM_NAME_EN = 'Invoice System';
    const SYSTEM_NAME_AR = 'نظام الفواتير';
    const SYSTEM_VERSION = '1.0.0';

    // Notification Types
    const NOTIFICATION_INVOICE_CREATED = 'invoice_created';
    const NOTIFICATION_INVOICE_PAID = 'invoice_paid';
    const NOTIFICATION_INVOICE_OVERDUE = 'invoice_overdue';
    const NOTIFICATION_CLIENT_CREATED = 'client_created';

    // Report Types
    const REPORT_INVOICES = 'invoices';
    const REPORT_CLIENTS = 'clients';
    const REPORT_REVENUE = 'revenue';
    const REPORT_PAYMENTS = 'payments';

    // Export Formats
    const EXPORT_PDF = 'pdf';
    const EXPORT_EXCEL = 'excel';
    const EXPORT_CSV = 'csv';
    const CACHE_TTL_INVOICES = 600; // 10 minutes

    // Payment Statuses (بجانب Invoice Statuses)
    const PAYMENT_STATUS_PENDING = 'pending';
    const PAYMENT_STATUS_COMPLETED = 'completed';
    const PAYMENT_STATUS_FAILED = 'failed';
    const PAYMENT_STATUS_REFUNDED = 'refunded';

    // Payment Methods
    const PAYMENT_METHOD_CARD = 'card';
    const PAYMENT_METHOD_BANK_TRANSFER = 'bank_transfer';
    const PAYMENT_METHOD_CASH = 'cash';

    // Permissions (بجانب الصلاحيات الحالية)
    const CREATE_PAYMENT = 'create_payment';
    const VIEW_PAYMENTS = 'view_payments';
    const REFUND_PAYMENT = 'refund_payment';
    const VIEW_OTP_LOGS = 'view_otp_logs';
    const VIEW_SUPPORT_TICKETS = 'view_support_tickets';
    const REPLY_SUPPORT_TICKET = 'reply_support_ticket';
    const EDIT_SUPPORT_TICKET_STATUS = 'edit_support_ticket_status';
    const DELETE_SUPPORT_TICKET = 'delete_support_ticket';
    const REFUND_PAYMENTS = 'refund_payments';

    // Response Messages (أضف في قسم messages)
    const MESSAGE_PAYMENT_SESSION_CREATED = 'payment_session_created';
    const MESSAGE_PAYMENT_SUCCESS = 'payment_success';
    const MESSAGE_PAYMENT_FAILED = 'payment_failed';
    const MESSAGE_PAYMENT_CANCELLED = 'payment_cancelled';


    const INVOICE_STATUS_UNPAID = 'unpaid';
    const INVOICE_STATUS_PENDING = 'pending';

    // ═══════════════════════════════════════════════════════════════
    // Property & Facility Management Module
    // ═══════════════════════════════════════════════════════════════
    const VIEW_PROPERTIES = 'view_properties';
    const CREATE_PROPERTY = 'create_property';
    const EDIT_PROPERTY = 'edit_property';
    const DELETE_PROPERTY = 'delete_property';

    const VIEW_FLOORS = 'view_floors';
    const CREATE_FLOOR = 'create_floor';
    const EDIT_FLOOR = 'edit_floor';
    const DELETE_FLOOR = 'delete_floor';

    const VIEW_UNITS = 'view_units';
    const CREATE_UNIT = 'create_unit';
    const EDIT_UNIT = 'edit_unit';
    const DELETE_UNIT = 'delete_unit';

    const VIEW_TENANTS = 'view_tenants';
    const CREATE_TENANT = 'create_tenant';
    const EDIT_TENANT = 'edit_tenant';
    const DELETE_TENANT = 'delete_tenant';


    // ═══════════════════════════════════════════════════════════════
    // Contracts
    // ═══════════════════════════════════════════════════════════════
    const VIEW_CONTRACTS = 'view_contracts';
    const CREATE_CONTRACT = 'create_contract';
    const EDIT_CONTRACT = 'edit_contract';
    const DELETE_CONTRACT = 'delete_contract';
    const RENEW_CONTRACT = 'renew_contract';
    const TERMINATE_CONTRACT = 'terminate_contract';

    const CONTRACT_STATUS_DRAFT = 'draft';
    const CONTRACT_STATUS_ACTIVE = 'active';
    const CONTRACT_STATUS_EXPIRED = 'expired';
    const CONTRACT_STATUS_TERMINATED = 'terminated';
    const CONTRACT_STATUS_RENEWED = 'renewed';

    const PAYMENT_FREQUENCY_MONTHLY = 'monthly';
    const PAYMENT_FREQUENCY_QUARTERLY = 'quarterly';
    const PAYMENT_FREQUENCY_SEMI_ANNUAL = 'semi_annual';
    const PAYMENT_FREQUENCY_ANNUAL = 'annual';

    const VIEW_ACTIVITY_LOGS = 'view_activity_logs';
}
