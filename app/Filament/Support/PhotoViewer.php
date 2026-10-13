<?php

namespace App\Filament\Support;

use App\Filament\Resources\Tickets\RelationManagers\TicketAttachmentsRelationManager;
use App\Models\OutageAttachment;
use App\Models\OutagePhoto;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;

/**
 * One way to look at a photo, everywhere on the website: it opens large in a pop-up in the middle
 * of the page (resources/views/filament/partials/photo-viewer.blade.php), with what is known about
 * it underneath and a Download button — never in another tab, and the page behind stays where it was.
 *
 * A click hands the pop-up the photo's address and its description as data (never as script).
 */
final class PhotoViewer
{
    /** The click that opens the pop-up: `x-on:click="…"`. Null when there is nothing to show. */
    public static function open(?string $src, ?string $title = null, ?string $caption = null, ?string $download = null): ?string
    {
        if (blank($src)) {
            return null;
        }

        return "\$dispatch('oms-photo', ".Js::from([
            'src' => $src,
            'title' => $title ?: 'Photo',
            'caption' => $caption,
            'download' => $download ?: $src,
        ])->toHtml().')';
    }

    /** A photo or file on a ticket (customer tickets, site visits, 4G tickets). Null for anything that is not a picture. */
    public static function forAttachment(?OutageAttachment $file): ?string
    {
        if (! $file?->isImage()) {
            return null;
        }

        $title = TicketAttachmentsRelationManager::KINDS[$file->kind] ?? $file->name();

        return self::open($file->url(), $title, self::describe($file, $title), $file->url());
    }

    /** A site photo of an outage or of field maintenance. */
    public static function forPhoto(?OutagePhoto $photo): ?string
    {
        return $photo ? self::open($photo->url(), $photo->label(), $photo->caption(), $photo->url().'?download=1') : null;
    }

    /** "View" on a row of photos and files: only where the file is a picture. */
    public static function viewAction(): Action
    {
        return Action::make('viewPhoto')->label('View')->icon(Heroicon::OutlinedEye)->color('gray')
            ->visible(fn (OutageAttachment $record) => $record->isImage())
            ->alpineClickHandler(fn (OutageAttachment $record) => self::forAttachment($record));
    }

    /** "Download" on a row: the file itself, as a download — the page stays. */
    public static function downloadAction(): Action
    {
        return Action::make('download')->label('Download')->icon(Heroicon::OutlinedArrowDownTray)
            ->url(fn (OutageAttachment $record) => $record->url());
    }

    /** A file as a link in a line of text: a picture opens in the pop-up, anything else downloads. */
    public static function link(OutageAttachment $file): HtmlString
    {
        $click = self::forAttachment($file);

        return new HtmlString('<a class="underline" href="'.e($file->url()).'"'
            .($click ? ' x-data="{}" x-on:click.prevent="'.e($click).'"' : '').'>'.e($file->name()).'</a>');
    }

    /** Attributes for an image shown on a page, so a click opens it large. */
    public static function imageAttributes(?OutagePhoto $photo): array
    {
        $click = self::forPhoto($photo);

        // (The values are written into the page as they are: escaped here.)
        return $click ? ['x-data' => '{}', 'x-on:click' => e($click), 'style' => 'cursor: zoom-in', 'title' => 'Click to view'] : [];
    }

    /** What is known about the file, on one line — without repeating the heading of the pop-up. */
    private static function describe(OutageAttachment $file, string $title): string
    {
        return collect([
            $file->name(),
            $file->note,
            $file->coordinates(),
            $file->place,
            $file->taken_at ? 'taken '.$file->taken_at->format('d M Y H:i') : null,
            $file->relationLoaded('user') && $file->user ? 'added by '.$file->user->name : null,
        ])->filter()->unique()->reject(fn (string $part) => $part === $title)->implode(' · ');
    }
}
