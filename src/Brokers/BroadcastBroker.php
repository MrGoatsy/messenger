<?php

namespace RTippin\Messenger\Brokers;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use RTippin\Messenger\Broadcasting\MessengerBroadcast;
use RTippin\Messenger\Contracts\BroadcastDriver;
use RTippin\Messenger\Contracts\BroadcastEvent;
use RTippin\Messenger\Contracts\MessengerProvider;
use RTippin\Messenger\Messenger;
use RTippin\Messenger\Models\Call;
use RTippin\Messenger\Models\CallParticipant;
use RTippin\Messenger\Models\Participant;
use RTippin\Messenger\Models\Thread;
use RTippin\Messenger\Repositories\ParticipantRepository;
use RTippin\Messenger\Services\PushNotificationService;
use RTippin\Messenger\Traits\ChecksReflection;

class BroadcastBroker implements BroadcastDriver
{
    use ChecksReflection;

    /**
     * @var Messenger
     */
    protected Messenger $messenger;

    /**
     * @var Thread|null
     */
    protected ?Thread $thread = null;

    /**
     * @var array
     */
    protected array $with = [];

    /**
     * @var Collection|null
     */
    protected ?Collection $recipients = null;

    /**
     * @var ParticipantRepository
     */
    protected ParticipantRepository $participantRepository;

    /**
     * @var Factory
     */
    protected Factory $broadcast;

    /**
     * @var Application
     */
    protected Application $app;

    /**
     * @var bool
     */
    protected bool $usingPresence = false;

    /**
     * @var PushNotificationService
     */
    protected PushNotificationService $pushNotification;

    /**
     * BroadcastBroker constructor.
     *
     * @param Messenger $messenger
     * @param ParticipantRepository $participantRepository
     * @param PushNotificationService $pushNotification
     * @param Factory $broadcast
     * @param Application $app
     */
    public function __construct(Messenger $messenger,
                                ParticipantRepository $participantRepository,
                                PushNotificationService $pushNotification,
                                Factory $broadcast,
                                Application $app)
    {
        $this->messenger = $messenger;
        $this->participantRepository = $participantRepository;
        $this->broadcast = $broadcast;
        $this->app = $app;
        $this->pushNotification = $pushNotification;
    }

    /**
     * @inheritDoc
     */
    public function toAllInThread(Thread $thread): self
    {
        $this->thread = $thread;

        $this->presence(false);

        $this->recipients = $this->participantRepository
            ->getThreadBroadcastableParticipants($this->thread);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function toOthersInThread(Thread $thread): self
    {
        $this->thread = $thread;

        $this->presence(false);

        $this->recipients = $this->participantRepository
            ->getThreadBroadcastableParticipants($this->thread)
            ->reject(fn (Participant $participant) => (string) $participant->owner_id === (string) $this->messenger->getProviderId()
                    && $participant->owner_type === $this->messenger->getProviderClass()
            );

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function toSelected(Collection $recipients): self
    {
        $this->presence(false);

        if ($recipients->count()) {
            $this->recipients = $recipients;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function to($recipient): self
    {
        $this->presence(false);

        $this->recipients = collect([$recipient]);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function toPresence($entity): self
    {
        $this->presence(true);

        $this->recipients = collect([$entity]);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function toManyPresence(Collection $presence): self
    {
        $this->presence(true);

        if ($presence->count()) {
            $this->recipients = $presence;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function with(array $with): self
    {
        $this->with = $with;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function broadcast(string $abstract): void
    {
        if (! is_null($this->recipients)
            && $this->recipients->count()
            && $this->checkImplementsInterface(
                $abstract, BroadcastEvent::class
            )) {
            if ($this->usingPresence) {
                $this->generatePresenceChannels()
                    ->each(fn (Collection $channels) => $this->executeBroadcast($abstract, $channels));
            } else {
                $this->generatePrivateChannels()
                    ->each(fn (Collection $channels) => $this->executeBroadcast($abstract, $channels));

                $this->executePushNotify($abstract);
            }
        }
    }

    /**
     * @return Collection
     */
    protected function generatePrivateChannels(): Collection
    {
        return $this->recipients
            ->map(fn ($recipient) => $this->generatePrivateChannel($recipient))
            ->reject(fn ($recipient) => is_null($recipient))
            ->chunk(100);
    }

    /**
     * Generate each private thread channel name. Accepts
     * thread and call participants, or messenger provider.
     *
     * outputs private-messenger.{alias}.{id}
     *
     * @param mixed $recipient
     * @return string|null
     */
    protected function generatePrivateChannel($recipient): ?string
    {
        $abstract = is_object($recipient)
            ? get_class($recipient)
            : '';

        $participants = [
            Participant::class,
            CallParticipant::class,
        ];

        if (in_array($abstract, $participants)
            && $this->messenger->isValidMessengerProvider($recipient->owner_type)) {
            /** @var Participant|CallParticipant $recipient */

            return "private-messenger.{$this->messenger->findProviderAlias($recipient->owner_type)}.{$recipient->owner_id}";
        }

        if (! in_array($abstract, $participants)
            && $this->messenger->isValidMessengerProvider($recipient)) {
            /** @var MessengerProvider $recipient */

            return "private-messenger.{$this->messenger->findProviderAlias($recipient)}.{$recipient->getKey()}";
        }

        return null;
    }

    /**
     * @return Collection
     */
    protected function generatePresenceChannels(): Collection
    {
        return $this->recipients
            ->map(fn ($recipient) => $this->generatePresenceChannel($recipient))
            ->reject(fn ($recipient) => is_null($recipient))
            ->chunk(100);
    }

    /**
     * @param $entity
     * @return string|null
     */
    protected function generatePresenceChannel($entity): ?string
    {
        $abstract = is_object($entity)
            ? get_class($entity)
            : '';

        if ($abstract === Thread::class) {
            /** @var Thread $entity */

            return "presence-messenger.thread.{$entity->id}";
        }

        if ($abstract === Call::class) {
            /** @var Call $entity */

            return "presence-messenger.call.{$entity->id}.thread.{$entity->thread_id}";
        }

        return null;
    }

    /**
     * @param bool $usingPresence
     */
    protected function presence(bool $usingPresence): void
    {
        $this->usingPresence = $usingPresence;
    }

    /**
     * @param string|MessengerBroadcast $abstractBroadcast
     * @param Collection $channels
     */
    protected function executeBroadcast(string $abstractBroadcast, Collection $channels): void
    {
        try {
            $this->broadcast->event(
                $this->app
                    ->make($abstractBroadcast)
                    ->setResource($this->with)
                    ->setChannels($channels->values()->toArray())
            );
        } catch (BroadcastException $e) {
            //continue on
        } catch (BindingResolutionException $e) {
            report($e);
            //continue on
        }
    }

    /**
     * @param string $abstractBroadcast
     */
    protected function executePushNotify(string $abstractBroadcast): void
    {
        if ($this->messenger->isPushNotificationsEnabled()) {
            $this->pushNotification
                ->to($this->recipients)
                ->with($this->with)
                ->notify($abstractBroadcast);
        }
    }
}
