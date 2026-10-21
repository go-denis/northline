<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendOrderPlacedEmail;
use App\Jobs\SyncOrderToCrm;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderQueueDispatchTest extends TestCase
{
    use DatabaseMigrations;

    public function test_order_creation_dispatches_side_effect_jobs_after_commit(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $customer = Customer::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'items' => [
                ['product_name' => 'V-belt A48', 'qty' => 2, 'unit_price' => 10],
                ['product_name' => 'Shaft seal 35x52x7', 'qty' => 1, 'unit_price' => 5],
            ],
        ]);

        $response->assertCreated();

        $order = Order::query()->first();

        $this->assertNotNull($order);

        Queue::assertPushed(SendOrderPlacedEmail::class, 1);
        Queue::assertPushed(SendOrderPlacedEmail::class, function (SendOrderPlacedEmail $job) use ($order): bool {
            return $job->orderId === $order->id;
        });

        Queue::assertPushed(SyncOrderToCrm::class, 1);
        Queue::assertPushed(SyncOrderToCrm::class, function (SyncOrderToCrm $job) use ($order): bool {
            return $job->orderId === $order->id;
        });
    }

    public function test_order_creation_does_not_dispatch_side_effect_jobs_when_outer_transaction_rolls_back(): void
    {
        Queue::fake();

        // This guards transaction-boundary behavior: side effects must not be
        // enqueued if an outer transaction aborts.

        $user = User::factory()->create();
        $customer = Customer::factory()->create();

        DB::beginTransaction();

        try {
            $response = $this->actingAs($user)->postJson('/api/orders', [
                'customer_id' => $customer->id,
                'items' => [
                    ['product_name' => 'V-belt A48', 'qty' => 2, 'unit_price' => 10],
                ],
            ]);

            $response->assertCreated();

            Queue::assertNothingPushed();
        } finally {
            DB::rollBack();
        }

        Queue::assertNothingPushed();
    }
}
