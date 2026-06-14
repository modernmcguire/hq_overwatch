<?php

namespace ModernMcguire\Overwatch\Exceptions;

use RuntimeException;

/**
 * Thrown whenever an Overwatch token fails any verification step. The message
 * is intentionally terse and never surfaced to the end user — callers abort
 * with a generic 403 so a probing attacker learns nothing about why.
 */
class InvalidTokenException extends RuntimeException
{
}
