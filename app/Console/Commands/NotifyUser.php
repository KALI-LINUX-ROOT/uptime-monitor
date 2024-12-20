<?php

namespace App\Console\Commands;

use App\Models\CustomerSite;
use App\Models\MonitoringLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotifyUser extends Command
{
    protected $signature = 'notify-user';

    protected $description = 'Notify user for website down';

    public function handle(): void
    {
        $telegramToken = config('services.telegram_notifier.token');
        if (empty($telegramToken)) {
            $this->error('Telegram bot token is not configured.');
            return;
        }

        $customerSites = CustomerSite::where('is_active', 1)->get();

        foreach ($customerSites as $customerSite) {
            if (!$customerSite->canNotifyUser()) {
                continue;
            }

            $responseTimes = MonitoringLog::query()
                ->where('customer_site_id', $customerSite->id)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get(['response_time', 'created_at']);

            $responseTimeAverage = $responseTimes->avg('response_time');
            
            if ($responseTimeAverage >= ($customerSite->down_threshold * 0.9)) {
                $this->notifyUser($customerSite, $responseTimes);
                $customerSite->last_notify_user_at = Carbon::now();
                $customerSite->save();
            }
        }

        $this->info('Notification process completed.');
    }

    private function notifyUser(CustomerSite $customerSite, Collection $responseTimes): void
    {
        $owner = $customerSite->owner;
        if (!$owner) {
            Log::channel('daily')->error('Missing customer site owner', ['site' => $customerSite->toArray()]);
            return;
        }

        $telegramChatId = $owner->telegram_chat_id;
        if (empty($telegramChatId)) {
            Log::channel('daily')->error('Missing Telegram chat ID for owner', ['site' => $customerSite->toArray()]);
            return;
        }

        $telegramToken = config('services.telegram_notifier.token');
        $endpoint = "https://api.telegram.org/bot{$telegramToken}/sendMessage";

        $text = $this->formatMessage($customerSite, $responseTimes);

        try {
            $response = Http::post($endpoint, [
                'chat_id' => $telegramChatId,
                'text' => $text,
            ]);

            if ($response->successful()) {
                Log::channel('daily')->info('Notification sent successfully', ['site' => $customerSite->id]);
            } else {
                Log::channel('daily')->error('Failed to send notification', [
                    'response' => $response->body(),
                    'site' => $customerSite->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('daily')->error('Error sending Telegram notification', [
                'error' => $e->getMessage(),
                'site' => $customerSite->id,
            ]);
        }
    }

    private function formatMessage(CustomerSite $customerSite, Collection $responseTimes): string
    {
        $text = "🚨 *Uptime Alert: Website Down* 🚨\n\n";
        $text .= "*Site Name:* {$customerSite->name}\n";
        $text .= "*URL:* {$customerSite->url}\n\n";
        $text .= "*Last 5 Response Times:*\n";

        foreach ($responseTimes as $responseTime) {
            $text .= "- {$responseTime->created_at->format('H:i:s')}: {$responseTime->response_time} ms\n";
        }

        $text .= "\n*Details:* [View Site](" . route('customer_sites.show', [$customerSite->id]) . ")";

        return $text;
    }
}
