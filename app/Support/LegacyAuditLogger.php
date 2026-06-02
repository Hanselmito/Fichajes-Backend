<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use JsonException;

class LegacyAuditLogger
{
    private ?bool $auditTableExists = null;

    public function logInsert(string $table, int $recordId, array $newValues, ?int $changedBy = null): void
    {
        $this->insertAuditRow($table, $recordId, 'INSERT', null, $newValues, $changedBy);
    }

    public function logUpdate(string $table, int $recordId, array $oldValues, array $newValues, ?int $changedBy = null): void
    {
        $this->insertAuditRow($table, $recordId, 'UPDATE', $oldValues, $newValues, $changedBy);
    }

    public function logDelete(string $table, int $recordId, array $oldValues, ?int $changedBy = null): void
    {
        $this->insertAuditRow($table, $recordId, 'DELETE', $oldValues, null, $changedBy);
    }

    private function insertAuditRow(
        string $table,
        int $recordId,
        string $action,
        ?array $oldValues,
        ?array $newValues,
        ?int $changedBy,
    ): void {
        if (! $this->hasAuditTable()) {
            return;
        }

        DB::table('audit_log')->insert([
            'id' => ((int) DB::table('audit_log')->max('id')) + 1,
            'table_name' => $table,
            'record_id' => $recordId,
            'action' => $action,
            'old_values' => $this->encodePayload($oldValues),
            'new_values' => $this->encodePayload($newValues),
            'changed_by' => $changedBy,
            'changed_at' => now(),
        ]);
    }

    private function hasAuditTable(): bool
    {
        if ($this->auditTableExists !== null) {
            return $this->auditTableExists;
        }

        return $this->auditTableExists = DB::getSchemaBuilder()->hasTable('audit_log');
    }

    private function encodePayload(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        try {
            return json_encode($this->normalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return null;
        }
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalize($item);
            }

            return $normalized;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
            return $value;
        }

        return (string) $value;
    }
}