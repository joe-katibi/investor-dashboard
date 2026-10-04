<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor e-mails name the site by the tower company's own reference ({{tower_co_id}}), so the
 * vendor's engineer can look the site up without asking. The templates are editable and the
 * seeder never overwrites them, so the templates of a running system are extended here: one line
 * under the site line. A template that no longer has a site line, or already uses the
 * placeholder, is left exactly as the administrator wrote it.
 */
return new class extends Migration
{
    private const LINE = '**Tower CO ID:** {{tower_co_id}}';

    private const INLINE = ' (Tower CO ID {{tower_co_id}})';

    public function up(): void
    {
        if (! Schema::hasTable('vendor_templates')) {
            return;
        }

        foreach (DB::table('vendor_templates')->where('body', 'not like', '%tower_co_id%')->get(['id', 'body']) as $template) {
            // "**Site ID:** {{site_id}}" or "**Site:** {{site_id}} {{site_name}}" on a line of its own.
            $body = preg_replace_callback(
                '/^(\*\*Site(?: ID)?:\*\*[^\r\n]*\{\{\s*site_id\s*\}\}[^\r\n]*)(\r\n|\n|$)/m',
                fn (array $match) => $match[1].($match[2] ?: "\n").self::LINE.$match[2],
                (string) $template->body, 1, $count,
            );

            if ($count === 0) {
                // The site named inside a sentence: "… for site {{site_id}} {{site_name}} has been cancelled."
                $body = preg_replace('/(\{\{\s*site_id\s*\}\}\s+\{\{\s*site_name\s*\}\})/', '$1'.self::INLINE, (string) $template->body, 1, $count);
            }

            if ($count > 0 && is_string($body)) {
                DB::table('vendor_templates')->where('id', $template->id)->update(['body' => $body]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('vendor_templates')) {
            return;
        }

        foreach (DB::table('vendor_templates')->where('body', 'like', '%tower_co_id%')->get(['id', 'body']) as $template) {
            DB::table('vendor_templates')->where('id', $template->id)->update([
                'body' => str_replace(["\r\n".self::LINE, "\n".self::LINE, self::INLINE], '', (string) $template->body),
            ]);
        }
    }
};
