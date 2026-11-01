<?php
// يشغَّل عبر: php artisan tinker --execute="require base_path('tests/manual/my-tickets-smoke.php');"
// كل شيء داخل transaction ويتراجع في النهاية: لا بيانات تبقى ولا بث ولا إيميلات.

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

DB::beginTransaction();
try {
    $owner = User::first();
    $other = User::where('id', '!=', $owner->id)->first();

    $t = SupportTicket::create([
        'ticket_number' => 'TKT-SMOKE01', 'user_id' => $owner->id, 'name' => 'Smoke',
        'email' => 'smoke@example.com', 'subject' => 'smoke', 'message' => 'hello',
        'priority' => 'medium', 'status' => 'open',
    ]);
    SupportTicketReply::create(['ticket_id' => $t->id, 'user_id' => $owner->id, 'message' => 'public', 'is_admin_reply' => false, 'is_internal' => false]);
    SupportTicketReply::create(['ticket_id' => $t->id, 'user_id' => $owner->id, 'message' => 'SECRET-INTERNAL', 'is_admin_reply' => true, 'is_internal' => true]);

    [$c, $r] = $call(null, 'GET', "/api/support/my-tickets/{$t->id}");
    $check('no token -> 401', $c === 401, $c);

    [$c, $r] = $call($owner, 'GET', "/api/support/my-tickets/{$t->id}");
    $check('owner show -> 200', $c === 200, $c);
    $check('internal note hidden', !str_contains(json_encode($r), 'SECRET-INTERNAL'));
    $check('only 1 public reply', count($r['data']['replies'] ?? []) === 1, count($r['data']['replies'] ?? []));

    [$c, $r] = $call($other, 'GET', "/api/support/my-tickets/{$t->id}");
    $check('other user show -> 404', $c === 404, $c);

    [$c, $r] = $call($owner, 'GET', '/api/support/my-tickets');
    $items = $r['data']['items'] ?? [];
    $check('owner list has the ticket', collect($items)->contains('id', $t->id));
    $check('owner list only own tickets', collect($items)->every(fn ($i) => (int) $i['user_id'] === $owner->id));

    [$c, $r] = $call($other, 'GET', '/api/support/my-tickets');
    $check('other list lacks the ticket', !collect($r['data']['items'] ?? [])->contains('id', $t->id));

    [$c, $r] = $call($other, 'POST', "/api/support/my-tickets/{$t->id}/replies", ['message' => 'x']);
    $check('other user reply -> 404', $c === 404, $c);

    [$c, $r] = $call($owner, 'POST', "/api/support/my-tickets/{$t->id}/replies", ['message' => 'my reply', 'is_internal' => true]);
    $check('owner reply -> 200', $c === 200, $c . ' ' . json_encode($r));
    $check('reply forced non-internal', SupportTicketReply::where('message', 'my reply')->value('is_internal') == 0);
    $check('response hides internal note', !str_contains(json_encode($r), 'SECRET-INTERNAL'));

    $t->update(['status' => 'closed']);
    [$c, $r] = $call($owner, 'POST', "/api/support/my-tickets/{$t->id}/replies", ['message' => 'late']);
    $check('reply on closed -> 422', $c === 422, $c);
} catch (\Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . PHP_EOL;
}
DB::rollBack();
echo 'rolled back, nothing saved' . PHP_EOL;
