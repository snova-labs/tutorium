<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** An editable transactional email. */
final class EmailTemplate extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'brand_id', 'code', 'subject', 'body_html', 'variables', 'locale'];

    protected function casts(): array
    {
        return ['variables' => 'array'];
    }

    /** @param array<string, string> $values */
    public function render(array $values): array
    {
        $replace = static function (string $text) use ($values): string {
            foreach ($values as $key => $value) {
                $text = str_replace('{'.$key.'}', $value, $text);
            }

            return $text;
        };

        return ['subject' => $replace($this->subject), 'body' => $replace($this->body_html)];
    }

    public function auditModule(): string
    {
        return 'Settings';
    }
}
