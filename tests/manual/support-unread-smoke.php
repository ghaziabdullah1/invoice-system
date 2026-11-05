<?php
// php artisan tinker --execute="require base_path('tests/manual/support-unread-smoke.php');"
// داخل transaction ويتراجع. الأحداث مُعطّلة فلا بث ولا إيميلات.
use App\Events\SupportMessageSent;
use App\Events\SupportTicketCreated;
use App\Events\SupportTicketUpdated;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

$call = function (?User $u, string $method, string $uri, array $body = []) {
    app('auth')->forgetGuards();
    $server = ['HTTP_ACCEPT' => 'application/json'];
    if ($u) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $u->createToken('smoke')->plainTextToken;
    }
    $res = app()->handle(Request::create($uri, $method, $body, [], [], $server));
    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
};
$check = function (string $name, bool $ok, $extra = '') {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $name . ($ok ? '' : "   <-- $extra") . PHP_EOL;
};
$add = function (SupportTicket $t, User $u, string $msg, bool $admin, bool $internal, $at = null) {
    $r = new SupportTicketReply(['ticket_id' => $t->id, 'user_id' => $u->id, 'message' => $msg, 'is_admin_reply' => $admin, 'is_internal' => $internal]);
    if ($at) $r->created_at = $at;
    $r->save();
    return $r;
};

Event::fake([SupportMessageSent::class, SupportTicketCreated::class, SupportTicketUpdated::class]);
DB::beginTransaction();
try {
    $owner = User::first();
    $other = User::where('id', '!=', $owner->id)->first();

    [$c, $r] = $call($owner, 'GET', '/api/support/my-tickets/unread-count');
    $check('unread-count endpoint -> 200', $c === 200, $c . ' ' . json_encode($r));
    $base = (int) ($r['data']['count'] ?? 0);

    $t = SupportTicket::create([
        'ticket_number' => 'TKT-UNREAD1', 'user_id' => $owner->id, 'name' => 'Smoke', 'email' => 'smoke@example.com',
        'subject' => 'smoke', 'message' => 'hello', 'priority' => 'medium', 'status' => 'open',
    ]);
    $add($t, $owner, 'my message', false, false);
    $add($t, $owner, 'staff public', true, false);
    $add($t, $owner, 'staff INTERNAL', true, true);

    [$c, $r] = $call($owner, 'GET', '/api/support/my-tickets/unread-count');
    $check('count +1 (internal and own messages not counted)', ($r['data']['count'] ?? -1) === $base + 1, json_encode($r));

    [$c, $r] = $call($owner, 'GET', '/api/support/my-tickets');
    $mine = collect($r['data']['items'] ?? [])->firstWhere('id', $t->id);
    $check('list shows unread_count = 1', ($mine['unread_count'] ?? -1) === 1, json_encode($mine));

    [$c] = $call($other, 'POST', "/api/support/my-tickets/{$t->id}/read");
    $check('other user read -> 404', $c === 404, $c);

    [$c] = $call($owner, 'POST', "/api/support/my-tickets/{$t->id}/read");
    $check('owner read -> 200', $c === 200, $c);
    [$c, $r] = $call($owner, 'GET', '/api/support/my-tickets/unread-count');
    $check('count back to baseline after read', ($r['data']['count'] ?? -1) === $base, json_encode($r));

    $add($t, $owner, 'staff public 2', true, false, now()->addMinutes(5));
    [$c, $r] = $call($owner, 'GET', '/api/support/my-tickets/unread-count');
    $check('new staff reply after read -> +1', ($r['data']['count'] ?? -1) === $base + 1, json_encode($r));

    [$c] = $call($owner, 'POST', "/api/support/my-tickets/{$t->id}/replies", ['message' => 'thanks']);
    $check('customer reply -> 200', $c === 200, $c);
    [$c, $r] = $call($owner, 'GET', '/api/support/my-tickets/unread-count');
    $check('customer reply marks read', ($r['data']['count'] ?? -1) === $base, json_encode($r));

    [$c] = $call(null, 'GET', '/api/support/my-tickets/unread-count');
    $check('no token -> 401', $c === 401, $c);
} catch (\Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . PHP_EOL;
}
DB::rollBack();
echo 'rolled back, nothing saved' . PHP_EOL;
