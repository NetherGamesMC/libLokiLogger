<?php

namespace libLokiLogger;

use pmmp\thread\Thread as NativeThread;
use pocketmine\thread\log\ThreadSafeLoggerAttachment;
use pocketmine\thread\Thread;
use pocketmine\thread\Worker;
use pocketmine\utils\TextFormat;
use ReflectionClass;

class LokiLoggerAttachment extends ThreadSafeLoggerAttachment
{
    /** @var LokiLoggerThread */
    private LokiLoggerThread $lokiInstance;

    public function __construct()
    {
        $this->lokiInstance = LokiLoggerThread::getInstance();
    }

    public function log(string $level, string $message): void
    {
        $thread = NativeThread::getCurrentThread();
        if ($thread === null) {
            $threadName = "Server thread";
        } elseif ($thread instanceof Thread or $thread instanceof Worker) {
            $threadName = $thread->getThreadName() . " thread";
        } else {
            $threadName = (new ReflectionClass($thread))->getShortName() . " thread";
        }

        $this->lokiInstance->write(preg_filter('/^\[(.*?)] /', '', TextFormat::clean($message)), ['level' => $level, 'thread' => $threadName]);
    }
}