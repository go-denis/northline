<?php

namespace Tests\Feature;

use App\Jobs\SendOrderPlacedEmail;
use App\Jobs\SyncOrderToCrm;
use App\Mail\OrderPlacedMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CrmSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class OrderDeskTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_list_orders(): void
    {
        $this->getJson('/api/orders')->assertUnauthorized();
    }

    public function test_seeded_user_can_sign_in(): void
    {
        $this->seed();

        $response = $this->postJson('/login', [
            'email' => 'alex.morgan@northline.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('email', 'alex.morgan@northline.test');

        $this->assertAuthenticated();
    }

    public function test_seed_builds_orders_across_every_status(): void
    {
        $this->seed();

        $this->assertGreaterThanOrEqual(30, Order::query()->count());

        $statuses = Order::query()->pluck('status')->unique()->sort()->values()->all();

        $this->assertSame(
            ['cancelled', 'completed', 'new', 'processing'],
            $statuses,
        );
    }

    public function test_orders_include_an_item_count(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'created_by' => $user->id,
        ]);
        OrderItem::factory()->count(2)->create([
            'order_id' => $order->id,
        ]);

        $response = $this->actingAs($user)->getJson('/api/orders');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.items_count', 2);
    }

    public function test_orders_can_be_filtered_by_status(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();

        Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'new',
            'created_by' => $user->id,
        ]);
        Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'completed',
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->getJson('/api/orders?status=new');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'new');
    }

    public function test_orders_can_be_filtered_by_customer_email(): void
    {
        $user = User::factory()->create();

        $firstCustomer = Customer::factory()->create([
            'email' => 'first@example.test',
        ]);

        $secondCustomer = Customer::factory()->create([
            'email' => 'second@example.test',
        ]);

        Order::factory()->create([
            'customer_id' => $firstCustomer->id,
            'created_by' => $user->id,
        ]);

        Order::factory()->create([
            'customer_id' => $secondCustomer->id,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->getJson('/api/orders?customer_email=first@example.test');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer.email', 'first@example.test');
    }

    public function test_orders_can_be_filtered_by_partial_customer_email(): void
    {
        $user = User::factory()->create();

        $firstCustomer = Customer::factory()->create([
            'email' => 'first@example.test',
        ]);

        $secondCustomer = Customer::factory()->create([
            'email' => 'second@other.test',
        ]);

        Order::factory()->create([
            'customer_id' => $firstCustomer->id,
            'created_by' => $user->id,
        ]);

        Order::factory()->create([
            'customer_id' => $secondCustomer->id,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->getJson('/api/orders?customer_email=example');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer.email', 'first@example.test');
    }

    public function test_orders_can_be_filtered_by_status_and_customer_email_together(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create([
            'email' => 'filter@example.test',
        ]);

        Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'new',
            'created_by' => $user->id,
        ]);

        Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'completed',
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/orders?status=completed&customer_email=filter@example.test');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.0.customer.email', 'filter@example.test');
    }

    public function test_orders_are_paginated(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();

        Order::factory()->count(16)->create([
            'customer_id' => $customer->id,
            'created_by' => $user->id,
        ]);

        $firstPageResponse = $this->actingAs($user)->getJson('/api/orders?page=1');

        $firstPageResponse->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 16)
            ->assertJsonPath('meta.per_page', 15);

        $secondPageResponse = $this->actingAs($user)->getJson('/api/orders?page=2');

        $secondPageResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_orders_keep_filters_when_requesting_another_page(): void
    {
        $user = User::factory()->create();

        $matchingCustomer = Customer::factory()->create([
            'email' => 'buyer@example.test',
        ]);

        $otherCustomer = Customer::factory()->create([
            'email' => 'other@northline.test',
        ]);

        Order::factory()->count(17)->create([
            'customer_id' => $matchingCustomer->id,
            'status' => 'new',
            'created_by' => $user->id,
        ]);

        Order::factory()->count(5)->create([
            'customer_id' => $otherCustomer->id,
            'status' => 'new',
            'created_by' => $user->id,
        ]);

        $pageTwoResponse = $this->actingAs($user)
            ->getJson('/api/orders?status=new&customer_email=example&page=2');

        $pageTwoResponse->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 17);

        $this->assertCount(2, $pageTwoResponse->json('data'));

        foreach ($pageTwoResponse->json('data') as $order) {
            $this->assertSame('new', $order['status']);
            $this->assertStringContainsString('example', $order['customer']['email']);
        }
    }

    public function test_orders_reject_unknown_status_filter(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/orders?status=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_a_user_can_create_an_order_with_several_items(): void
    {
        // We assert immediate persisted state after the request, so queued
        // side effects must not run during this test.
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
        $this->assertSame($user->id, $order->created_by);
        $this->assertSame('new', $order->status);
        $this->assertSame('25.00', $order->total_amount);
        $this->assertFalse($order->email_sent);
        $this->assertSame(2, $order->items()->count());
    }

    public function test_email_job_success_is_independent_from_crm_job_failure(): void
    {
        Mail::fake();

        $customer = Customer::factory()->create([
            'email' => 'parts@westbridge-hydraulics.test',
        ]);

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'email_sent' => false,
        ]);

        $sendEmailJob = new SendOrderPlacedEmail($order->id);
        $sendEmailJob->handle();

        $this->assertTrue($order->fresh()->email_sent);

        $syncToCrmJob = new SyncOrderToCrm($order->id);

        try {
            $syncToCrmJob->handle(app(CrmSyncService::class));
            $this->fail('Expected CRM sync to fail for Westbridge customer.');
        } catch (RuntimeException) {
            $this->assertTrue($order->fresh()->email_sent);
        }
    }

    public function test_send_order_placed_email_job_marks_order_as_sent_after_successful_delivery(): void
    {
        Mail::fake();

        $order = Order::factory()->create([
            'email_sent' => false,
        ]);

        $job = new SendOrderPlacedEmail($order->id);
        $job->handle();

        Mail::assertSent(OrderPlacedMail::class, function (OrderPlacedMail $mail) use ($order): bool {
            return $mail->order->id === $order->id;
        });

        $this->assertTrue($order->fresh()->email_sent);
    }

    public function test_sync_order_to_crm_job_surfaces_failures_for_retry_handling(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'parts@westbridge-hydraulics.test',
        ]);

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
        ]);

        $job = new SyncOrderToCrm($order->id);

        $this->expectException(RuntimeException::class);
        $job->handle(app(CrmSyncService::class));
    }

    public function test_creating_an_order_requires_line_items(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $customer = Customer::factory()->create();

        $this->actingAs($user)->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'items' => [],
        ])->assertUnprocessable();

        $this->assertSame(0, Order::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_user_can_delete_their_own_new_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'status' => 'new',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->deleteJson('/api/orders/'.$order->id)
            ->assertOk();

        $this->assertModelMissing($order);
    }

    public function test_a_user_cannot_delete_another_users_new_order(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()->create();

        $order = Order::factory()->create([
            'status' => 'new',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($actor)
            ->deleteJson('/api/orders/'.$order->id)
            ->assertForbidden();

        $this->assertModelExists($order);
    }

    public function test_a_user_cannot_delete_their_own_non_new_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'status' => 'completed',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->deleteJson('/api/orders/'.$order->id)
            ->assertForbidden();

        $this->assertModelExists($order);
    }
}
