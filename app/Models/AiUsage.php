<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsage extends Model
{
    protected $table = 'ai_usage';

    protected $fillable = ['usage_date', 'input_tokens', 'output_tokens', 'requests'];

    protected $casts = ['usage_date' => 'date'];

    public static function todayTokens(): int
    {
        $row = static::whereDate('usage_date', now()->toDateString())->first();

        return $row ? (int) ($row->input_tokens + $row->output_tokens) : 0;
    }

    public static function record(int $in, int $out): void
    {
        $row = static::firstOrCreate(['usage_date' => now()->toDateString()]);
        $row->increment('input_tokens', $in);
        $row->increment('output_tokens', $out);
        $row->increment('requests');
    }
}
