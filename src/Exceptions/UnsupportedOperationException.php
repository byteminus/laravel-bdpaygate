<?php

namespace ByteMinus\BdPayGate\Exceptions;

/**
 * The gateway has no API for this (e.g. refunds on a gateway without a refund API).
 */
class UnsupportedOperationException extends GatewayException {}
