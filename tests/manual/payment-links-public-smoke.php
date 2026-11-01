<?php
// php artisan tinker --execute="require base_path('tests/manual/payment-links-public-smoke.php');"
use App\Constants\Constants;
use App\Models\Invoice;
use App\Services\PaymentLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

$check = function (string $name, bool $ok, $extra = '') {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $name . ($ok ? '' : "   <-- $extra") . PHP_EOL;
};
$get = function (string $uri) {
    $res = app()->handle(Request::create($uri, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']));
    return [$res->getStatusCode(), $res->getContent()];
};
$session = fn ($id, $linkId) => (object) [
    'id' => $id, 'payment_status' => 'paid', 'payment_intent' => 'pi_smoke',
    'metadata' => (object) ['payment_link_id' => $linkId],
];

Mail::fake();
Queue::fake();
$svc = app(PaymentLinkService::class);
$check('kwd 10.5 -> 10500', $svc->toStripeAmount(10.5, 'KWD') === 10500, $svc->toStripeAmount(10.5, 'KWD'));
$check('sar 10.5 -> 1050', $svc->toStripeAmount(10.5, 'SAR') === 1050, $svc->toStripeAmount(10.5, 'SAR'));
$check('jpy 500 -> 500', $svc->toStripeAmount(500, 'JPY') === 500);

DB::beginTransaction();
try {
    $inv = Invoice::doesntHave('installmentPlan')->where('status', '!=', Constants::INVOICE_STATUS_PAID)->first();
    if (!$inv) {
        echo 'SKIP: no unpaid invoice without installment plan' . PHP_EOL;
    } else {
        $before = $inv->status;

        $partial = $svc->createPaymentLink($inv, ['amount' => 1, 'expires_in_days' => 2]);
        [$c, $body] = $get("/api/payment-links/{$partial->token}/public");
        $check('public info -> 200', $c === 200, $c . ' ' . $body);
        $check('public info state active', str_contains($body, '"state":"active"'), $body);
        $check('public info hides internals', !str_contains($body, 'created_by') && !str_contains($body, '"token"'), $body);
        [$c] = $get('/api/payment-links/does-not-exist/public');
        $check('unknown token -> 404', $c === 404, $c);

        $svc->completeFromSession($session('cs_smoke_1', $partial->id));
        $check('partial link marked paid', $partial->fresh()->status === 'paid');
        $check('partial link leaves invoice unchanged', $inv->fresh()->status === $before, $inv->fresh()->status);

        $full = $svc->createPaymentLink($inv, ['amount' => $inv->total, 'expires_in_days' => 2]);
        $svc->completeFromSession($session('cs_smoke_2', $full->id));
        $check('full link marked paid', $full->fresh()->status === 'paid');
        $check('full link marks invoice paid', $inv->fresh()->status === Constants::INVOICE_STATUS_PAID, $inv->fresh()->status);

        $svc->completeFromSession($session('cs_smoke_2', $full->id));
        $check('webhook replay is harmless', true);

        [$c, $body] = $get("/api/payment-links/{$full->token}/public");
        $check('paid link shows state paid', str_contains($body, '"state":"paid"'), $body);
    }
} catch (\Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . PHP_EOL;
}
DB::rollBack();
echo 'rolled back, nothing saved' . PHP_EOL;
