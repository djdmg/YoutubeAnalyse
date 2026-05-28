<?php

namespace App\EventSubscriber;

use App\Entity\MessengerLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

class MessengerLogSubscriber implements EventSubscriberInterface
{
    /** @var array<string, array{log: MessengerLog, startedAt: float}> */
    private array $running = [];

    public function __construct(private readonly EntityManagerInterface $em) {}

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => 'onReceived',
            WorkerMessageHandledEvent::class  => 'onHandled',
            WorkerMessageFailedEvent::class   => 'onFailed',
        ];
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $message  = $envelope->getMessage();
        $class    = get_class($message);

        $payload = [];
        if (method_exists($message, '__serialize')) {
            $payload = $message->__serialize();
        } else {
            // Extract public readonly properties via reflection
            $ref = new \ReflectionClass($message);
            foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
                $val = $prop->getValue($message);
                // Truncate long strings (e.g. prompt) for display
                if (is_string($val) && mb_strlen($val) > 200) {
                    $val = mb_substr($val, 0, 200) . '…';
                }
                $payload[$prop->getName()] = $val;
            }
        }

        $log = new MessengerLog($class, $payload);
        $this->em->persist($log);
        $this->em->flush();

        $this->running[spl_object_id($envelope->getMessage())] = [
            'log'       => $log,
            'startedAt' => microtime(true),
        ];
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $key = spl_object_id($event->getEnvelope()->getMessage());
        if (!isset($this->running[$key])) return;

        ['log' => $log, 'startedAt' => $startedAt] = $this->running[$key];
        $log->markSuccess((int) ((microtime(true) - $startedAt) * 1000));
        $this->em->flush();
        unset($this->running[$key]);
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $key = spl_object_id($event->getEnvelope()->getMessage());
        if (!isset($this->running[$key])) return;

        ['log' => $log, 'startedAt' => $startedAt] = $this->running[$key];
        $duration = (int) ((microtime(true) - $startedAt) * 1000);
        $error    = $event->getThrowable()->getMessage();
        $error    = preg_replace('/([?&]key=)[^&\s"\']+/', '$1***', $error);

        if ($event->willRetry()) {
            $log->markRetry($error, 0);
        } else {
            $log->markFailed($error, $duration);
        }

        $this->em->flush();
        unset($this->running[$key]);
    }
}
