<?php
declare(strict_types=1);

use IamAngusU\MagicLink\Config;
use IamAngusU\MagicLink\Crypto;
use IamAngusU\MagicLink\Database;
use IamAngusU\MagicLink\Mail\ContextualMailer;
use IamAngusU\MagicLink\Mail\DeliveryContext;
use IamAngusU\MagicLink\Mail\MagicLinkMessage;
use IamAngusU\MagicLink\Mail\Mailer;
use IamAngusU\MagicLink\Mail\MailTransportException;
use IamAngusU\MagicLink\Mail\OutboxWorker;
use IamAngusU\MagicLink\MagicLinkService;

$root = dirname(__DIR__);
require $root . '/autoload.php';

function hardeningExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class ScriptedContextMailer implements ContextualMailer
{
    /** @var list<string> */
    public array $messageIds = [];
    /** @var list<string> */
    public array $recipients = [];
    /** @var array<int,Throwable> */
    public array $errors = [];
    /** @var null|Closure(string,DeliveryContext):void */
    public ?Closure $onSend = null;
    private int $calls = 0;

    public function send(string $to, string $subject, string $html, string $plain): void
    {
        throw new LogicException('The worker must use the contextual mail path.');
    }

    public function sendWithContext(string $to, string $subject, string $html, string $plain, DeliveryContext $context): void
    {
        $this->calls++;
        $this->messageIds[] = $context->messageId;
        $this->recipients[] = $to;
        if ($this->onSend !== null) {
            ($this->onSend)($to, $context);
        }
        if (isset($this->errors[$this->calls])) {
            throw $this->errors[$this->calls];
        }
    }
}

$temporary = sys_get_temp_dir() . '/magic-link-worker-' . bin2hex(random_bytes(6));
mkdir($temporary . '/storage', 0700, true);
$config = Config::fromArray($temporary, [
    'APP_ENV' => 'test',
    'APP_URL' => 'http://127.0.0.1:8080',
    'APP_NAME' => 'Worker Test',
    'APP_LOCALE' => 'en',
    'DB_DRIVER' => 'sqlite',
    'DB_PATH' => 'storage/test.sqlite',
    'MAIL_TRANSPORT' => 'log',
    'MAIL_FROM_ADDRESS' => 'test@example.com',
    'MAGICLINK_ALLOWED_DOMAINS' => 'example.com',
    'MAIL_LOCK_TIMEOUT_SECONDS' => '30',
    'MAIL_MAX_ATTEMPTS' => '5',
]);
$database = Database::connect($config);
$database->migrate();
$pdo = $database->pdo();
$crypto = new Crypto($config->appKey());
$service = new MagicLinkService($pdo, $config, $crypto);

// Transient errors are retried with jitter, while the Message-ID remains stable.
$scripted = new ScriptedContextMailer();
$scripted->errors[1] = MailTransportException::transient('smtp_timeout');
$worker = new OutboxWorker($pdo, $config, $crypto, new MagicLinkMessage($config, $scripted));
$first = $service->request('retry@example.com', 'browser-retry', '127.0.0.1');
$attemptOne = $worker->run(1);
hardeningExpect($attemptOne['retried'] === 1 && $attemptOne['failed'] === 0, 'A transient transport error must remain retryable.');
$retryRow = $pdo->prepare('SELECT status,attempts,available_at FROM mail_outbox WHERE selector = ?');
$retryRow->execute([$first['selector']]);
$retryState = $retryRow->fetch();
hardeningExpect($retryState['status'] === 'pending' && (int) $retryState['attempts'] === 1, 'A transient failure must return the row to pending.');
hardeningExpect((int) $retryState['available_at'] >= time() + 25, 'A retry must use non-immediate backoff.');
$pdo->prepare('UPDATE mail_outbox SET available_at = 0 WHERE selector = ?')->execute([$first['selector']]);
$attemptTwo = $worker->run(1);
hardeningExpect($attemptTwo['sent'] === 1, 'The legitimate retry must be deliverable.');
hardeningExpect($scripted->messageIds[0] === $scripted->messageIds[1], 'Retries must reuse the same stable Message-ID.');
hardeningExpect((bool) preg_match('/^ml-[a-f0-9]{48}@127\.0\.0\.1$/D', $scripted->messageIds[0]), 'The Message-ID must be opaque and header-safe.');

// Permanent SMTP rejection stops immediately instead of wasting every attempt.
$scripted->errors[3] = MailTransportException::smtp(550);
$permanent = $service->request('permanent@example.com', 'browser-permanent', '127.0.0.2');
$permanentResult = $worker->run(1);
hardeningExpect($permanentResult['failed'] === 1 && $permanentResult['retried'] === 0, 'A permanent SMTP rejection must fail immediately.');
$permanentRow = $pdo->prepare('SELECT status,attempts FROM mail_outbox WHERE selector = ?');
$permanentRow->execute([$permanent['selector']]);
$permanentState = $permanentRow->fetch();
hardeningExpect($permanentState['status'] === 'failed' && (int) $permanentState['attempts'] === 1, 'A 5xx row must be terminal on its first attempt.');
$failureAudit = $pdo->query("SELECT metadata_json FROM audit_events WHERE event_type = 'magic_link.delivery_failed' ORDER BY id DESC LIMIT 1")->fetchColumn();
$failureMetadata = json_decode((string) $failureAudit, true, 8, JSON_THROW_ON_ERROR);
hardeningExpect($failureMetadata['error_category'] === 'smtp_permanent' && $failureMetadata['error_code'] === 550, 'Audits must expose only a safe SMTP category and code.');

// If a later batch row expires while the first mail is in flight, the original
// worker must observe lost ownership and must not deliver it a second time.
$batchA = $service->request('batch-a@example.com', 'browser-batch', '127.0.0.3');
$batchB = $service->request('batch-b@example.com', 'browser-batch', '127.0.0.4');
$winnerMailer = new ScriptedContextMailer();
$winner = new OutboxWorker($pdo, $config, $crypto, new MagicLinkMessage($config, $winnerMailer));
$loserMailer = new ScriptedContextMailer();
$winnerResult = null;
$loserMailer->onSend = function (string $recipient) use ($pdo, $batchB, $winner, &$winnerResult): void {
    if ($recipient !== 'batch-a@example.com') {
        return;
    }
    $pdo->prepare("UPDATE mail_outbox SET locked_at = 0 WHERE selector = ? AND status = 'sending'")->execute([$batchB['selector']]);
    $winnerResult = $winner->run(1);
};
$loser = new OutboxWorker($pdo, $config, $crypto, new MagicLinkMessage($config, $loserMailer));
$loserResult = $loser->run(2);
hardeningExpect($winnerResult !== null && $winnerResult['sent'] === 1, 'The worker reclaiming the stale later row must deliver it.');
hardeningExpect($loserResult['claimed'] === 2 && $loserResult['sent'] === 1 && $loserResult['lost'] === 1, 'The original batch must skip a row it no longer owns.');
$allRecipients = array_merge($loserMailer->recipients, $winnerMailer->recipients);
sort($allRecipients);
hardeningExpect($allRecipients === ['batch-a@example.com', 'batch-b@example.com'], 'Lease recovery must not duplicate a delivered recipient.');

echo "Mail worker hardening tests passed.\n";
