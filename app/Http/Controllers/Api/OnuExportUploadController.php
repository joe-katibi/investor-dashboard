<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncOnuExportJob;
use App\Models\OnuSyncRun;
use App\Services\Customers\OnuExportSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * POST /api/integrations/onu-export/{source}: the ONU export dumps, sent in by scripts/onu_export_uploader.py from
 * a server that can reach the share. Not a user: a shared secret (Authorization: Bearer …, OMS_ONU_EXPORT_TOKEN),
 * optionally only from the listed addresses. The body is one part of a dump, gzip-compressed:
 *
 *   X-Onu-File    CS-Reports_12_00_00_20261021_120000_p1.csv   (the name as dumped)
 *   X-Onu-Sha256  SHA-256 of the uncompressed file               (checked: a damaged upload is refused)
 *   X-Onu-Parts   how many parts the dump has (default 1)
 *
 * The part is kept in the source's folder; once every part is in, the dump is read (SyncOnuExportJob) — when
 * OMS_ONU_EXPORT_AUTO_APPLY is on; until then it is only kept, for a first run by hand.
 */
class OnuExportUploadController extends Controller
{
    /** Largest dump part accepted, uncompressed (a full FiberHome export is about 20 MB). */
    public const MAX_BYTES = 200 * 1024 * 1024;

    public function __invoke(Request $request, string $source): JsonResponse
    {
        $config = config("oms.onu_exports.{$source}");
        $token = (string) config('oms.onu_export_upload.token');

        // Switched off, or no such source: as if the address did not exist.
        if (! is_array($config) || strlen($token) < 32) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (! hash_equals($token, (string) $request->bearerToken())) {
            Log::warning('ONU export upload refused: wrong token', ['ip' => $request->ip(), 'source' => $source]);

            return response()->json(['message' => 'Unauthorised.'], 401);
        }

        $allowed = (array) config('oms.onu_export_upload.allowed_ips');

        if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            Log::warning('ONU export upload refused: address not allowed', ['ip' => $request->ip(), 'source' => $source]);

            return response()->json(['message' => 'This address may not send ONU exports.'], 403);
        }

        $name = (string) $request->header('X-Onu-File');

        if ($name !== basename($name) || ! preg_match(OnuExportSync::FILE_PATTERN, $name, $match)) {
            return response()->json(['message' => 'X-Onu-File must be the dump name, like CS-Reports_12_00_00_20261021_120000_p1.csv.'], 422);
        }

        $body = $request->getContent();
        $csv = str_starts_with($body, "\x1f\x8b") ? @gzdecode($body, self::MAX_BYTES) : $body;

        if (! is_string($csv) || $csv === '' || strlen($csv) > self::MAX_BYTES) {
            return response()->json(['message' => 'The file is empty, too large or not valid gzip.'], 422);
        }

        if (! hash_equals(strtolower((string) $request->header('X-Onu-Sha256')), hash('sha256', $csv))) {
            return response()->json(['message' => 'The SHA-256 does not match: the file was damaged on the way. Send it again.'], 422);
        }

        // An ONU export, not something else with the right name.
        $header = strtok($csv, "\n");

        if (! is_string($header) || stripos($header, 'ONU Name') === false || stripos($header, 'NE Name') === false) {
            return response()->json(['message' => 'This is not an ONU export (no “NE Name” / “ONU Name” columns).'], 422);
        }

        $dumpedAt = OnuExportSync::stamp($name);

        if (OnuSyncRun::query()->where('source', $source)->where('dumped_at', $dumpedAt)->where('status', OnuSyncRun::DONE)->exists()) {
            return response()->json(['status' => 'already_read', 'dumped_at' => $dumpedAt?->toDateTimeString()]);
        }

        $directory = (string) $config['path'];
        File::ensureDirectoryExists($directory, 0750);
        $path = $directory.DIRECTORY_SEPARATOR.$name;
        $temporary = $path.'.part';
        file_put_contents($temporary, $csv, LOCK_EX);
        rename($temporary, $path);

        // Every part of the dump in: read it.
        $parts = max(1, min(50, (int) ($request->header('X-Onu-Parts') ?: 1)));
        $prefix = substr($name, 0, (int) strrpos($name, '_p') + 2);
        $received = collect(range(1, $parts))->map(fn (int $part) => $directory.DIRECTORY_SEPARATOR.$prefix.$part.'.csv')->filter(fn (string $file) => is_file($file))->values();

        if ($received->count() < $parts) {
            return response()->json(['status' => 'part_received', 'received' => $received->count(), 'parts' => $parts], 202);
        }

        Log::info('ONU export received', ['source' => $source, 'file' => $name, 'bytes' => strlen($csv), 'ip' => $request->ip()]);

        // Until a first dump has been checked and applied by hand, dumps are only kept.
        if (! config('oms.onu_export_upload.auto_apply')) {
            return response()->json(['status' => 'stored', 'dumped_at' => $dumpedAt?->toDateTimeString(), 'bytes' => strlen($csv)], 202);
        }

        SyncOnuExportJob::dispatch($source, $received->all(), $dumpedAt?->toDateTimeString());

        return response()->json(['status' => 'queued', 'dumped_at' => $dumpedAt?->toDateTimeString(), 'bytes' => strlen($csv)], 202);
    }
}
