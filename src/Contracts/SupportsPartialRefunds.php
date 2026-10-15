<?php

namespace ByteMinus\BdPayGate\Contracts;

/**
 * The gateway can refund part of a payment, possibly more than once.
 */
interface SupportsPartialRefunds extends SupportsRefunds {}
