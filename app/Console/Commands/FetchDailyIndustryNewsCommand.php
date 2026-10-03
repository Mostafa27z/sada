<?php

namespace App\Console\Commands;

use App\Jobs\FetchTenantIndustryNewsJob;
use App\Models\Tenant;
use App\Services\IndustryNewsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FetchDailyIndustryNewsCommand extends Command
{
    protected $signature = 'sada:fetch-industry-news 
                            {--tenant= : Run for a specific tenant ID} 
                            {--sync : Run synchronously instead of dispatching to queue}
                            {--date= : Custom batch date (YYYY-MM-DD)}';

    protected $description = 'Fetch and curate top 10 daily industry news with actionable recommendations for active tenants';

    public function handle(IndustryNewsService $newsService): int
    {
        $todayKsa = Carbon::now('Asia/Riyadh')->toDateString();
        $batchDate = $this->option('date') ?: $todayKsa;
        $isSync = (bool)$this->option('sync');

        $this->info("Starting Daily Industry News Fetcher for date: {$batchDate} (KSA Time)");

        if (!$newsService->getGeminiService()->hasApiKey()) {
            $this->error("❌ تنبيه هام: مفتاح GEMINI_API_KEY غير موجود في ملف .env على هذا السيرفر!");
            $this->warn("💡 يرجى إضافة GEMINI_API_KEY=AIzaSy... في ملف .env ليتمكن الذكاء الاصطناعي من تحليل الأخبار وصياغة المقترحات.");
            return Command::FAILURE;
        }

        $tenantId = $this->option('tenant');

        if ($tenantId) {
            $tenants = Tenant::where('id', $tenantId)->get();
        } else {
            $tenants = Tenant::whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])->get();
        }

        if ($tenants->isEmpty()) {
            $this->warn('No active tenants found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$tenants->count()} tenant(s) to process.");

        foreach ($tenants as $tenant) {
            $this->line("<fg=cyan>Processing Tenant #{$tenant->id}: {$tenant->name}</>");

            if ($isSync) {
                try {
                    $items = $newsService->fetchAndProcessDailyNewsForTenant(
                        $tenant,
                        $batchDate,
                        function (string $msg) {
                            $this->line($msg);
                        }
                    );
                    if (!empty($items)) {
                        $this->info("  --> اكتمل بنجاح: تم حفظ " . count($items) . " من أهم أخبار القطاع مع المقترحات.");
                    } else {
                        $this->warn("  --> انتهت المعالجة بـ 0 عناصر. راجع السجلات للتفاصيل.");
                    }
                } catch (\Throwable $e) {
                    $this->error("  ❌ Failed for tenant #{$tenant->id}: " . $e->getMessage());
                }
            } else {
                FetchTenantIndustryNewsJob::dispatch($tenant->id, $batchDate);
                $this->info("  --> تم إرسال المهمة للطابور بنجاح.");
            }
        }

        $this->info('Daily Industry News dispatch completed.');
        return Command::SUCCESS;
    }
}
