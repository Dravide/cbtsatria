<?php

namespace App\Domain\Exam\Models;

use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengecualian pengawas pada sesi tertentu (dikosongkan / diliburkan).
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'event_id',
    'exam_session_id',
    'supervisor_id',
    'reason',
])]
class SupervisorSessionExclusion extends Model
{
    use BelongsToSchool;

    protected $table = 'supervisor_session_exclusions';

    /** @return BelongsTo<SchoolEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SchoolEvent::class, 'event_id');
    }

    /** @return BelongsTo<ExamSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }
}
