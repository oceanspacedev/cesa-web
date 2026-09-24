<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\CustomerPanelProvider;
use Cesa\Document\DocumentServiceProvider;
use Cesa\ExitClearance\ExitClearanceServiceProvider;
use Cesa\FormTransfer\FormTransferServiceProvider;
use Cesa\Helpdesk\HelpdeskServiceProvider;
use Cesa\IdCard\IdCardServiceProvider;
use Cesa\Kepegawaian\KepegawaianServiceProvider;
use Cesa\Lead\LeadServiceProvider;
use Cesa\LegacySync\LegacySyncServiceProvider;
use Cesa\Padelnis\PadelnisServiceProvider;
use Cesa\Payroll\PayrollServiceProvider;
use Cesa\Presensi\PresensiServiceProvider;
use Cesa\Rekrutmen\RekrutmenServiceProvider;
use Cesa\Shelf\ShelfServiceProvider;
use Cesa\Waste\WasteServiceProvider;
use Webkul\Account\AccountServiceProvider;
use Webkul\Accounting\AccountingServiceProvider;
use Webkul\Analytic\AnalyticServiceProvider;
use Webkul\Blog\BlogServiceProvider;
use Webkul\Chatter\ChatterServiceProvider;
use Webkul\Contact\ContactServiceProvider;
use Webkul\Employee\EmployeeServiceProvider;
use Webkul\Field\FieldServiceProvider;
use Webkul\FullCalendar\FullCalendarServiceProvider;
use Webkul\Inventory\InventoryServiceProvider;
use Webkul\Invoice\InvoiceServiceProvider;
use Webkul\Partner\PartnerServiceProvider;
use Webkul\Payment\PaymentServiceProvider;
use Webkul\PluginManager\PluginManagerServiceProvider;
use Webkul\Product\ProductServiceProvider;
use Webkul\Project\ProjectServiceProvider;
use Webkul\Purchase\PurchaseServiceProvider;
use Webkul\Recruitment\RecruitmentServiceProvider;
use Webkul\Sale\SaleServiceProvider;
use Webkul\Security\SecurityServiceProvider;
use Webkul\Support\SupportServiceProvider;
use Webkul\TableViews\TableViewsServiceProvider;
use Webkul\TimeOff\TimeOffServiceProvider;
use Webkul\Timesheet\TimesheetServiceProvider;
use Webkul\Website\WebsiteServiceProvider;

return [
    // App Providers
    AppServiceProvider::class,
    AdminPanelProvider::class,
    CustomerPanelProvider::class,

    // Core Plugins — included with `php artisan erp:install`, no separate install needed
    AnalyticServiceProvider::class,
    ChatterServiceProvider::class,
    FieldServiceProvider::class,
    PartnerServiceProvider::class,
    SecurityServiceProvider::class,
    SupportServiceProvider::class,
    TableViewsServiceProvider::class,
    PluginManagerServiceProvider::class,

    // Installable Plugins — each requires `php artisan [module]:install`
    // AccountingServiceProvider::class,
    // AccountServiceProvider::class,
    // BlogServiceProvider::class,
    // ContactServiceProvider::class,
    // EmployeeServiceProvider::class,
    // FullCalendarServiceProvider::class,
    // InventoryServiceProvider::class,
    // InvoiceServiceProvider::class,
    // PaymentServiceProvider::class,
    // ProductServiceProvider::class,
    // ProjectServiceProvider::class,
    // PurchaseServiceProvider::class,
    // RecruitmentServiceProvider::class,
    // SaleServiceProvider::class,
    // TimeOffServiceProvider::class,
    // TimesheetServiceProvider::class,
    // WebsiteServiceProvider::class,

    // CESA Plugins
    DocumentServiceProvider::class,
    ExitClearanceServiceProvider::class,
    FormTransferServiceProvider::class,
    HelpdeskServiceProvider::class,
    IdCardServiceProvider::class,
    KepegawaianServiceProvider::class,
    LeadServiceProvider::class,
    PadelnisServiceProvider::class,
    PayrollServiceProvider::class,
    PresensiServiceProvider::class,
    RekrutmenServiceProvider::class,
    ShelfServiceProvider::class,
    WasteServiceProvider::class,
    LegacySyncServiceProvider::class,
];
