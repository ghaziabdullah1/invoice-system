<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Invoice;
use App\Observers\InvoiceObserver;
use App\Repository\Admin\Invoice\InvoiceInterface;
use App\Repository\Admin\Invoice\InvoiceRepository;
use App\Repository\Admin\Client\ClientInterface;
use App\Repository\Admin\Client\ClientRepository;
use App\Repository\Admin\User\UserInterface;
use App\Repository\Admin\User\UserRepository;
use App\Repository\Admin\AdminGroup\AdminGroupInterface;
use App\Repository\Admin\AdminGroup\AdminGroupRepository;
use App\Repository\Admin\Report\ReportInterface;
use App\Repository\Admin\Report\ReportRepository;
use App\Repository\Admin\Permission\PermissionInterface;
use App\Repository\Admin\Permission\PermissionRepository;
use App\Repository\Admin\Payment\PaymentInterface;
use App\Repository\Admin\Payment\PaymentRepository;
use App\Repository\Admin\ActivityLog\ActivityLogInterface;
use App\Repository\Admin\ActivityLog\ActivityLogRepository;
use App\Repository\Admin\OtpLog\OtpLogInterface;
use App\Repository\Admin\OtpLog\OtpLogRepository;
use App\Repository\Admin\Dashboard\DashboardInterface;
use App\Repository\Admin\Dashboard\DashboardRepository;
use App\Repository\Admin\InstallmentPlan\InstallmentPlanInterface;
use App\Repository\Admin\InstallmentPlan\InstallmentPlanRepository;
use App\Repository\Admin\InstallmentInterestTier\InstallmentInterestTierInterface;
use App\Repository\Admin\InstallmentInterestTier\InstallmentInterestTierRepository;
use App\Repository\Admin\SupportTicket\SupportTicketInterface;
use App\Repository\Admin\SupportTicket\SupportTicketRepository;
use App\Repository\Admin\RecurringInvoice\RecurringInvoiceInterface;
use App\Repository\Admin\RecurringInvoice\RecurringInvoiceRepository;
use App\Repository\Admin\Property\PropertyInterface;
use App\Repository\Admin\Property\PropertyRepository;
use App\Repository\Admin\Floor\FloorInterface;
use App\Repository\Admin\Floor\FloorRepository;
use App\Repository\Admin\Unit\UnitInterface;
use App\Repository\Admin\Unit\UnitRepository;
use App\Repository\Admin\Tenant\TenantInterface;
use App\Repository\Admin\Tenant\TenantRepository;
use App\Repository\Admin\Contract\ContractInterface;
use App\Repository\Admin\Contract\ContractRepository;
use App\Repository\Admin\PropertySummary\PropertySummaryInterface;
use App\Repository\Admin\PropertySummary\PropertySummaryRepository;

use App\Services\ExportService;
use App\Http\Kernel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\URL;
use App\Constants\Constants;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Bind Repositories
        $this->app->bind(InvoiceInterface::class, InvoiceRepository::class);
        $this->app->bind(ClientInterface::class, ClientRepository::class);
        $this->app->bind(UserInterface::class, UserRepository::class);
        $this->app->bind(AdminGroupInterface::class, AdminGroupRepository::class);
        $this->app->bind(ReportInterface::class, ReportRepository::class);
        $this->app->bind(PermissionInterface::class, PermissionRepository::class);
        $this->app->bind(PaymentInterface::class, PaymentRepository::class);
        $this->app->bind(ActivityLogInterface::class, ActivityLogRepository::class);
        $this->app->bind(OtpLogInterface::class, OtpLogRepository::class);
        $this->app->bind(DashboardInterface::class, DashboardRepository::class);
        $this->app->bind(InstallmentPlanInterface::class, InstallmentPlanRepository::class);
        $this->app->bind(InstallmentInterestTierInterface::class, InstallmentInterestTierRepository::class);
        $this->app->bind(SupportTicketInterface::class, SupportTicketRepository::class);
        $this->app->bind(RecurringInvoiceInterface::class, RecurringInvoiceRepository::class);
        $this->app->bind(PropertyInterface::class, PropertyRepository::class);
        $this->app->bind(FloorInterface::class, FloorRepository::class);
        $this->app->bind(UnitInterface::class, UnitRepository::class);
        $this->app->bind(TenantInterface::class, TenantRepository::class);
        $this->app->bind(ContractInterface::class, ContractRepository::class);
        $this->app->bind(PropertySummaryInterface::class, PropertySummaryRepository::class);

        // Bind Services
        $this->app->singleton(ExportService::class, function ($app) {
            return new ExportService();
        });

        // Bind Exports
        $this->app->bind(\App\Exports\InvoiceReportExport::class);
        $this->app->bind(\App\Exports\ClientReportExport::class);
        $this->app->bind(\App\Exports\RevenueReportExport::class);
        $this->app->bind(\App\Exports\OverdueReportExport::class);
    }

    public function boot()
    {
        Invoice::observe(InvoiceObserver::class);

        // ✅ تسجيل Rate Limiters (Login, API, Exports, Password Reset)
        // ملاحظة: هذا الاستدعاء يبقى صحيحاً رغم أن Kernel.php لم يعد
        // الـ HTTP Kernel الفعلي في Laravel 11 — لأنه استدعاء ثابت
        // (static method call) مستقل تماماً عن دورة حياة الـ Kernel،
        // وليس تسجيل middleware يعتمد على تحميل الملف كـ Kernel حقيقي.
        Kernel::configureRateLimiting();

        // ✅ فرض HTTPS في بيئة الإنتاج — بدون هذا، أي رابط مولّد
        // بواسطة url()/route() قد يُبنى بـ http:// خلف load balancer
        // أو proxy، ويُعتبر ثغرة إفصاح بروتوكول غير آمن.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Register validation rules
        Validator::extend('valid_currency', function ($attribute, $value, $parameters, $validator) {
            $validCurrencies = [
                Constants::CURRENCY_USD,
                Constants::CURRENCY_EUR,
                Constants::CURRENCY_GBP,
                Constants::CURRENCY_SAR,
                Constants::CURRENCY_AED
            ];

            return in_array($value, $validCurrencies);
        });

        Validator::replacer('valid_currency', function ($message, $attribute, $rule, $parameters) {
            return str_replace(':attribute', $attribute, 'العملة المحددة غير صالحة.');
        });

        RateLimiter::for('support-ticket-create', function ($request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        RateLimiter::for('support-ticket-track', function ($request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Set global pagination limit
        \Illuminate\Pagination\Paginator::useBootstrap();
    }
}
