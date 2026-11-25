<?php

namespace App\Actions\Concerns;

use App\Enums\AlertChannel;
use App\Exceptions\AlertRefused;
use App\Models\User;

trait ResolvesAlertChannel
{
    /**
     * What the customer's preference is now, recorded on the alert for display.
     *
     * @throws AlertRefused when there is neither LINE nor an email to tell them on.
     */
    private function channelFor(User $user): AlertChannel
    {
        if ($user->prefersLine()) {
            return AlertChannel::Line;
        }

        if (filled($user->email)) {
            return AlertChannel::Email;
        }

        throw new AlertRefused('no_channel');
    }
}
