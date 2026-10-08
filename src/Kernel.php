<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink;

use IamAngusU\MagicLink\Mail\FileMailer;
use IamAngusU\MagicLink\Mail\MagicLinkMessage;
use IamAngusU\MagicLink\Mail\NativeMailer;
use IamAngusU\MagicLink\Mail\OutboxWorker;
use IamAngusU\MagicLink\Mail\SmtpMailer;

final class Kernel
{
    private function __construct(
        public readonly Config $config,
        public readonly Database $database,
        public readonly Crypto $crypto,
        public readonly MagicLinkService $magicLinks,
        public readonly HandoffService $handoffs,
        public readonly OutboxWorker $outbox,
        public readonly MaintenanceService $maintenance,
        public readonly Tuning $tuning,
    ) {}

    public static function boot(string $root): self
    {
        $config = Config::load($root);
        $database = Database::connect($config);
        $database->ensureSchema();
        $crypto = new Crypto($config->appKey());
        $mailer = match ($config->string('MAIL_TRANSPORT')) {
            'log' => new FileMailer($config),
            'smtp' => new SmtpMailer($config),
            default => new NativeMailer($config),
        };
        $magicLinks = new MagicLinkService($database->pdo(), $config, $crypto);
        $handoffs = new HandoffService($database->pdo(), $config, $crypto);
        $outbox = new OutboxWorker(
            $database->pdo(),
            $config,
            $crypto,
            new MagicLinkMessage($config, $mailer),
        );
        $tuning = new Tuning($config, $database->pdo());
        // Resolve every automatic value during boot so invalid operator config fails fast.
        $tuning->stateBatchMax();
        $tuning->workerBatch();
        $tuning->pendingMailMax();
        $tuning->maintenanceBatch();

        return new self(
            $config,
            $database,
            $crypto,
            $magicLinks,
            $handoffs,
            $outbox,
            new MaintenanceService($database->pdo(), $config),
            $tuning,
        );
    }

    public function app(): App
    {
        return new App(
            $this->config,
            $this->magicLinks,
            $this->handoffs,
            new StateCatalog($this->config->root(), $this->config->locale()),
            $this->outbox,
            $this->maintenance,
            $this->tuning,
        );
    }
}
