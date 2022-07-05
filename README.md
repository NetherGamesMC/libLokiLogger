# libLokiLogger

[Grafana Loki](https://grafana.com/oss/loki/) asynchronous logging utility for PocketMine-MP logger. It will log 
everything from any threads you want.

# Getting started

You will have to initialize and start `LokiLoggerThread` process in your plugin, you can supply your own composer autoloader path
if you are using php's composer.

Here is the code to start using the project:
```php
# Start logging thread.
$lokiFactory = new LokiLoggerThread(COMPOSER_AUTOLOADER_PATH, 'http://localhost:1300', ['app' => 'lobby-1', 'region' => 'ap', 'server-id' => '1']);
$lokiFactory->start();

$this->getServer()->getAsyncPool()->addWorkerStartHook(function (int $workerId) use ($lokiFactory): void {
    $this->getServer()->getAsyncPool()->submitTaskToWorker(new LokiRegisterAsyncTask($lokiFactory), $workerId);
});

# Add an attachment to the server's logger, this will log everything in console into grafana loki.
Server::getInstance()->getLogger()->addAttachment(new LokiLoggerAttachment());
```