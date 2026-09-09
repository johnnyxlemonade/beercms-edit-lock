<?php

declare(strict_types=1);

namespace BeersCms\EditLock\Exception;

/**
 * Ocekavany konflikt pri acquire, kdy existujici zamek nelze prevzit.
 */
final class LockConflictException extends EditLockException {}
