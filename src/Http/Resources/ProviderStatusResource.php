<?php

namespace RTippin\Messenger\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RTippin\Messenger\Contracts\MessengerProvider;
use RTippin\Messenger\Definitions;
use RTippin\Messenger\Repositories\ThreadRepository;

/**
 * @property-read Model|MessengerProvider $provider
 */

class ProviderStatusResource extends JsonResource
{
    /**
     * @var Model|MessengerProvider
     */
    protected $provider;

    /**
     * @var bool
     */
    protected bool $addOptions;

    /**
     * @var null|int
     */
    protected ?int $forceFriendStatus;

    /**
     * @var bool
     */
    protected bool $addBaseModel;

    /**
     * ProviderStatusResource constructor.
     *
     * @param mixed $provider
     */
    public function __construct($provider)
    {
        parent::__construct($provider);

        $this->provider = $provider;
    }

    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     * @noinspection PhpMissingParamTypeInspection
     */
    public function toArray($request)
    {
        return [
            'provider' => new ProviderResource($this->provider),
            'active_calls_count' => $this->activeCallsCount(),
            'online_status' => $this->provider->onlineStatus(),
            'online_status_verbose' => Definitions::OnlineStatus[$this->provider->onlineStatus()],
            'unread_threads_count' => $this->unreadThreadsCount(),
            'pending_friends_count' => $this->pendingFriendsCount(),
            'settings' => $this->provider->messenger
        ];
    }

    /**
     * @return int
     */
    private function unreadThreadsCount(): int
    {
        return app(ThreadRepository::class)
            ->getProviderUnreadThreadsBuilder()
            ->count();
    }

    /**
     * @return int
     */
    private function pendingFriendsCount(): int
    {
        return messenger()->getProvider()
            ->pendingFriendRequest()
            ->count();
    }

    /**
     * @return int
     */
    private function activeCallsCount(): int
    {
        return app(ThreadRepository::class)
                ->getProviderThreadsWithActiveCallsBuilder()
                ->count();
    }
}