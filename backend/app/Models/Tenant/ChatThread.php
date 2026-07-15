<?php

namespace App\Models\Tenant;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A private 1-to-1 chat thread between a teacher and a student's guardian,
 * about that student (messaging increment 4). See ChatController for the
 * authorization that keeps this pairing to teacher<->guardian only.
 */
class ChatThread extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['user_one_id', 'user_two_id', 'student_id', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'thread_id')->orderBy('created_at');
    }

    public function participantIds(): array
    {
        return [$this->user_one_id, $this->user_two_id];
    }

    public function otherParticipantId(int $userId): int
    {
        return $this->user_one_id === $userId ? $this->user_two_id : $this->user_one_id;
    }

    public function otherParticipant(int $userId): ?User
    {
        return User::find($this->otherParticipantId($userId));
    }

    public function unreadCountFor(int $userId): int
    {
        return $this->messages()->where('sender_user_id', '!=', $userId)->whereNull('read_at')->count();
    }
}
