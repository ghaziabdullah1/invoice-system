<?php
// php artisan tinker --execute="require base_path('tests/manual/client-statement-smoke.php');"
// يقرأ بياناتك الحقيقية داخل transaction ويتراجع (التوكن المؤقت لا يبقى).
use App\Constants\Constants;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$call = function (?User $u, string $uri) {
    app('auth')->forgetGuards();
    $server = ['HTTP_ACCEPT' => 'application/json'];
    if ($u) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $u->createToken('smoke')->plainTextToken;
    }
    $res = app()->handle(Request::create($uri, 'GET', [], [], [], $server));
    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
};
$check = function (string $name, bool $ok, $extra = '') {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $name . ($ok ? '' : "   <-- $extra") . PHP_EOL;
};

DB::beginTransaction();
try {
    $admin = User::where('admin_group_id', Constants::SUPER_ADMIN_GROUP_ID)->first();
    $client = Client::whereHas('invoices')->first();
    if (!$admin || !$client) {
        echo 'SKIP: need a super admin and a client with invoices' . PHP_EOL;
    } else {
        $base = "/api/admin/clients/{$client->id}/statement";
        [$c] = $call(null, $base);
        $check('no token -> 401', $c === 401, $c);

        [$c, $r] = $call($admin, $base);
        $check('super admin -> 200', $c === 200, $c . ' ' . json_encode($r));
        $secs = $r['data']['sections'] ?? [];
        $check('has at least one currency section', count($secs) > 0, json_encode($r));

        $okMath = true;
        foreach ($secs as $s) {
            if (abs($s['opening'] + $s['total_debit'] - $s['total_credit'] - $s['closing']) > 0.001) $okMath = false;
        }
        $check('opening + debit - credit = closing', $okMath);

        $expected = (float) Invoice::where('client_id', $client->id)->whereNull('deleted_at')
            ->where('status', '!=', Constants::INVOICE_STATUS_DRAFT)
            ->where('status', '!=', Constants::INVOICE_STATUS_PAID)->sum('total');
        $got = array_sum(array_column($secs, 'closing'));
        $check('closing = unpaid non-draft invoices', abs($expected - $got) < 0.01, "expected $expected got $got");

        [$c, $r2] = $call($admin, $base . '?from=2099-01-01');
        $s2 = $r2['data']['sections'] ?? [];
        $check('future from -> no entries', collect($s2)->every(fn($s) => count($s['entries']) === 0), json_encode($s2));
        $check('future from -> opening equals full closing', abs(array_sum(array_column($s2, 'opening')) - $got) < 0.01);

        [$c] = $call($admin, $base . '?from=2026-05-01&to=2026-01-01');
        $check('to before from -> 422', $c === 422, $c);

        [$c] = $call($admin, '/api/admin/clients/99999999/statement');
        $check('unknown client -> 404', $c === 404, $c);
    }
} catch (\Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . PHP_EOL;
}
DB::rollBack();
echo 'rolled back, nothing saved' . PHP_EOL;
