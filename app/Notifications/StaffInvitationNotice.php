<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitationNotice extends Notification
{
    public function __construct(public readonly string $link, private readonly string $branchName) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('คำเชิญเข้าร่วม ENDU CARE')
            ->greeting('คุณได้รับคำเชิญเข้าร่วม ENDU CARE')
            ->line('สาขา '.$this->branchName)
            ->action('เปิดคำเชิญ', $this->link)
            ->line('คำเชิญใช้ได้ครั้งเดียว ภายใน 48 ชั่วโมง');
    }
}
