<?php

declare(strict_types=1);

namespace EzPhp\Storage;

use EzPhp\Contracts\EzPhpException;

/**
 * Class StorageException
 *
 * Thrown when a storage operation fails (read, write, delete, upload).
 *
 * @package EzPhp\Storage
 */
final class StorageException extends EzPhpException
{
}
