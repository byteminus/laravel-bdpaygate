<?php

namespace ByteMinus\BdPayGate\Facades;

use ByteMinus\BdPayGate\BdPayGateManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \ByteMinus\BdPayGate\Contracts\Gateway driver(?string $driver = null)
 * @method static \ByteMinus\BdPayGate\Contracts\Gateway gateway(?string $name = null)
 * @method static \ByteMinus\BdPayGate\Contracts\Gateway for(\ByteMinus\BdPayGate\Models\Payment $payment)
 * @method static \ByteMinus\BdPayGate\Drivers\FakeGateway fake()
 * @method static \ByteMinus\BdPayGate\Models\Payment charge(float|int|string $amount, string $reference, \ByteMinus\BdPayGate\Data\Customer $customer, ?string $description = null, array $meta = [])
 * @method static \ByteMinus\BdPayGate\Data\CallbackResult handleCallback(\Illuminate\Http\Request $request, string $outcome)
 * @method static \ByteMinus\BdPayGate\Models\Payment sync(\ByteMinus\BdPayGate\Models\Payment $payment)
 * @method static \ByteMinus\BdPayGate\Data\RefundResult refund(\ByteMinus\BdPayGate\Models\Payment $payment, float|int|string|null $amount = null, ?string $reason = null)
 * @method static BdPayGateManager extend(string $driver, \Closure $callback)
 *
 * @see BdPayGateManager
 */
class BdPayGate extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BdPayGateManager::class;
    }
}
