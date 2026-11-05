<?php
// php artisan tinker --execute="require base_path('tests/manual/filters-probe.php');"
// يستدعي كل قوائم الباك إند بقيم مستحيلة. إن لم يتغير العدد فالفلتر مُتجاهَل.
use App\Constants\Constants;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

RateLimiter::for('api', fn () => Limit::none());

$ui = [
    'clients'            => ['status', 'search', 'date_from', 'date_to'],
    'invoices'           => ['status', 'date_from', 'date_to', 'search'],
    'payments'           => ['status', 'date_from', 'date_to', 'search'],
    'permissions'        => ['search', 'status', 'menu_id', 'parent_id'],
    'users'              => ['search'],
    'recurring-invoices' => ['status', 'client_id'],
    'support/tickets'    => ['status', 'priority', 'search'],
];
$probes = [
    'search' => 'zzzz_no_match_zzzz', 'status' => '__none__', 'date_from' => '2099-01-01', 'date_to' => '1990-01-01',
    'start_date' => '2099-01-01', 'priority' => '__none__', 'client_id' => 999999999, 'user_id' => 999999999,
    'menu_id' => 999999999, 'parent_id' => 999999999, 'action' => '__none__',
];

$total = function ($j) {
    if (!is_array($j)) return null;
    $d = $j['data'] ?? null;
    if (is_array($d)) {
        if (isset($d['pagination']['total'])) return (int) $d['pagination']['total'];
        if (isset($d['total'])) return (int) $d['total'];
        if (isset($d['meta']['total'])) return (int) $d['meta']['total'];
        if (array_is_list($d)) return count($d);
    }
    if (isset($j['pagination']['total'])) return (int) $j['pagination']['total'];
    return null;
};

$admin = User::where('admin_group_id', Constants::SUPER_ADMIN_GROUP_ID)->first();
if (!$admin) { echo 'SKIP: no super admin user' . PHP_EOL; return; }

$uris = [];
foreach (Route::getRoutes() as $r) {
    if (!in_array('GET', $r->methods())) continue;
    $u = $r->uri();
    if (!str_starts_with($u, 'api/admin/') || str_contains($u, '{')) continue;
    if (preg_match('/export|download|exported-files|dashboard|notification|search\/all|recent/', $u)) continue;
    $uris[$u] = true;
}
$uris = array_keys($uris);
sort($uris);

DB::beginTransaction();
try {
    $token = $admin->createToken('probe')->plainTextToken;
    $call = function (string $uri, array $q) use ($token) {
        app('auth')->forgetGuards();
        $req = Request::create('/' . $uri, 'GET', $q, [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $res = app()->handle($req);
        return [$res->getStatusCode(), json_decode($res->getContent(), true), $res->getContent()];
    };

    $dead = [];
    $skipped = [];
    foreach ($uris as $uri) {
        [$c, $j, $raw] = $call($uri, []);
        if ($c !== 200) { $skipped[] = "$uri -> HTTP $c " . mb_substr(preg_replace('/\s+/', ' ', $raw), 0, 90); continue; }
        $base = $total($j);
        if ($base === null) continue;
        if ($base === 0) { $skipped[] = "$uri -> EMPTY (no rows, cannot test)"; continue; }

        $res = [];
        foreach ($probes as $k => $v) {
            [$c2, $j2] = $call($uri, [$k => $v]);
            if ($c2 === 422) { $res[$k] = 'OK(422)'; continue; }
            if ($c2 !== 200) { $res[$k] = 'ERR' . $c2; continue; }
            $res[$k] = ($total($j2) !== $base) ? 'OK' : 'NO-EFFECT';
        }

        $short = substr($uri, strlen('api/admin/'));
        $expect = null;
        foreach ($ui as $name => $keys) {
            if ($short === $name) $expect = $keys;
        }
        if ($expect === null && str_contains($short, 'activity')) $expect = ['search', 'action', 'user_id', 'date_from', 'date_to'];

        if ($expect !== null) {
            $parts = [];
            foreach ($expect as $k) {
                $r = $res[$k] ?? 'n/a';
                $parts[] = "$k=$r";
                if ($r === 'NO-EFFECT') $dead[] = "$short : $k";
            }
            echo str_pad($short, 26) . "(rows=$base)  UI sends -> " . implode('  ', $parts) . PHP_EOL;
        } else {
            $ok = array_keys(array_filter($res, fn ($x) => str_starts_with($x, 'OK')));
            echo str_pad($short, 26) . "(rows=$base)  supports -> " . ($ok ? implode(', ', $ok) : '(none of the probed keys)') . PHP_EOL;
        }
    }
    echo PHP_EOL . '--- DEAD FILTERS (the UI sends them, the backend ignores them) ---' . PHP_EOL;
    echo $dead ? implode(PHP_EOL, $dead) : 'none' . PHP_EOL;
    echo PHP_EOL . '--- NOT TESTED ---' . PHP_EOL . implode(PHP_EOL, $skipped) . PHP_EOL;
} catch (\Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . PHP_EOL;
}
DB::rollBack();
echo 'rolled back, nothing saved' . PHP_EOL;
