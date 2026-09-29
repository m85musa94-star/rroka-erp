<?php

namespace App\Services\Daftra;

use Illuminate\Http\Client\ConnectionException;

/**
 * Read-only check of the Daftra read endpoints the financial reports would use.
 *
 * The paths below come from search-result snippets of docs.daftara.dev (the site
 * itself is blocked from the dev environment) and are NOT verified. This probe is
 * the verification: it calls each one with limit=1 against the real account and
 * reports the HTTP status and the response STRUCTURE only — field names and value
 * types, never values — so it can be shared without exposing customer data.
 */
class DaftraProbe
{
    /** key => [label, path] */
    public const ENDPOINTS = [
        'site_info' => ['بيانات الحساب', '/site_info.json'],
        'clients' => ['العملاء', '/clients.json'],
        'invoices' => ['فواتير المبيعات', '/invoices.json'],
        'invoice_payments' => ['مدفوعات الفواتير', '/invoice_payments.json'],
        'client_payments' => ['مدفوعات العملاء', '/client_payments.json'],
        'credit_notes' => ['إشعارات الدائن', '/credit_notes.json'],
        'estimates' => ['عروض الأسعار', '/estimates.json'],
        'purchase_invoices' => ['فواتير المشتريات', '/purchase_invoices.json'],
        'expenses' => ['المصروفات', '/expenses.json'],
    ];

    private const MAX_FIELDS = 300;

    public function __construct(private readonly DaftraClient $client) {}

    /** @return list<array{key:string,label:string,path:string,status:?int,ok:bool,error:?string,fields:list<string>}> */
    public function run(): array
    {
        $results = [];
        foreach (self::ENDPOINTS as $key => [$label, $path]) {
            $row = ['key' => $key, 'label' => $label, 'path' => $path, 'status' => null, 'ok' => false, 'error' => null, 'fields' => []];
            try {
                $response = $this->client->get($path, ['limit' => 1, 'page' => 1]);
                $row['status'] = $response->status();
                $row['ok'] = $response->successful();
                $json = $response->json();
                if (is_array($json)) {
                    $row['fields'] = array_slice(self::shape($json), 0, self::MAX_FIELDS);
                } elseif (! $row['ok']) {
                    $row['error'] = 'non-JSON response';
                }
            } catch (ConnectionException $e) {
                $row['error'] = 'connection failed';
            }
            $results[] = $row;
        }

        return $results;
    }

    /**
     * Flatten a JSON document to "path: type" lines. Lists show their first element
     * as [] and numeric object keys become #, so ids and values never leak.
     *
     * @return list<string>
     */
    public static function shape(mixed $value, string $prefix = '', int $depth = 0): array
    {
        if (! is_array($value)) {
            return [($prefix === '' ? '(root)' : $prefix).': '.get_debug_type($value)];
        }
        if ($depth >= 6) {
            return [$prefix.': …'];
        }
        if ($value === []) {
            return [($prefix === '' ? '(root)' : $prefix).': empty'];
        }
        if (array_is_list($value)) {
            return self::shape($value[0], $prefix.'[]', $depth + 1);
        }

        $lines = [];
        $seenNumeric = false;
        foreach ($value as $k => $v) {
            if (is_int($k) || ctype_digit((string) $k)) {
                if ($seenNumeric) {
                    continue;
                }
                $seenNumeric = true;
                $k = '#';
            }
            $lines = [...$lines, ...self::shape($v, $prefix === '' ? (string) $k : "$prefix.$k", $depth + 1)];
        }

        return $lines;
    }

    /** Plain-text report to copy and send for review. */
    public static function asText(array $results, ?string $subdomain): string
    {
        $out = ['Daftra probe — '.now()->toDateTimeString().' — subdomain: '.($subdomain ?: '-')];
        foreach ($results as $r) {
            $out[] = '';
            $out[] = "## {$r['key']} GET {$r['path']} → ".($r['status'] ?? 'no response').($r['error'] ? " ({$r['error']})" : '');
            foreach ($r['fields'] as $f) {
                $out[] = '  '.$f;
            }
        }

        return implode("\n", $out);
    }
}
