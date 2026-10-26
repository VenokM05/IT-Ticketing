<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppTemplate extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'name',
        'language',
        'category',
        'approval_status',
        'body',
        'notification_type',
    ];

    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }
}
