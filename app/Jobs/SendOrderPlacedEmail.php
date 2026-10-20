<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\OrderPlacedMail;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendOrderPlacedEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public int $orderId) {}

    public function handle(): void
    {
        $order = Order::query()->with('customer')->findOrFail($this->orderId);

        $customer = $order->customer ?? throw new \RuntimeException('Order is missing its customer.');

        Mail::to($customer->email)->send(new OrderPlacedMail($order));

        $order->forceFill([
            'email_sent' => true,
        ])->save();
    }
}
