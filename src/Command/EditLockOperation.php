<?php

declare(strict_types=1);

namespace BeersCms\EditLock\Command;

/**
 * Operace nad jiz ziskanym editacnim zamkem.
 * Acquire a overeni pred zapisem zustavaji soucasti aplikacni transakce.
 */
enum EditLockOperation: string
{
    case Refresh = 'refresh';
    case Release = 'release';
}
