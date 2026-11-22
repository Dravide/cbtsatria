<?php

namespace App\Domain\Event\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'event_id',
    'exam_session_id',
    'exam_session_room_id',
    'jenis',
    'tingkat',
    'judul',
    'pesan',
    'konteks_label',
    'tautan',
    'read_at',
])]
class EventNotification extends Model
{
    use BelongsToSchool;

    public const JENIS_TERKUNCI = 'terkunci';

    public const JENIS_PELANGGARAN = 'pelanggaran';

    public const JENIS_TERPUTUS = 'terputus_massal';

    public const JENIS_INSIDEN = 'insiden';

    public const TINGKAT_BAD = 'bad';

    public const TINGKAT_WARN = 'warn';

    public const TINGKAT_INFO = 'info';

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SchoolEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SchoolEvent::class, 'event_id');
    }
}
