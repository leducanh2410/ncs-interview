<?php

namespace Tests\Feature\Chat;

use App\Actions\Chat\Lifecycle\AutoCloseConversationAction;
use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatMessageType;
use App\Enums\ChatSystemMessageType;
use App\Jobs\AutoCloseShiftGroupChats;
use App\Models\ChatConversation;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use App\Events\ChatConversationStateChanged;
use App\Events\NewChatMessage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsChatFixtures;
use Tests\TestCase;

class AutoCloseShiftGroupChatsTest extends TestCase
{
    use BuildsChatFixtures;
    use RefreshDatabase;

    private function makeShift(string $date, string $startTime, string $endTime): Shift
    {
        $workplace = Workplace::factory()->create();

        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        return Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => User::factory()->create()->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
    }

    private function makeShiftChat(Shift $shift): ChatConversation
    {
        return ChatConversation::factory()->create([
            'type' => ChatConversationType::ShiftGroup->value,
            'status' => ChatConversationStatus::Open->value,
            'shift_id' => $shift->id,
        ]);
    }

    private function runJob(): void
    {
        (new AutoCloseShiftGroupChats())->handle(app(AutoCloseConversationAction::class));
    }

    public function testClosesShiftChatPastAutoCloseWindow(): void
    {
        Event::fake([ChatConversationStateChanged::class, NewChatMessage::class]);
        $shift = $this->makeShift(now()->subDays(5)->format('Y-m-d'), '09:00:00', '18:00:00');
        $conversation = $this->makeShiftChat($shift);

        $this->runJob();

        $conversation->refresh();
        $this->assertSame(ChatConversationStatus::Closed->value, $conversation->status);
        $this->assertNull($conversation->closed_by_user_id);
        $this->assertSame('auto_closed_shift_ended', $conversation->close_reason);
        $this->assertNotNull($conversation->closed_at);

        $this->assertDatabaseHas('chat_audit_logs', [
            'conversation_id' => $conversation->id,
            'action' => ChatAuditAction::AutoClosed->value,
            'actor_user_id' => null,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'message_type' => ChatMessageType::System->value,
            'body' => ChatSystemMessageType::ConversationClosed->value,
        ]);
        Event::assertDispatched(ChatConversationStateChanged::class);
        Event::assertDispatched(NewChatMessage::class);
    }

    public function testKeepsShiftChatWithinWindowOpen(): void
    {
        $shift = $this->makeShift(now()->format('Y-m-d'), '09:00:00', '18:00:00');
        $conversation = $this->makeShiftChat($shift);

        $this->runJob();

        $this->assertSame(ChatConversationStatus::Open->value, $conversation->fresh()->status);
    }

    public function testHandlesOvernightShift(): void
    {
        // now = 2026-06-15 12:00. Overnight shift on 2026-06-12 (22:00 -> 05:00) ends
        // 2026-06-13 05:00; +3 days = 2026-06-16 05:00 (future) -> must stay OPEN.
        // A naive same-day end (2026-06-12 05:00) would wrongly close it.
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));
        $shift = $this->makeShift('2026-06-12', '22:00:00', '05:00:00');
        $conversation = $this->makeShiftChat($shift);

        $this->runJob();

        $this->assertSame(ChatConversationStatus::Open->value, $conversation->fresh()->status);
    }

    public function testSkipsAlreadyClosedShiftChat(): void
    {
        $shift = $this->makeShift(now()->subDays(5)->format('Y-m-d'), '09:00:00', '18:00:00');
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::ShiftGroup->value,
            'status' => ChatConversationStatus::Closed->value,
            'closed_at' => now()->subDay(),
            'shift_id' => $shift->id,
        ]);

        $this->runJob();

        $this->assertDatabaseMissing('chat_audit_logs', [
            'conversation_id' => $conversation->id,
            'action' => ChatAuditAction::AutoClosed->value,
        ]);
    }

    public function testRespectsConfiguredDays(): void
    {
        config(['chat.shift_group.auto_close_days' => 10]);
        // Ended 5 days ago: past the default 3-day window but within the 10-day override.
        $shift = $this->makeShift(now()->subDays(5)->format('Y-m-d'), '09:00:00', '18:00:00');
        $conversation = $this->makeShiftChat($shift);

        $this->runJob();

        $this->assertSame(ChatConversationStatus::Open->value, $conversation->fresh()->status);
    }
}
