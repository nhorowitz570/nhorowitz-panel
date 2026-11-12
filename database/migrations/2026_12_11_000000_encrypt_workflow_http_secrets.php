<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PREFIX = 'encrypted:';

    public function up(): void
    {
        $this->each(fn (string $value) => $value === '' || str_starts_with($value, self::PREFIX) ? $value : self::PREFIX.Crypt::encryptString($value));
    }

    public function down(): void
    {
        $this->each(fn (string $value) => str_starts_with($value, self::PREFIX) ? Crypt::decryptString(substr($value, strlen(self::PREFIX))) : $value);
    }

    /** @param  callable(string): string  $change */
    private function each(callable $change): void
    {
        foreach (['workflows', 'workflow_runs'] as $table) {
            DB::table($table)->select(['id', 'graph'])->orderBy('id')->chunkById(200, function ($rows) use ($table, $change) {
                foreach ($rows as $row) {
                    $graph = json_decode((string) $row->graph, true);
                    if (! is_array($graph)) {
                        continue;
                    }
                    $changed = false;
                    foreach ($graph['nodes'] ?? [] as $i => $node) {
                        if (! is_array($node) || ($node['kind'] ?? null) !== 'http' || ! is_array($node['rules'] ?? null)) {
                            continue;
                        }
                        foreach (['url', 'body'] as $rule) {
                            $value = $node['rules'][$rule] ?? null;
                            if (is_string($value) && ($next = $change($value)) !== $value) {
                                $graph['nodes'][$i]['rules'][$rule] = $next;
                                $changed = true;
                            }
                        }
                    }
                    if ($changed) {
                        DB::table($table)->where('id', $row->id)->update(['graph' => json_encode($graph)]);
                    }
                }
            });
        }
    }
};
