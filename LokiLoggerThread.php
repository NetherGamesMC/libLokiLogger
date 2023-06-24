<?php

namespace libLokiLogger;

use pmmp\thread\ThreadSafeArray;
use pocketmine\thread\NonThreadSafeValue;
use pocketmine\thread\Thread;
use pocketmine\utils\Internet;
use pocketmine\utils\InternetException;
use pocketmine\utils\SingletonTrait;
use RuntimeException;
use function array_merge;
use function explode;
use function json_encode;
use function microtime;
use function strlen;

class LokiLoggerThread extends Thread
{
    use SingletonTrait;

    public const PUBLISHING_DELAY = 5;

    /** @var ThreadSafeArray */
    private ThreadSafeArray $buffer;
    /** @phpstan-var NonThreadSafeValue<array<string, string>> */
    private NonThreadSafeValue $labels;

    /**
     * @param string $endpoint The endpoint to grafana loki
     * @param array<string, string> $labels Default labels that will be used to identify the logs' origin.
     */
    public function __construct(
        private string $composerPath,
        private string $endpoint,
        array          $labels = [])
    {
        $this->buffer = new ThreadSafeArray();
        $this->labels = new NonThreadSafeValue($labels);

        self::setInstance($this);
    }

    /**
     * @param string $line The line of a particular log.
     * @param array $labels Optional label to identify the line information.
     * @return void
     */
    public function write(string $line, array $labels = []): void
    {
        $currentTime = sprintf("%d", microtime(true) * 1000000000);
        $this->synchronized(function () use ($line, $currentTime, $labels): void {
            foreach (explode("\n", $line) as $l) {
                if ($l !== '') {
                    $this->buffer[] = igbinary_serialize([$l, $currentTime, $labels]);
                }
            }

            $this->notify();
        });
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException("Grafana Loki is not initialized properly in this environment.");
        }

        return self::$instance;
    }

    protected function onRun(): void
    {
        if (!empty($this->composerPath)) {
            require $this->composerPath;
        }

        $defaultLabels = $this->labels->deserialize();

        while (!$this->isKilled) {
            $start = microtime(true);

            $this->tickProcessor($defaultLabels);

            $time = microtime(true) - $start;
            if ($time < self::PUBLISHING_DELAY) {
                $sleepUntil = (int)((self::PUBLISHING_DELAY - $time) * 1000000);

                $this->synchronized(function () use ($sleepUntil): void {
                    $this->wait($sleepUntil);
                });
            }
        }

        $this->tickProcessor($defaultLabels);
    }

    private function tickProcessor(array $defaultLabels): void
    {
        $hasLogs = false;

        $mainStream = [];
        $secondaryStream = [];
        while ($this->buffer->count() > 0) {
            $buffer = $this->buffer->shift();

            [$message, $timestamp, $labels] = igbinary_unserialize($buffer);

            if (empty($labels)) {
                $mainStream[] = [$timestamp, $message];
            } else {
                $secondaryStream[] = [$labels, $timestamp, $message];
            }

            $hasLogs = true;
        }

        if ($hasLogs) {
            $mainBody["streams"] = [["stream" => $defaultLabels, "values" => $mainStream]];
            foreach ($secondaryStream as [$labels, $timestamp, $message]) {
                $mainBody["streams"][] = ["stream" => array_merge($defaultLabels, $labels), "values" => [[$timestamp, $message]]];
            }

            // Retry one more time, if it fails again, we just ignore it and move on to the next logs.
            $this->postContent(json_encode($mainBody), 1);
        }
    }

    private function postContent(string $jsonPayload, int $retries): void
    {
        try {
            $v = Internet::simpleCurl($this->endpoint . '/loki/api/v1/push', 10, [
                "User-Agent: NetherGamesMC/libLokiLogger",
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonPayload)
            ], [
                CURLOPT_POST => 1,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => $jsonPayload
            ]);

            if ($v->getCode() !== 204 && $retries > 0) {
                $this->postContent($jsonPayload, $retries - 1);
            }
        } catch (InternetException) {
            if ($retries > 0) {
                $this->postContent($jsonPayload, $retries - 1);
            }
        }
    }
}