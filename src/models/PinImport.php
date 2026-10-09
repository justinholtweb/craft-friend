<?php

namespace justinholtweb\friend\models;

use craft\base\Model;

/**
 * What one CSV import did, or — on a dry run — would have done.
 */
class PinImport extends Model
{
    public bool $dryRun = false;

    /** Data rows read, not counting a header or blank lines. */
    public int $rows = 0;

    public int $created = 0;
    public int $updated = 0;

    /** Rows whose URI already had a pin, left alone because updating was not asked for. */
    public int $skipped = 0;

    /** How many of the created/updated pins point at an entry rather than a URL. */
    public int $linked = 0;

    /** @var array<int, string> Line number → why that row was refused. */
    public array $errors = [];

    /** A problem with the file as a whole; nothing was imported. */
    public ?string $fileError = null;

    public function addRowError(int $line, string $message): void
    {
        $this->errors[$line] = $message;
    }

    public function succeeded(): bool
    {
        return $this->fileError === null && $this->errors === [];
    }
}
