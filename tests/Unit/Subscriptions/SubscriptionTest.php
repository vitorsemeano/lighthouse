<?php

namespace Tests\Unit\Subscriptions;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Nuwave\Lighthouse\Execution\Utils\Subscription;
use Nuwave\Lighthouse\Schema\Types\GraphQLSubscription;
use Nuwave\Lighthouse\Subscriptions\Contracts\BroadcastsSubscriptions;
use Nuwave\Lighthouse\Subscriptions\Subscriber;
use Nuwave\Lighthouse\Subscriptions\SubscriptionBroadcaster;
use Nuwave\Lighthouse\Subscriptions\SubscriptionRegistry;
use Nuwave\Lighthouse\Subscriptions\SubscriptionServiceProvider;
use Prophecy\Argument;

class SubscriptionTest extends SubscriptionTestCase
{
    /**
     * @var string
     */
    public const SUBSCRIPTION_FIELD = 'postCreated';

    /**
     * @var \Nuwave\Lighthouse\Subscriptions\SubscriptionRegistry
     */
    protected $subscriptionRegistry;

    /**
     * @var \Prophecy\Prophecy\ObjectProphecy
     */
    protected $broadcaster;

    protected function getPackageProviders($app)
    {
        return array_merge(
            parent::getPackageProviders($app),
            [SubscriptionServiceProvider::class]
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema = "
        type Query {
            subscription: String @field(resolver: \"{$this->qualifyTestResolver()}\")
        }
        ";

        $this->subscriptionRegistry = app(SubscriptionRegistry::class);
        $this->subscriptionRegistry->register($this->subscription(), self::SUBSCRIPTION_FIELD);

        $this->broadcaster = $this->prophesize(SubscriptionBroadcaster::class);
        $this->app->instance(BroadcastsSubscriptions::class, $this->broadcaster->reveal());
    }

    public function testCanSendSubscriptionToBroadcaster(): void
    {
        $root = [
            'post' => [
                'id' => 1,
            ],
        ];

        $this->broadcaster->broadcast(
            Argument::type(GraphQLSubscription::class),
            self::SUBSCRIPTION_FIELD,
            $root
        )->shouldBeCalled();

        Subscription::broadcast(self::SUBSCRIPTION_FIELD, $root);
    }

    public function testThrowsOnInvalidSubscriptionField(): void
    {
        $this->broadcaster->broadcast(Argument::any())->shouldNotBeCalled();
        $this->expectException(InvalidArgumentException::class);

        Subscription::broadcast('unknownField', []);
    }

    public function resolve(): string
    {
        return self::SUBSCRIPTION_FIELD;
    }

    protected function subscription(): GraphQLSubscription
    {
        return new class extends GraphQLSubscription {
            public function authorize(Subscriber $subscriber, Request $request): bool
            {
                return true;
            }

            public function filter(Subscriber $subscriber, $root): bool
            {
                return true;
            }
        };
    }
}
