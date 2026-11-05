<?php
// php artisan tinker --execute="require base_path('tests/manual/filters-real-values.php');"
// يختبر الفلاتر بقيم حقيقية ويقارن كل نتيجة بعدّ مستقل من قاعدة البيانات. داخل transaction.
use App\Constants\Constants;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('api', fn () => Limit::none());

$check = function (string $name, bool $ok, $extra = '') {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $name . ($ok ? '' : "   <-- $extra") . PHP_EOL;
};
$total = function ($j) {
    if (!is_array($j)) return null;
    $d = $j['data'] ?? null;
    if (is_array($d)) {
        if (isset($d['pagination']['total'])) return (int) $d['pagination']['total'];
        if (isset($d['total'])) return (int) $d['total'];
        if (array_is_list($d)) return count($d);
    }
    return null;
};

$admin = User::where('admin_group_id', Constants::SUPER_ADMIN_GROUP_ID)->first();
if (!$admin) { echo 'SKIP: no super admin user' . PHP_EOL; return; }

DB::beginTransaction();
try {
    $token = $admin->createToken('probe')->plainTextToken;
    $get = function (string $uri, array $q = []) use ($token, $total) {
        app('auth')->forgetGuards();
        $req = Request::create('/' . $uri, 'GET', $q, [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $res = app()->handle($req);
        return [$res->getStatusCode(), $total(json_decode($res->getContent(), true))];
    };

    echo '--- clients ---' . PHP_EOL;
    [$c, $all] = $get('api/admin/clients');
    $check('clients reachable', $c === 200 && $all !== null, "$c");
    [, $a] = $get('api/admin/clients', ['status' => 'active']);
    [, $i] = $get('api/admin/clients', ['status' => 'inactive']);
    $check('clients: active + inactive = all', $a + $i === $all, "$a + $i vs $all");
    [, $n] = $get('api/admin/clients', ['date_from' => '2099-01-01']);
    $check('clients: date_from 2099 -> 0', $n === 0, $n);
    [, $n] = $get('api/admin/clients', ['date_to' => '1990-01-01']);
    $check('clients: date_to 1990 -> 0', $n === 0, $n);
    [, $n] = $get('api/admin/clients', ['date_from' => '1990-01-01', 'date_to' => '2099-12-31']);
    $check('clients: wide range -> all', $n === $all, "$n vs $all");

    echo '--- invoices (real statuses from your data) ---' . PHP_EOL;
    [, $allInv] = $get('api/admin/invoices');
    foreach (Invoice::query()->distinct()->pluck('status')->filter()->values() as $s) {
        [, $n] = $get('api/admin/invoices', ['status' => $s]);
        $exp = Invoice::where('status', $s)->count();
        $check("invoices: status=$s", $n === $exp, "endpoint $n, db $exp (all=$allInv): if equal to all, the status is not in ALLOWED_STATUSES");
    }

    echo '--- users ---' . PHP_EOL;
    [, $allU] = $get('api/admin/users');
    foreach (User::query()->distinct()->pluck('admin_group_id')->filter()->values() as $g) {
        [, $n] = $get('api/admin/users', ['group_id' => $g]);
        $exp = User::where('admin_group_id', $g)->count();
        $check("users: group_id=$g", $n === $exp, "endpoint $n, db $exp");
    }
    [, $a] = $get('api/admin/users', ['status' => 'active']);
    [, $i] = $get('api/admin/users', ['status' => 'inactive']);
    $check('users: active + inactive = all', $a + $i === $allU, "$a + $i vs $allU");
    [, $n] = $get('api/admin/users', ['date_from' => '2099-01-01']);
    $check('users: date_from 2099 -> 0', $n === 0, $n);
    $nm = (string) $admin->name;
    [, $n] = $get('api/admin/users', ['search' => substr($nm, 0, 3)]);
    $check('users: search by name prefix finds at least one', $n >= 1, $n);

    echo '--- permissions ---' . PHP_EOL;
    [$c] = $get('api/admin/permissions/all');
    $check('permissions/all -> 200 (was 500)', $c === 200, $c);
    [, $n] = $get('api/admin/permissions', ['search' => 'zzzz_no_match']);
    $check('permissions: search no match -> 0', $n === 0, $n);
} catch (\Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . PHP_EOL;
}
DB::rollBack();
echo 'rolled back, nothing saved' . PHP_EOL;
