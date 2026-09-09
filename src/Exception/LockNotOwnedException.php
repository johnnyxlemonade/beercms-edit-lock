<?php

declare(strict_types=1);

namespace BeersCms\EditLock\Exception;

/**
 * Odmitnuti operace bez platneho zamku se shodnym uzivatelem a tokenem.
 */
final class LockNotOwnedException extends EditLockException {}
