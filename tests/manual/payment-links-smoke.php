<?php
// php artisan tinker --execute="require base_path('tests/manual/payment-links-smoke.php');"
// داخل transaction ويتراجع في النهاية. لا يرسل بريداً (واتساب وsms فقط).
use App\Models\Invoice;
use App\Services\PaymentLinkService;
use Illuminate\Support\Facades\DB;

$check = function (string $name, bool $ok, $extra = '') {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $name . ($ok ? '' : "   <-- $extra") . PHP_EOL;
};
DB::beginTransaction();
try {
    $inv = Invoice::first();
    if (!$inv) {
        echo 'no invoices in DB' . PHP_EOL;
    } else {
        $svc = app(PaymentLinkService::class);
        $link = $svc->createPaymentLink($inv, ['amount' => 10, 'expires_in_days' => 2]);
        $arr = $link->fresh()->toArray();
        $check('public_url present in JSON', !empty($arr['public_url']), json_encode($arr['public_url'] ?? null));
        $check('public_url contains token', str_contains($arr['public_url'] ?? '', $link->token));
        $r = $svc->sendLinkToClient($link, 'whatsapp');
        $check('whatsapp returns wa.me url', $r['status'] && str_contains($r['data']['whatsapp_url'] ?? '', 'wa.me'), json_encode($r));
        $r = $svc->sendLinkToClient($link, 'sms');
        $check('sms refused', $r['status'] === false, json_encode($r));
        $check('validateLink finds active link', $svc->validateLink($link->token)?->id === $link->id);
    }
} catch (\Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . PHP_EOL;
}
DB::rollBack();
echo 'rolled back, nothing saved' . PHP_EOL;
