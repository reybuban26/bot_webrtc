<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * One-time command to encrypt existing plaintext AI chat message content.
 *
 * Usage:
 *   php artisan messages:encrypt
 *
 * Safe to run multiple times — already-encrypted rows are skipped.
 */
class EncryptExistingMessages extends Command
{
    protected $signature   = 'messages:encrypt {--dry-run : Preview how many rows would be encrypted without making changes}';
    protected $description = 'Encrypt existing plaintext content in the chat_messages table (at-rest encryption migration).';

    public function handle(): int
    {
        $isDry = $this->option('dry-run');

        $this->info($isDry ? '[Dry run] Scanning chat_messages...' : 'Encrypting chat_messages...');

        // Read raw values directly to avoid the accessor decrypting them.
        $rows = DB::table('chat_messages')->select('id', 'content')->get();

        $total    = $rows->count();
        $toUpdate = 0;
        $skipped  = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($rows as $row) {
            $bar->advance();

            $value = $row->content ?? '';

            // Skip already-encrypted rows (heuristic: Laravel payload starts with 'eyJ')
            if (str_starts_with($value, 'eyJ')) {
                $skipped++;
                continue;
            }

            $toUpdate++;

            if (!$isDry) {
                DB::table('chat_messages')
                    ->where('id', $row->id)
                    ->update(['content' => Crypt::encryptString($value)]);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Total rows', 'Already encrypted (skipped)', 'Encrypted now'],
            [[$total, $skipped, $isDry ? "{$toUpdate} (dry run)" : $toUpdate]]
        );

        if ($isDry) {
            $this->comment('Run without --dry-run to apply changes.');
        } else {
            $this->info("✅ Done. {$toUpdate} row(s) encrypted.");
        }

        return self::SUCCESS;
    }
}
