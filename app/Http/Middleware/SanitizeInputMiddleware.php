<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SanitizeInputMiddleware
{
    /**
     * ✅ تنظيف المدخلات من XSS قبل أي معالجة
     * ✅ لا تُطبق على Stripe Webhook (raw body)
     */
    public function handle(Request $request, Closure $next)
    {
        // ✅ استثناء Webhook Stripe (يحتاج raw body)
        if ($request->is('*/stripe/*') || $request->is('*/payments/webhook')) {
            return $next($request);
        }

        $input = $request->all();
        array_walk_recursive($input, function (&$value, $key) {
            // كلمات المرور تبقى كما أُدخلت: تعديلها يُضعفها (strip_tags يقطع ما بعد <) ويغيّر ما تُقارَن به
            if (!is_string($value) || $this->isPasswordField($key)) {
                return;
            }

            $value = strip_tags($value);
            $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        });
        $request->merge($input);

        return $next($request);
    }

    private function isPasswordField($key): bool
    {
        return is_string($key) && stripos($key, 'password') !== false;
    }
}
