<?php

namespace App\Notifications;

use App\Models\Donation;

class DonationStatusChanged extends AppNotification
{
    public function __construct(private Donation $donation)
    {
    }

    public function type(): string
    {
        return 'donation_status';
    }

    public function title(): string
    {
        return 'Donation update';
    }

    public function message(): string
    {
        return "Your donation ({$this->donation->reference_no}) is now \"{$this->donation->status}\".";
    }

    public function data(): array
    {
        return [
            'donation_id' => $this->donation->id,
            'reference_no' => $this->donation->reference_no,
            'status' => $this->donation->status,
        ];
    }
}
