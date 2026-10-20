<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use App\Services\CrmSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncOrderToCrm implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 45;

    /** @var list<int> */
    public array $backoff = [10, 60, 180, 300];

    public function __construct(public int $orderId) {}

    public function handle(CrmSyncService $crmSyncService): void
    {
        $order = Order::query()->with('customer')->findOrFail($this->orderId);

        $crmSyncService->push($order);
    }

    public function failed(Throwable $throwable): void
    {
        Log::warning('CRM sync failed', [
            'order_id' => $this->orderId,
            'message' => $throwable->getMessage(),
        ]);
    }
}
