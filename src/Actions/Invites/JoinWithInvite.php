<?php

namespace RTippin\Messenger\Actions\Invites;

use Exception;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use RTippin\Messenger\Actions\Threads\StoreParticipant;
use RTippin\Messenger\Events\InviteUsedEvent;
use RTippin\Messenger\Exceptions\FeatureDisabledException;
use RTippin\Messenger\Messenger;
use RTippin\Messenger\Models\Invite;
use Throwable;

class JoinWithInvite extends InviteAction
{
    /**
     * @var Dispatcher
     */
    private Dispatcher $dispatcher;

    /**
     * @var DatabaseManager
     */
    private DatabaseManager $database;

    /**
     * JoinWithInvite constructor.
     *
     * @param Messenger $messenger
     * @param DatabaseManager $database
     * @param Dispatcher $dispatcher
     */
    public function __construct(Messenger $messenger,
                                DatabaseManager $database,
                                Dispatcher $dispatcher)
    {
        parent::__construct($messenger);

        $this->dispatcher = $dispatcher;
        $this->database = $database;
    }

    /**
     * @param mixed ...$parameters
     * @var Invite[0]
     * @return $this
     * @throws Exception|Throwable|FeatureDisabledException
     */
    public function execute(...$parameters): self
    {
        $this->isInvitationsEnabled();

        /** @var Invite $invite */
        $invite = $parameters[0];

        $this->setThread($invite->thread)
            ->handleTransactions($invite)
            ->fireEvents($invite);

        return $this;
    }

    /**
     * @param Invite $invite
     * @return $this
     * @throws Throwable
     */
    private function handleTransactions(Invite $invite): self
    {
        if ($this->isChained()) {
            $this->executeTransactions($invite);
        } else {
            $this->database->transaction(fn () => $this->executeTransactions($invite), 3);
        }

        return $this;
    }

    /**
     * Execute all actions that must occur for
     * a successful private thread creation.
     *
     * @param Invite $invite
     */
    private function executeTransactions(Invite $invite): void
    {
        $this->incrementInviteUses($invite);

        $this->setData(
            $this->chain(StoreParticipant::class)
                ->execute(...$this->addParticipant())
                ->getParticipant()
        );
    }

    /**
     * @param Invite $invite
     * @return $this
     */
    private function incrementInviteUses(Invite $invite): self
    {
        $invite->update([
            'uses' => $invite->uses + 1,
        ]);

        return $this;
    }

    /**
     * Execute params for self participant.
     *
     * @mixin StoreParticipant
     * @return array
     */
    private function addParticipant(): array
    {
        return [
            $this->getThread(),
            $this->messenger->getProvider(),
            [],
            true,
        ];
    }

    /**
     * Broadcast / fire events.
     *
     * @param Invite $invite
     * @return $this
     */
    private function fireEvents(Invite $invite): self
    {
        if ($this->shouldFireEvents()) {
            $this->dispatcher->dispatch(new InviteUsedEvent(
                $this->messenger->getProvider()->withoutRelations(),
                $this->getThread(true),
                $invite->withoutRelations()
            ));
        }

        return $this;
    }
}
