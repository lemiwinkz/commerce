<?php

namespace craft\commerce\services;

use Craft;
use craft\commerce\base\Plan;
use craft\commerce\base\SubscriptionGatewayInterface;
use craft\commerce\elements\Subscription;
use craft\commerce\errors\SubscriptionException;
use craft\commerce\events\CancelSubscriptionEvent;
use craft\commerce\events\CreateSubscriptionEvent;
use craft\commerce\events\SubscriptionEvent;
use craft\commerce\events\SubscriptionPaymentEvent;
use craft\commerce\events\SubscriptionSwitchPlansEvent;
use craft\commerce\models\subscriptions\CancelSubscriptionForm;
use craft\commerce\models\subscriptions\SubscriptionForm;
use craft\commerce\models\subscriptions\SubscriptionPayment;
use craft\commerce\models\subscriptions\SwitchPlansForm;
use craft\commerce\records\Subscription as SubscriptionRecord;
use craft\elements\User;
use craft\helpers\Db;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Susbcriptions service.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class Subscriptions extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event SubscriptionEvent The event that is triggered when a subscription is expired.
     *
     * Plugins can get notified when a subscription is being expired.
     *
     * ```php
     * use craft\commerce\events\SubscriptionEvent;
     * use craft\commerce\services\Subscriptions;
     * use yii\base\Event;
     *
     * Event::on(Subscriptions::class, Subscriptions::EVENT_EXPIRE_SUBSCRIPTION, function(SubscriptionEvent $e) {
     *     // Do something about it - perhaps make a call to 3rd party service to de-authorize a user.
     * });
     * ```
     */
    const EVENT_EXPIRE_SUBSCRIPTION = 'expireSubscription';

    /**
     * @event CreateSubscriptionEvent The event that is triggered before a subscription is created.
     * You may set [[CreateSubscriptionEvent::isValid]] to `false` to prevent the user from being subscribed to the plan.
     *
     * Plugins can get notified before a subscription is created.
     *
     * ```php
     * use craft\commerce\events\CreateSubscriptionEvent;
     * use craft\commerce\services\Subscriptions;
     * use yii\base\Event;
     *
     * Event::on(Subscriptions::class, Subscriptions::EVENT_BEFORE_CREATE_SUBSCRIPTION, function(CreateSubscriptionEvent $e) {
     *     // Do something - perhaps check if the user is eligible for the parameters set and prevent the subscription if not.
     * });
     * ```
     */

    const EVENT_BEFORE_CREATE_SUBSCRIPTION = 'beforeCreateSubscription';

    /**
     * @event SubscriptionEvent The event that is triggered after a subscription is created
     *
     * Plugins can get notified after a subscription is created.
     *
     * ```php
     * use craft\commerce\events\SubscriptionEvent;
     * use craft\commerce\services\Subscriptions;
     * use yii\base\Event;
     *
     * Event::on(Subscriptions::class, Subscriptions::EVENT_AFTER_CREATE_SUBSCRIPTION, function(SubscriptionEvent $e) {
     *     // Do something about it - perhaps make a call to 3rd party service to authorize a user
     * });
     * ```
     */

    const EVENT_AFTER_CREATE_SUBSCRIPTION = 'afterCreateSubscription';

    /**
     * @event SubscriptionEvent The event that is triggered before a subscription is reactivated
     * You may set [[SubscriptionEvent::isValid]] to `false` to prevent the subscription from being reactivated
     *
     * Plugins can get notified before a subscription gets reactivated.
     *
     * ```php
     * use craft\commerce\events\SubscriptionEvent;
     * use craft\commerce\services\Subscriptions;
     * use yii\base\Event;
     *
     * Event::on(Subscriptions::class, Subscriptions::EVENT_BEFORE_REACTIVATE_SUBSCRIPTION, function(SubscriptionEvent $e) {
     *     // Do something - maybe the user does not qualify for reactivation due to some business logic.
     * });
     * ```
     */
    const EVENT_BEFORE_REACTIVATE_SUBSCRIPTION = 'beforeReactivateSubscription';

    /**
     * @event SubscriptionEvent The event that is triggered after a subscription is reactivated
     *
     * Plugins can get notified before a subscription gets reactivated.
     *
     * ```php
     * use craft\commerce\events\SubscriptionEvent;
     * use craft\commerce\services\Subscriptions;
     * use yii\base\Event;
     *
     * Event::on(Subscriptions::class, Subscriptions::EVENT_AFTER_REACTIVATE_SUBSCRIPTION, function(SubscriptionEvent $e) {
     *     // Do something - maybe the user needs to be re-authorized with a 3rd party service.
     * });
     * ```
     */
    const EVENT_AFTER_REACTIVATE_SUBSCRIPTION = 'afterReactivateSubscription';

    /**
     * @event SubscriptionSwitchPlansEvent The event that is triggered before a plan switch happens for a subscription
     * You may set [[SubscriptionSwitchPlansEvent::isValid]] to `false` to prevent the switch from happening
     *
     * Plugins can get notified before a subscription is switched to a different plan.
     *
     * ```php
     * use craft\commerce\events\SubscriptionSwitchPlansEvent;
     * use craft\commerce\services\Subscriptions;
     * use yii\base\Event;
     *
     * Event::on(Subscriptions::class, Subscriptions::EVENT_BEFORE_SWITCH_SUBSCRIPTION_PLAN, function(SubscriptionSwitchPlansEvent $e) {
     *     // Do something - maybe the user is not permitted to switch to that plan due to some business logic.
     * });
     * ```
     */
    const EVENT_BEFORE_SWITCH_SUBSCRIPTION_PLAN = 'beforeSwitchSubscriptionPlan';

    /**
     * @event SubscriptionSwitchPlansEvent The event that is triggered after a subscription is switched to a different plan
     *
     * Plugins can get notified after a subscription gets switched to a different plan.
     *
     * ```php
     * use craft\commerce\events\SubscriptionSwitchPlansEvent;
     * use craft\commerce\services\Subscriptions;
     * use yii\base\Event;
     *
     * Event::on(Subscriptions::class, Subscriptions::EVENT_AFTER_SWITCH_SUBSCRIPTION_PLAN, function(SubscriptionSwitchPlansEvent $e) {
     *     // Do something - maybe the user needs their permissions adjusted on a 3rd party service.
     * });
     * ```
     */
    const EVENT_AFTER_SWITCH_SUBSCRIPTION_PLAN = 'afterSwitchSubscriptionPlan';

    /**
     * @event CancelSubscriptionEvent The event that is triggered before a subscription is canceled
     * You may set [[CancelSubscriptionEvent::isValid]] to `false` to prevent the subscription from being canceled
     *
     * Plugins can get notified before a subscription is canceled.
     *
     * ```php
     * use craft\commerce\events\CancelSubscriptionEvent;
     * use craft\commerce\services\Subscriptions;
     * use yii\base\Event;
     *
     * Event::on(Subscriptions::class, Subscriptions::EVENT_BEFORE_CANCEL_SUBSCRIPTION, function(CancelSubscriptionEvent $e) {
     *     // Do something - maybe the user is not permitted to cancel the subscription for some reason.
     * });
     * ```
     */
    const EVENT_BEFORE_CANCEL_SUBSCRIPTION = 'beforeCancelSubscription';

    /**
     * @event CancelSubscriptionEvent The event that is triggered after a subscription is canceled
     *
     * Plugins can get notified after a subscription gets canceled.
     *
     * ```php
     * use craft\commerce\events\CancelSubscriptionEvent;
     * use craft\commerce\services\Subscriptions;
     * use yii\base\Event;
     *
     * Event::on(Subscriptions::class, Subscriptions::EVENT_AFTER_CANCEL_SUBSCRIPTION, function(CancelSubscriptionEvent $e) {
     *     // Do something - maybe refund the user for the remainder of the subscription.
     * });
     * ```
     */
    const EVENT_AFTER_CANCEL_SUBSCRIPTION = 'afterCancelSubscription';

    /**
     * @event SubscriptionEvent The event that is triggered after an existing
     *
     * Plugins can get notified before a subscription gets updated. Typically this event is fired when subscription data is updated on the gateway.
     *
     * ```php
     * use craft\commerce\events\SubscriptionEvent;
     * use craft\commerce\services\Subscriptions;
     * use yii\base\Event;
     *
     * Event::on(Subscriptions::class, Subscriptions::EVENT_BEFORE_UPDATE_SUBSCRIPTION, function(SubscriptionEvent $e) {
     *     // Do something - maybe refund the user for the remainder of the subscription.
     * });
     * ```
     */
    const EVENT_BEFORE_UPDATE_SUBSCRIPTION = 'beforeUpdateSubscription';

    /**
     * @event SubscriptionPaymentEvent The event that is triggered after receiving a subscription payment
     *
     * Plugins can get notified when a subscription payment is received.
     *
     * ```php
     * use craft\commerce\events\SubscriptionPaymentEvent;
     * use craft\commerce\services\Subscriptions;
     * use yii\base\Event;
     *
     * Event::on(Subscriptions::class, Subscriptions::EVENT_RECEIVE_SUBSCRIPTION_PAYMENT, function(SubscriptionPaymentEvent $e) {
     *     // Do something - perhaps update the loyalty reward data.
     * });
     * ```
     */
    const EVENT_RECEIVE_SUBSCRIPTION_PAYMENT = 'receiveSubscriptionPayment';

    // Public Methods
    // =========================================================================

    /**
     * Expire a subscription.
     *
     * @param Subscription $subscription subscription to expire
     * @param \DateTime $dateTime expiry date time
     * @return bool whether succesfully expired subscription
     * @throws \Throwable if cannot expire subscription
     */
    public function expireSubscription(Subscription $subscription, \DateTime $dateTime = null): bool
    {
        // fire an 'expireSubscription' event
        if ($this->hasEventHandlers(self::EVENT_EXPIRE_SUBSCRIPTION)) {
            $this->trigger(self::EVENT_EXPIRE_SUBSCRIPTION, new SubscriptionEvent([
                'subscriptions' => $subscription
            ]));
        }

        $subscription->isExpired = true;
        $subscription->dateExpired = $dateTime ?? Db::prepareDateForDb(new \DateTime());

        return Craft::$app->getElements()->saveElement($subscription, false);
    }

    /**
     * Returns susbcription count for a plan.
     *
     * @param int $planId
     * @return int
     */
    public function getSubscriptionCountForPlanById(int $planId): int
    {
        return SubscriptionRecord::find()->where(['planId' => $planId])->count();
    }

    /**
     * Subscribe a user to a subscription plan.
     *
     * @param User $user the user subscribing to a plan
     * @param Plan $plan the plan the user is being subscribed to
     * @param SubscriptionForm $parameters array of additional parameters to use
     * @return bool the result
     * @throws InvalidConfigException if the gateway does not support subscriptions
     * @throws SubscriptionException if something went wrong during subscription
     */
    public function subscribe(User $user, Plan $plan, SubscriptionForm $parameters): bool
    {
        $gateway = $plan->getGateway();

        if (!$gateway instanceof SubscriptionGatewayInterface) {
            throw new SubscriptionException('Gateway does not support subscriptions.');
        }

        // fire a 'beforeCreateSubscription' event
        $event = new CreateSubscriptionEvent([
            'user' => $user,
            'plan' => $plan,
            'parameters' => $parameters
        ]);
        $this->trigger(self::EVENT_BEFORE_CREATE_SUBSCRIPTION, $event);

        if (!$event->isValid) {
            $error = Craft::t('commerce', 'Subscription for {user} to {plan} prevented by a plugin.', [
                'user' => $user->getFriendlyName(),
                'plan' => (string)$plan
            ]);

            Craft::error($error, __METHOD__);

            return false;
        }

        $response = $gateway->subscribe($user, $plan, $parameters);

        $subscription = new Subscription();
        $subscription->userId = $user->id;
        $subscription->planId = $plan->id;
        $subscription->gatewayId = $plan->gatewayId;
        $subscription->orderId = null;
        $subscription->reference = $response->getReference();
        $subscription->trialDays = $response->getTrialDays();
        $subscription->nextPaymentDate = $response->getNextPaymentDate();
        $subscription->subscriptionData = $response->getData();
        $subscription->isCanceled = false;
        $subscription->isExpired = false;

        try {
            Craft::$app->getElements()->saveElement($subscription, false);
        } catch (\Throwable $exception) {
            Craft::warning('Failed to subscribe '.$user.' to '.$plan.': '.$exception->getMessage());

            throw new SubscriptionException(Craft::t('commerce', 'Unable to subscribe at this time.'));
        }

        // Fire an 'afterCreateSubscription' event.
        if ($this->hasEventHandlers(self::EVENT_AFTER_CREATE_SUBSCRIPTION)) {
            $this->trigger(self::EVENT_AFTER_CREATE_SUBSCRIPTION, new SubscriptionEvent([
                'subscription' => $subscription
            ]));
        }

        return true;
    }

    /**
     * Reactivate a subscription.
     *
     * @param Subscription $subscription
     * @return bool
     * @throws InvalidConfigException if the gateway does not support subscriptions
     * @throws SubscriptionException  if something went wrong when reactivating subscription
     */
    public function reactivateSubscription(Subscription $subscription): bool
    {
        $gateway = $subscription->getGateway();

        if (!$gateway instanceof SubscriptionGatewayInterface) {
            throw new InvalidConfigException('Gateway does not support subscriptions.');
        }

        // fire a 'beforeReactivateSubscription' event
        $event = new SubscriptionEvent([
            'subscription' => $subscription,
        ]);
        $this->trigger(self::EVENT_BEFORE_REACTIVATE_SUBSCRIPTION, $event);

        if (!$event->isValid) {
            $error = Craft::t('commerce', 'Subscription "{reference}" reactivation was cancelled by a plugin.', [
                'reference' => $subscription->reference,
            ]);

            Craft::error($error, __METHOD__);

            return false;
        }

        $response = $gateway->reactivateSubscription($subscription);

        if (!$response->isScheduledForCancelation()) {
            $subscription->isCanceled = false;
            $subscription->dateCanceled = null;

            try {
                Craft::$app->getElements()->saveElement($subscription, false);
            } catch (\Throwable $exception) {
                Craft::warning('Failed to reactivate subscription '.$subscription->reference.': '.$exception->getMessage());

                throw new SubscriptionException(Craft::t('commerce', 'Unable to reactivate subscription at this time.'));
            }
        }

        // Fire a 'afterReactivateSubscription' event.
        if ($this->hasEventHandlers(self::EVENT_AFTER_REACTIVATE_SUBSCRIPTION)) {
            $this->trigger(self::EVENT_AFTER_REACTIVATE_SUBSCRIPTION, new SubscriptionEvent([
                'subscription' => $subscription
            ]));
        }

        return true;
    }

    /**
     * Switch a subscription to a different subscription plan.
     *
     * @param Subscription $subscription the subscription to modify
     * @param Plan $plan the plan to change the subscription to
     * @param SwitchPlansForm $parameters additional parameters to use
     * @return bool
     * @throws InvalidConfigException
     * @throws SubscriptionException
     */
    public function switchSubscriptionPlan(Subscription $subscription, Plan $plan, SwitchPlansForm $parameters): bool
    {
        $gateway = $subscription->getGateway();

        if (!$gateway instanceof SubscriptionGatewayInterface) {
            throw new InvalidConfigException('Gateway does not support subscriptions.');
        }

        $oldPlan = $subscription->getPlan();

        if (!$plan->canSwitchFrom($oldPlan)) {
            throw new InvalidConfigException('The migration between these plans is not allowed.');
        }

        // fire a 'beforeSwitchSubscriptionPlan' event
        $event = new SubscriptionSwitchPlansEvent([
            'oldPlan' => $oldPlan,
            'subscription' => $subscription,
            'newPlan' => $plan
        ]);
        $this->trigger(self::EVENT_BEFORE_SWITCH_SUBSCRIPTION_PLAN, $event);

        if (!$event->isValid) {
            $error = Craft::t('commerce', 'Subscription "{reference}" switch to "{plan}" was cancelled by a plugin.', [
                'reference' => $subscription->reference,
                'plan' => $plan->reference
            ]);

            Craft::error($error, __METHOD__);

            return false;
        }

        $response = $gateway->switchSubscriptionPlan($subscription, $plan, $parameters);

        $subscription->planId = $plan->id;
        $subscription->nextPaymentDate = $response->getNextPaymentDate();
        $subscription->subscriptionData = $response->getData();
        $subscription->isCanceled = false;
        $subscription->isExpired = false;

        try {
            $result = Craft::$app->getElements()->saveElement($subscription);

            if (!$result) {
                throw new SubscriptionException('Could not save subscription.');
            }

            // fire an 'afterSwitchSubscriptionPlan' event
            if ($this->hasEventHandlers(self::EVENT_AFTER_SWITCH_SUBSCRIPTION_PLAN)) {
                $this->trigger(self::EVENT_AFTER_SWITCH_SUBSCRIPTION_PLAN, new SubscriptionSwitchPlansEvent([
                    'oldPlan' => $oldPlan,
                    'subscription' => $subscription,
                    'newPlan' => $plan
                ]));
            }
        } catch (\Throwable $exception) {
            Craft::warning('Failed to switch the '.$subscription->reference.' subscription to '.$plan.': '.$exception->getMessage());

            throw new SubscriptionException(Craft::t('commerce', 'Unable to switch subscription plan at this time.'));
        }

        return true;
    }

    /**
     * Cancel a subscription.
     *
     * @param Subscription $subscription
     * @param CancelSubscriptionForm $parameters
     * @return bool
     * @throws InvalidConfigException if the gateway does not support subscriptions
     * @throws SubscriptionException  if something went wrong when canceling subscription
     */
    public function cancelSubscription(Subscription $subscription, CancelSubscriptionForm $parameters): bool
    {
        $gateway = $subscription->getGateway();

        if (!$gateway instanceof SubscriptionGatewayInterface) {
            throw new InvalidConfigException('Gateway does not support subscriptions.');
        }

        // fire a 'beforeCancelSubscription' event
        $event = new CancelSubscriptionEvent([
            'subscription' => $subscription,
            'parameters' => $parameters
        ]);
        $this->trigger(self::EVENT_BEFORE_CANCEL_SUBSCRIPTION, $event);

        if (!$event->isValid) {
            $error = Craft::t('commerce', 'Subscription "{reference}" cancellation was prevented by a plugin.', [
                'reference' => $subscription->reference,
            ]);

            Craft::error($error, __METHOD__);

            return false;
        }

        $response = $gateway->cancelSubscription($subscription, $parameters);

        if ($response->isCanceled() || $response->isScheduledForCancelation()) {
            if ($response->isScheduledForCancelation()) {
                $subscription->isCanceled = true;
                $subscription->dateCanceled = Db::prepareDateForDb(new \DateTime());
            }

            if ($response->isCanceled()) {
                $subscription->isExpired = true;
                $subscription->dateExpired = Db::prepareDateForDb(new \DateTime());
            }

            try {
                Craft::$app->getElements()->saveElement($subscription, false);

                // fire an 'afterCancelSubscription' event
                if ($this->hasEventHandlers(self::EVENT_AFTER_CANCEL_SUBSCRIPTION)) {
                    $this->trigger(self::EVENT_AFTER_CANCEL_SUBSCRIPTION, new CancelSubscriptionEvent([
                        'subscription' => $subscription,
                        'parameters' => $parameters
                    ]));
                }
            } catch (\Throwable $exception) {
                Craft::warning('Failed to cancel subscription '.$subscription->reference.': '.$exception->getMessage());

                throw new SubscriptionException(Craft::t('commerce', 'Unable to cancel subscription at this time.'));
            }
        }

        return true;
    }

    /**
     * Update a subscription.
     *
     * @param Subscription $subscription
     * @return bool
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     */
    public function updateSubscription(Subscription $subscription): bool
    {
        if ($this->hasEventHandlers(self::EVENT_BEFORE_UPDATE_SUBSCRIPTION)) {
            $this->trigger(self::EVENT_BEFORE_UPDATE_SUBSCRIPTION, new SubscriptionEvent([
                'subscription' => $subscription
            ]));
        }

        return Craft::$app->getElements()->saveElement($subscription);
    }

    /**
     * Receive a payment for a subscription
     *
     * @param Subscription $subscription
     * @param SubscriptionPayment $payment
     * @param \DateTime $paidUntil
     * @return bool
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     */
    public function receivePayment(Subscription $subscription, SubscriptionPayment $payment, \DateTime $paidUntil): bool
    {

        if ($this->hasEventHandlers(self::EVENT_RECEIVE_SUBSCRIPTION_PAYMENT)) {
            $this->trigger(self::EVENT_RECEIVE_SUBSCRIPTION_PAYMENT, new SubscriptionPaymentEvent([
                'subscription' => $subscription,
                'payment' => $payment,
                'paidUntil' => $paidUntil
            ]));
        }

        $subscription->nextPaymentDate = $paidUntil;

        return Craft::$app->getElements()->saveElement($subscription);
    }
}
