<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An Excel import file that can't be processed at all — not a real .xlsx, not the template, no
 * rows, too many rows. The message is written for shelter staff and shown to them as-is; problems
 * with individual rows are reported per row instead, never through this.
 */
class AnimalImportException extends RuntimeException {}
