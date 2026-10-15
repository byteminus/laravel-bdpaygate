<?php

namespace ByteMinus\BdPayGate\Exceptions;

/**
 * The gateway did not answer (timeout, connection refused, DNS). The request
 * may or may not have reached it, so this is never a reason to mark a payment
 * failed: leave it pending and sync later.
 */
class GatewayUnavailableException extends GatewayException {}
