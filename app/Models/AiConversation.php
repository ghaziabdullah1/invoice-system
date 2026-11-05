<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiConversation extends Model
{
    protected $fillable = ['channel', 'contact', 'client_id', 'status', 'last_message_at'];

    protected $casts = [
        'client_id'       => 'integer',
        'last_message_at' => 'datetime',
    ];

    public function messages()
    {
        return $this->hasMany(AiMessage::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
