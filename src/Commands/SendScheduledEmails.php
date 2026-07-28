<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Commands;

use FinityLabs\FinMail\Actions\EmailSender;
use FinityLabs\FinMail\Enums\ScheduledEmailStatus;
use FinityLabs\FinMail\Helpers\RecipientGrouper;
use FinityLabs\FinMail\Models\ScheduledEmail;
use Illuminate\Console\Command;

class SendScheduledEmails extends Command
{
    protected $signature = 'fin-mail:send-scheduled';

    protected $description = 'Dispatch scheduled emails whose send time has arrived.';

    public function handle(): int
    {
        $due = ScheduledEmail::query()->due()->orderBy('scheduled_at')->get();

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($due as $scheduled) {
            // Atomically claim the row so an overlapping run can never send it
            // twice: only the process that flips Pending -> Sent proceeds.
            $claimed = ScheduledEmail::query()
                ->whereKey($scheduled->getKey())
                ->where('status', ScheduledEmailStatus::Pending)
                ->update([
                    'status' => ScheduledEmailStatus::Sent,
                    'sent_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                continue;
            }

            $scheduled->refresh();

            $this->dispatchScheduled($scheduled);
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} scheduled email(s).");

        return self::SUCCESS;
    }

    protected function dispatchScheduled(ScheduledEmail $scheduled): void
    {
        $data = $scheduled->payload;

        // Shared with the compose page, so a CSV batch expands into the same
        // per-recipient emails whether it goes out now or in three days.
        $groups = RecipientGrouper::sendGroups($data, $scheduled->send_mode);

        $sentCount = 0;

        foreach ($groups as $group) {
            try {
                $sender = new EmailSender(
                    data: array_merge($data, $group),
                    record: null,
                    templateKey: $data['template_key'] ?? null,
                    notify: false,
                );

                if ($sender->send()) {
                    $sentCount++;
                }
            } catch (\Throwable $e) {
                // EmailSender normally swallows send failures, but stay defensive
                // so one bad group can't abort the rest of the batch.
                $this->error("Scheduled email #{$scheduled->getKey()} group failed: {$e->getMessage()}");
            }
        }

        if ($sentCount === 0) {
            $scheduled->markAsFailed('All recipient groups failed to send.');

            return;
        }

        if ($sentCount < count($groups)) {
            $scheduled->update([
                'metadata' => array_merge($scheduled->metadata ?? [], [
                    'partial' => true,
                    'sent_groups' => $sentCount,
                    'total_groups' => count($groups),
                ]),
            ]);
        }
    }
}
