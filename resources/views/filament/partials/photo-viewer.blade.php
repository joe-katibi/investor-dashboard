{{--
    The photo pop-up, on every signed-in page: any photo on the website opens here, large, in the
    middle of the page — never in another tab. Opened by a click that sends "oms-photo" with the
    photo's address and description (see App\Filament\Support\PhotoViewer). Closed with the button,
    the Esc key or a click beside the photo.
--}}
@auth
    <style>
        .oms-photo-viewer { position: fixed; inset: 0; z-index: 90; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .oms-photo-viewer__shade { position: absolute; inset: 0; background: rgba(0, 0, 0, .78); }
        .oms-photo-viewer__box { position: relative; display: flex; flex-direction: column; max-width: min(1100px, 96vw); max-height: 94vh; background: #fff; color: #111827; border-radius: 12px; overflow: hidden; box-shadow: 0 25px 60px rgba(0, 0, 0, .5); }
        .dark .oms-photo-viewer__box { background: #18181b; color: #f4f4f5; }
        .oms-photo-viewer__head, .oms-photo-viewer__foot { display: flex; align-items: center; gap: 12px; padding: 10px 14px; }
        .oms-photo-viewer__head { justify-content: space-between; border-bottom: 1px solid rgba(128, 128, 128, .25); font-weight: 600; }
        .oms-photo-viewer__foot { justify-content: space-between; flex-wrap: wrap; border-top: 1px solid rgba(128, 128, 128, .25); }
        .oms-photo-viewer__stage { display: flex; align-items: center; justify-content: center; background: #0a0a0a; min-height: 200px; overflow: auto; }
        .oms-photo-viewer__stage img { display: block; max-width: 100%; max-height: 74vh; object-fit: contain; }
        .oms-photo-viewer__caption { flex: 1 1 260px; font-size: 13px; opacity: .8; overflow-wrap: anywhere; }
        .oms-photo-viewer__buttons { display: flex; gap: 8px; margin-left: auto; }
        .oms-photo-viewer__btn { display: inline-flex; align-items: center; gap: 6px; border-radius: 8px; padding: 8px 14px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; border: 1px solid rgba(128, 128, 128, .4); background: transparent; color: inherit; }
        .oms-photo-viewer__btn--primary { background: #d97706; border-color: #d97706; color: #fff; }
        .oms-photo-viewer__close { border: 0; background: transparent; color: inherit; font-size: 22px; line-height: 1; cursor: pointer; padding: 2px 6px; }
        .oms-photo-viewer__missing { padding: 40px 24px; color: #e5e7eb; font-size: 14px; }
    </style>

    <div
        x-data="{ open: false, failed: false, photo: {} }"
        x-on:oms-photo.window="photo = $event.detail || {}; failed = false; open = true; $nextTick(() => $refs.close?.focus())"
        x-on:keydown.escape.window="open = false"
        x-show="open"
        x-cloak
        style="display: none"
        class="oms-photo-viewer"
        role="dialog"
        aria-modal="true"
        x-bind:aria-label="photo.title || 'Photo'"
        data-oms-photo-viewer
    >
        <div class="oms-photo-viewer__shade" x-on:click="open = false"></div>

        <div class="oms-photo-viewer__box">
            <div class="oms-photo-viewer__head">
                <span x-text="photo.title || 'Photo'"></span>
                <button type="button" class="oms-photo-viewer__close" x-ref="close" x-on:click="open = false" aria-label="Close">&times;</button>
            </div>

            <div class="oms-photo-viewer__stage">
                <template x-if="open && ! failed">
                    <img x-bind:src="photo.src" x-bind:alt="photo.title || 'Photo'" x-on:error="failed = true">
                </template>
                <p class="oms-photo-viewer__missing" x-show="failed">This photo could not be shown. It can still be downloaded.</p>
            </div>

            <div class="oms-photo-viewer__foot">
                <span class="oms-photo-viewer__caption" x-text="photo.caption" x-show="photo.caption"></span>
                <span class="oms-photo-viewer__buttons">
                    {{-- A plain link: the file is handed to the browser as a download and the page stays where it is. --}}
                    <a class="oms-photo-viewer__btn oms-photo-viewer__btn--primary" x-bind:href="photo.download || photo.src" download>Download</a>
                    <button type="button" class="oms-photo-viewer__btn" x-on:click="open = false">Close</button>
                </span>
            </div>
        </div>
    </div>
@endauth
