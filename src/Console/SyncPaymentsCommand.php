<?php

namespace ByteMinus\BdPayGate\Console;

use ByteMinus\BdPayGate\BdPayGateManager;
use ByteMinus\BdPayGate\Contracts\SupportsSync;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Models\Payment;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Settle payments whose callback never arrived. Schedule it:
 *
 *     Schedule::command('bdpaygate:sync')->everyTenMinutes();
 */
class SyncPaymentsCommand extends Command
{
    protected $signature = 'bdpaygate:sync
        {--gateway= : Only this gateway}
        {--older-than=15 : Minutes a payment must have been pending}
        {--max-age=2880 : Ignore payments pending longer than this many minutes}
        {--limit=200 : Payments per run}';

    protected $description = 'Ask gateways about pending payments and settle them';

    public function handle(BdPayGateManager $manager): int
    {
        $query = Payment::query()
            ->where('status', PaymentStatus::Pending)
            ->where('created_at', '<=', now()->subMinutes((int) $this->option('older-than')))
            ->where('created_at', '>=', now()->subMinutes((int) $this->option('max-age')))
            ->oldest()
            ->limit((int) $this->option('limit'));

        if ($gateway = $this->option('gateway')) {
            $query->where('gateway', $gateway);
        }

        $settled = 0;

        foreach ($query->get() as $payment) {
            try {
                $driver = $manager->for($payment);
            } catch (InvalidArgumentException) {
                continue;
            }

            if (! $driver instanceof SupportsSync) {
                continue;
            }

            try {
                if ($driver->sync($payment)->status !== PaymentStatus::Pending) {
                    $settled++;
                }
            } catch (GatewayException $e) {
                $this->warn("{$payment->gateway} {$payment->gateway_reference}: {$e->getMessage()}");
            }
        }

        $this->info("Settled {$settled} payment(s).");

        return self::SUCCESS;
    }
}
