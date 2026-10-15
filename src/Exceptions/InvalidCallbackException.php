<?php

namespace ByteMinus\BdPayGate\Exceptions;

/**
 * Thrown when a callback cannot be trusted: bad signature, unknown payment,
 * amount mismatch, or the gateway's validation API disagrees with the redirect.
 */
class InvalidCallbackException extends GatewayException {}
