<?php

namespace App\MessageHandler;

use App\Message\SendEmailMessage;
use App\Repository\AppSettingRepository;
use App\Service\EmailNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
class SendEmailHandler
{
    public function __construct(
        private readonly AppSettingRepository $settingRepo,
        private readonly LoggerInterface      $logger,
    ) {}

    public function __invoke(SendEmailMessage $msg): void
    {
        $email = (new Email())
            ->from(new Address($msg->fromEmail, $msg->fromName))
            ->to($msg->to)
            ->subject($msg->subject)
            ->html($msg->htmlBody);

        $transport = Transport::fromDsn($this->buildDsn());
        $mailer    = new Mailer($transport);
        $mailer->send($email);

        $this->logger->info('Email sent via Messenger', ['to' => $msg->to, 'subject' => $msg->subject]);
    }

    private function buildDsn(): string
    {
        $encryption = $this->settingRepo->get(EmailNotificationService::SETTING_SMTP_ENCRYPTION) ?: 'starttls';
        $scheme     = $encryption === 'ssl' ? 'smtps' : 'smtp';
        $user       = rawurlencode($this->settingRepo->get(EmailNotificationService::SETTING_SMTP_USER) ?? '');
        $pass       = rawurlencode($this->settingRepo->get(EmailNotificationService::SETTING_SMTP_PASSWORD) ?? '');
        $host       = $this->settingRepo->get(EmailNotificationService::SETTING_SMTP_HOST);
        $port       = (int) ($this->settingRepo->get(EmailNotificationService::SETTING_SMTP_PORT) ?: ($encryption === 'ssl' ? 465 : 587));
        $query      = $encryption === 'none' ? '?auto_tls=false' : '';

        return sprintf('%s://%s:%s@%s:%d%s', $scheme, $user, $pass, $host, $port, $query);
    }
}
