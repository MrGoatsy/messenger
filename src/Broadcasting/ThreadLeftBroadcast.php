<?php

namespace RTippin\Messenger\Broadcasting;

class ThreadLeftBroadcast extends MessengerBroadcast
{
    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'thread.left';
    }
}
